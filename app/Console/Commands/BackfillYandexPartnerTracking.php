<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\YandexApiLog;
use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая замена ID заявки Яндекс.Доставки (…-udp) на номер партнёра по доставке (LO-…)
 * в уже созданных заказах. Номер берётся из courier_order_id: сначала из сохранённых ответов
 * Яндекса (yandex_api_logs), если их нет (логи чистятся через 30 дней) — запросом request/info.
 *
 * ПО УМОЛЧАНИЮ НИЧЕГО НЕ ПИШЕТ (пробный прогон). Запись — только с --write.
 * Вписанный вручную трек не перезаписывается (правило YandexDeliveryService::applyOrderTracking).
 * Смена трека заказа сама ставит выгрузку в МойСклад (OrderObserver), поэтому запись идёт
 * партиями с паузой.
 */
class BackfillYandexPartnerTracking extends Command
{
    protected $signature = 'yandex:backfill-partner-tracking
        {--write : Записать изменения (без флага — только пробный прогон)}
        {--order= : Только этот номер заказа}
        {--limit= : Обработать не больше N заявок}
        {--chunk=20 : Сколько заказов писать подряд до паузы}
        {--sleep=3 : Пауза между партиями, секунд}
        {--no-fetch : Не запрашивать Яндекс, брать номера только из сохранённых ответов}';

    protected $description = 'Replace Yandex claim ids in order tracking with the partner id (LO-<courier_order_id>)';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $service = new YandexDeliveryService;

        $query = YandexOrder::query()->whereNotNull('claim_id')->where('claim_id', '!=', '')->orderBy('id');
        if ($this->option('order')) {
            $query->whereHas('order', fn ($q) => $q->where('order_number', (string) $this->option('order')));
        }
        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $rows = [];
        $stats = ['total' => 0, 'will_change_order' => 0, 'will_set_yandex' => 0, 'manual_kept' => 0, 'already_ok' => 0, 'no_partner_id' => 0, 'written' => 0, 'errors' => 0];
        $written = 0;

        $this->info($write ? 'РЕЖИМ ЗАПИСИ' : 'ПРОБНЫЙ ПРОГОН: ничего не записывается');

        foreach ($query->cursor() as $yandexOrder) {
            $order = Order::find($yandexOrder->order_id);
            if (! $order) {
                continue;
            }
            $stats['total']++;

            [$courierId, $source] = $this->courierOrderId($service, $yandexOrder);
            $partner = YandexDeliveryService::partnerTrackingNumber($courierId);
            if ($partner === null) {
                $stats['no_partner_id']++;
                $rows[] = [$order->order_number, $order->status->value ?? (string) $order->status, $order->tracking_number, '— нет номера партнёра —', $source];
                continue;
            }

            $current = $order->tracking_number;
            $systemSet = blank($current) || $current === $yandexOrder->claim_id || $current === $yandexOrder->tracking_number;
            $orderChange = $current !== $partner && $systemSet;
            $yandexChange = $yandexOrder->tracking_number !== $partner;

            if ($orderChange) {
                $stats['will_change_order']++;
            } elseif ($current !== $partner) {
                $stats['manual_kept']++;
            } else {
                $stats['already_ok']++;
            }
            if ($yandexChange) {
                $stats['will_set_yandex']++;
            }

            $rows[] = [
                $order->order_number,
                $order->status->value ?? (string) $order->status,
                $current,
                $orderChange ? $partner : ($current === $partner ? '(уже так)' : '(вписано вручную, не трогаем)'),
                $source,
            ];

            if (! $write || (! $orderChange && ! $yandexChange)) {
                continue;
            }

            try {
                DB::transaction(function () use ($service, $order, $yandexOrder, $partner) {
                    $yandexOrder->update(['tracking_number' => $partner]);
                    $delivery = is_array($order->delivery_data) ? $order->delivery_data : [];
                    if (($delivery['yandex_tracking_number'] ?? null) !== $partner) {
                        $order->update(['delivery_data' => array_merge($delivery, ['yandex_tracking_number' => $partner])]);
                    }
                    $service->applyOrderTracking($order->fresh(), $partner, $yandexOrder->claim_id, $yandexOrder);
                });
                $stats['written']++;
                $written++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->error("Заказ {$order->order_number}: ".$e->getMessage());
            }

            if ($written > 0 && $written % max(1, (int) $this->option('chunk')) === 0) {
                sleep(max(0, (int) $this->option('sleep')));
            }
        }

        $this->table(['Заказ', 'Статус', 'Трек сейчас', 'Станет', 'Откуда номер'], array_slice($rows, 0, 40));
        if (count($rows) > 40) {
            $this->line('… показаны первые 40 из '.count($rows));
        }
        $this->newLine();
        $this->info('Итого заявок: '.$stats['total']);
        $this->line("  трек заказа изменится: {$stats['will_change_order']}");
        $this->line("  «Трек-номер Яндекс.Доставки» будет заполнен/обновлён: {$stats['will_set_yandex']}");
        $this->line("  вписано вручную, не трогаем: {$stats['manual_kept']}");
        $this->line("  уже в нужном виде: {$stats['already_ok']}");
        $this->line("  номер партнёра не найден: {$stats['no_partner_id']}");
        if ($write) {
            $this->line("  записано: {$stats['written']}, ошибок: {$stats['errors']}");
        }

        return self::SUCCESS;
    }

    /** @return array{0: ?string, 1: string} [courier_order_id, источник] */
    private function courierOrderId(YandexDeliveryService $service, YandexOrder $yandexOrder): array
    {
        $log = YandexApiLog::query()
            ->where('claim_id', $yandexOrder->claim_id)
            ->where('method', '/api/b2b/platform/request/info')
            ->where('status_code', 200)
            ->orderByDesc('id')
            ->first(['response_body']);
        $id = data_get($log?->response_body, 'courier_order_id');
        if (filled($id)) {
            return [(string) $id, 'сохранённый ответ'];
        }

        if ($this->option('no-fetch')) {
            return [null, 'нет в сохранённых'];
        }

        usleep(400000);
        try {
            $result = $service->getRequestInfo($yandexOrder->claim_id, $yandexOrder->order_id);
            $id = $result['successful'] ? data_get($result['data'], 'courier_order_id') : null;
        } catch (\Throwable) {
            $id = null;
        }

        return filled($id) ? [(string) $id, 'запрос к Яндексу'] : [null, 'Яндекс не вернул'];
    }
}
