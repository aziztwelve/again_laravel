<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\YandexApiLog;
use App\Models\YandexOrder;
use App\Services\Delivery\YandexDeliveryService;
use App\Services\MoySklad\OrderService as MoySkladOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая замена трека заказов Яндекс.Доставки на номер грузоместа (YD…): и ID заявки (…-udp),
 * и номер партнёра (LO-…), который ставили с 13.11.2026. Номер берётся из request.places[0].barcode:
 * сначала из сохранённых ответов Яндекса (yandex_api_logs), если их нет (логи чистятся через 30 дней) —
 * запросом request/info. Берётся одно, первое место: у заказа один трек.
 *
 * ПО УМОЛЧАНИЮ НИЧЕГО НЕ ПИШЕТ (пробный прогон). Запись — только с --write.
 * Вписанный вручную трек не перезаписывается (правило YandexDeliveryService::isSystemSetTrack).
 * В МойСклад уходит ТОЛЬКО поле «Трек номер» (OrderService::pushTrackingNumber): полная выгрузка
 * заказа привела бы статус МойСклад к статусу сайта, поэтому события заказа при записи отключены.
 * Запись идёт партиями с паузой.
 */
class BackfillYandexPackageTracking extends Command
{
    protected $signature = 'yandex:backfill-package-tracking
        {--write : Записать изменения (без флага — только пробный прогон)}
        {--order= : Только этот номер заказа}
        {--limit= : Обработать не больше N заявок}
        {--chunk=20 : Сколько заказов писать подряд до паузы}
        {--sleep=3 : Пауза между партиями, секунд}
        {--no-fetch : Не запрашивать Яндекс, брать номера только из сохранённых ответов}';

    protected $description = 'Replace Yandex order tracking (claim id or LO-… partner id) with the Yandex package number (YD…)';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $service = new YandexDeliveryService;

        // Пока номер грузоместа включён только для новых заказов, замена старых заставила бы опрос статусов
        // возвращать им LO-… и спорить с этой командой. Сначала нужно снять ограничение по дате.
        if ($write && filled(config('services.yandex_delivery.package_tracking_from'))) {
            $this->error('Замена старых заказов выключена: services.yandex_delivery.package_tracking_from = '
                .config('services.yandex_delivery.package_tracking_from').'. Сначала снимите ограничение (пустое значение).');

            return self::FAILURE;
        }

        $query = YandexOrder::query()->whereNotNull('claim_id')->where('claim_id', '!=', '')->orderBy('id');
        if ($this->option('order')) {
            $query->whereHas('order', fn ($q) => $q->where('order_number', (string) $this->option('order')));
        }
        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $rows = [];
        $stats = ['total' => 0, 'will_change_order' => 0, 'will_set_yandex' => 0, 'manual_kept' => 0, 'already_ok' => 0, 'no_package' => 0, 'written' => 0, 'moysklad' => 0, 'errors' => 0];
        $moysklad = null;
        $written = 0;

        $this->info($write ? 'РЕЖИМ ЗАПИСИ' : 'ПРОБНЫЙ ПРОГОН: ничего не записывается');

        foreach ($query->cursor() as $yandexOrder) {
            $order = Order::find($yandexOrder->order_id);
            if (! $order) {
                continue;
            }
            $stats['total']++;

            [$package, $source] = $this->packageNumber($service, $yandexOrder);
            if ($package === null) {
                $stats['no_package']++;
                $rows[] = [$order->order_number, $order->status->value ?? (string) $order->status, $order->tracking_number, '— нет номера грузоместа —', $source];
                continue;
            }

            $current = $order->tracking_number;
            $orderChange = $current !== $package && YandexDeliveryService::isSystemSetTrack($current, $yandexOrder->claim_id, $yandexOrder);
            $yandexChange = $yandexOrder->tracking_number !== $package;

            if ($orderChange) {
                $stats['will_change_order']++;
            } elseif ($current !== $package) {
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
                $orderChange ? $package : ($current === $package ? '(уже так)' : '(вписано вручную, не трогаем)'),
                $source,
            ];

            if (! $write || (! $orderChange && ! $yandexChange)) {
                continue;
            }

            try {
                // Без событий: смена трека иначе поставит ПОЛНУЮ выгрузку заказа в МойСклад,
                // а она приводит статус МойСклад к статусу сайта. В МойСклад пишем только поле «Трек номер».
                Order::withoutEvents(function () use ($order, $yandexOrder, $package, $orderChange) {
                    DB::transaction(function () use ($order, $yandexOrder, $package, $orderChange) {
                        $yandexOrder->update(['tracking_number' => $package]);
                        $delivery = is_array($order->delivery_data) ? $order->delivery_data : [];
                        $updates = [];
                        if (($delivery['yandex_tracking_number'] ?? null) !== $package) {
                            $updates['delivery_data'] = array_merge($delivery, ['yandex_tracking_number' => $package]);
                        }
                        if ($orderChange) {
                            $updates['tracking_number'] = $package;
                        }
                        if ($updates !== []) {
                            $order->update($updates);
                        }
                    });
                });
                if ($orderChange && filled($order->moysklad_order_uuid)) {
                    $moysklad ??= new MoySkladOrderService;
                    $moysklad->pushTrackingNumber($order->fresh(), $package);
                    $stats['moysklad']++;
                }
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
        $this->line("  номер грузоместа не найден: {$stats['no_package']}");
        if ($write) {
            $this->line("  записано в базу сайта: {$stats['written']}, из них поле «Трек номер» обновлено в МойСклад: {$stats['moysklad']}, ошибок: {$stats['errors']}");
        }

        return self::SUCCESS;
    }

    /** @return array{0: ?string, 1: string} [номер грузоместа, источник] */
    private function packageNumber(YandexDeliveryService $service, YandexOrder $yandexOrder): array
    {
        // Последний ответ может быть пустым (сбой опроса): берём ближайший, где номер есть.
        $logs = YandexApiLog::query()
            ->where('claim_id', $yandexOrder->claim_id)
            ->where('method', '/api/b2b/platform/request/info')
            ->where('status_code', 200)
            ->where('response_body', 'like', '%"barcode"%')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['response_body']);
        foreach ($logs as $log) {
            $number = YandexDeliveryService::packageTrackingNumber($log->response_body);
            if ($number !== null) {
                return [$number, 'сохранённый ответ'];
            }
        }

        if ($this->option('no-fetch')) {
            return [null, 'нет в сохранённых'];
        }

        usleep(400000);
        try {
            $result = $service->getRequestInfo($yandexOrder->claim_id, $yandexOrder->order_id);
            $number = $result['successful'] ? YandexDeliveryService::packageTrackingNumber($result['data']) : null;
        } catch (\Throwable) {
            $number = null;
        }

        return $number !== null ? [$number, 'запрос к Яндексу'] : [null, 'Яндекс не вернул'];
    }
}
