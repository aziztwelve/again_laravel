<?php

namespace App\Console\Commands\Import;

use App\Models\DeliveryMethod;
use App\Models\Order;
use App\Services\Import\OrderImportService;
use Illuminate\Console\Command;

/**
 * Дополняет импортированные из InSales заказы недостающими данными доставки.
 *
 * Команда намеренно не перезаписывает заполненные поля: она предназначена для
 * исправления раннего импорта, который не брал треки из отдельных колонок
 * СДЭК/Почты России.
 */
class SyncLegacyOrderDeliveryCommand extends Command
{
    protected $signature = 'sync:legacy-order-delivery
        {file : Путь к CSV-выгрузке InSales}
        {--dry-run : Только собрать статистику, ничего не записывая}
        {--limit=0 : Обработать не более указанного числа заказов}';

    protected $description = 'Заполнить пустые службу доставки и трек-номер у импортированных заказов из CSV InSales';

    /** @var array<string, int> */
    private array $deliveryMethods = [];

    /** @var array<string, string> */
    private array $deliveryAliases = [
        'пункты выдачи заказов сдэк' => 'пункт выдачи сдэк',
    ];

    public function handle(OrderImportService $importService): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Файл не найден или недоступен: {$file}");

            return self::FAILURE;
        }

        @set_time_limit(0);
        $this->loadDeliveryMethods();

        $sourceOrders = $this->readSourceOrders($importService, (int) $this->option('limit'));
        $stats = [
            'source_orders' => count($sourceOrders),
            'orders_found' => 0,
            'orders_missing' => 0,
            'delivery_already_present' => 0,
            'delivery_filled' => 0,
            'delivery_unresolved' => 0,
            'legacy_delivery_name_filled' => 0,
            'tracking_already_present' => 0,
            'tracking_filled' => 0,
            'changed_orders' => 0,
        ];
        $unresolvedMethods = [];

        foreach (array_chunk(array_keys($sourceOrders), 500) as $orderNumbers) {
            $orders = Order::query()
                ->whereIn('order_number', $orderNumbers)
                ->get(['id', 'order_number', 'delivery_method_id', 'tracking_number', 'legacy_delivery_method']);

            $foundNumbers = $orders->pluck('order_number')->all();
            $stats['orders_missing'] += count(array_diff($orderNumbers, $foundNumbers));

            foreach ($orders as $order) {
                $stats['orders_found']++;
                $source = $sourceOrders[$order->order_number];
                $changes = [];

                if ($source['delivery_method'] !== null) {
                    if ($order->delivery_method_id === null) {
                        $methodId = $this->resolveDeliveryMethodId($source['delivery_method']);
                        if ($methodId !== null) {
                            $changes['delivery_method_id'] = $methodId;
                            $stats['delivery_filled']++;
                        } else {
                            $stats['delivery_unresolved']++;
                            $unresolvedMethods[$source['delivery_method']] = ($unresolvedMethods[$source['delivery_method']] ?? 0) + 1;
                        }
                    } else {
                        $stats['delivery_already_present']++;
                    }

                    if ($this->isEmpty($order->legacy_delivery_method)) {
                        $changes['legacy_delivery_method'] = mb_substr($source['delivery_method'], 0, 512);
                        $stats['legacy_delivery_name_filled']++;
                    }
                }

                if ($source['tracking_number'] !== null) {
                    if ($this->isEmpty($order->tracking_number)) {
                        $changes['tracking_number'] = $source['tracking_number'];
                        $stats['tracking_filled']++;
                    } else {
                        $stats['tracking_already_present']++;
                    }
                }

                if ($changes !== []) {
                    $stats['changed_orders']++;
                    if (! $this->option('dry-run')) {
                        $order->forceFill($changes)->save();
                    }
                }
            }
        }

        $this->table(
            ['Метрика', 'Значение'],
            collect($stats)->map(fn (int $value, string $name) => [$name, $value])->values()->all(),
        );

        if ($unresolvedMethods !== []) {
            $this->warn('Не удалось сопоставить службы доставки:');
            foreach ($unresolvedMethods as $method => $count) {
                $this->line(" - {$method}: {$count}");
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('DRY RUN: данные не изменялись.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, array{delivery_method: ?string, tracking_number: ?string}> */
    private function readSourceOrders(OrderImportService $importService, int $limit): array
    {
        $orders = [];

        foreach ($importService->parseOrders((string) $this->argument('file')) as $bundle) {
            $header = $bundle['header'];
            $orderNumber = $this->value($header['order_number'] ?? null);
            if ($orderNumber === null) {
                continue;
            }
            if ($limit > 0 && count($orders) >= $limit) {
                break;
            }

            $orders[$orderNumber] = [
                'delivery_method' => $this->value($header['delivery_method'] ?? null),
                // В InSales общий трек часто пуст, а номер СДЭК/Почты России
                // находится в отдельной колонке. Берём общий номер, если он есть.
                'tracking_number' => $this->value($header['tracking_number'] ?? null)
                    ?? $this->value($header['cdek_track_number'] ?? null)
                    ?? $this->value($header['russian_post_track'] ?? null),
            ];
        }

        return $orders;
    }

    private function loadDeliveryMethods(): void
    {
        DeliveryMethod::query()
            ->select(['id', 'name'])
            ->get()
            ->each(function (DeliveryMethod $method): void {
                $key = $this->normalizeDeliveryName($method->name);
                if ($key !== '') {
                    $this->deliveryMethods[$key] = $method->id;
                }
            });
    }

    private function resolveDeliveryMethodId(string $sourceName): ?int
    {
        $name = $this->normalizeDeliveryName($sourceName);
        $name = $this->deliveryAliases[$name] ?? $name;

        return $this->deliveryMethods[$name] ?? null;
    }

    private function normalizeDeliveryName(string $name): string
    {
        $name = preg_replace('/\s*\(.*/u', '', $name) ?? $name;

        return mb_strtolower(trim($name));
    }

    private function value(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '""' ? null : $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $this->value($value) === null;
    }
}
