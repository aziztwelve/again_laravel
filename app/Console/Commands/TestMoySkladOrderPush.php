<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\MoySklad\OrderService;
use Illuminate\Console\Command;

class TestMoySkladOrderPush extends Command
{
    /**
     * Артисан-команда для тестирования выгрузки заказа в МойСклад.
     *
     * Примеры запуска:
     *   php artisan ms:test-order-push            — берёт последний заказ
     *   php artisan ms:test-order-push --id=123   — берёт заказ с id=123
     *   php artisan ms:test-order-push --dry       — только выводит payload, не отправляет
     */
    protected $signature = 'ms:test-order-push
                            {--id= : ID заказа из БД}
                            {--dry : Только показать данные, не отправлять в МС}';

    protected $description = 'Тест выгрузки заказа в МойСклад (для разработки)';

    public function handle(): int
    {
        // Определяем заказ
        $orderId = $this->option('id');

        if ($orderId) {
            $order = Order::with(['items.variant', 'items.product', 'address', 'client.profile', 'deliveryMethod'])
                ->find($orderId);

            if (! $order) {
                $this->error("Заказ с id={$orderId} не найден.");
                return self::FAILURE;
            }
        } else {
            $order = Order::with(['items.variant', 'items.product', 'address', 'client.profile', 'deliveryMethod'])
                ->latest()
                ->first();

            if (! $order) {
                $this->error('В базе нет ни одного заказа.');
                return self::FAILURE;
            }
        }

        // Информация о заказе
        $this->info("=== Заказ #{$order->order_number} (id={$order->id}) ===");
        $this->line("  Статус:   {$order->status?->value}");
        $this->line("  Создан:   {$order->created_at}");
        $this->line("  Сумма:    {$order->total_amount}");
        $this->line("  Позиций:  {$order->items->count()}");

        $client = $order->client;
        $profile = $client?->profile;
        $addr = $order->address;

        $this->line("  Клиент:   " . ($profile?->full_name ?? $client?->email ?? $order->email ?? '—'));
        $this->line("  Телефон:  " . ($addr?->recipient_phone ?? $profile?->phone ?? '—'));
        $this->line("  Email:    " . ($client?->email ?? $order->email ?? '—'));
        $this->line("  Адрес:    " . ($addr?->city . ', ' . $addr?->address ?? '—'));

        $this->newLine();
        $this->line('--- Позиции ---');

        $hasAllUuids = true;
        foreach ($order->items as $item) {
            $variantUuid = $item->variant?->uuid ?? null;
            $status = $variantUuid ? '<fg=green>uuid: ' . $variantUuid . '</>' : '<fg=red>нет uuid в МС</>';
            $this->line("  [{$item->id}] {$item->variant?->product?->name} / {$item->variant?->name} — {$item->quantity} шт × {$item->price} руб → {$status}");

            if (! $variantUuid) {
                $hasAllUuids = false;
            }
        }

        if (! $hasAllUuids) {
            $this->newLine();
            $this->warn('Некоторые варианты не синхронизированы с МойСклад (нет uuid). Они будут пропущены.');
        }

        // Режим dry-run
        if ($this->option('dry')) {
            $this->newLine();
            $this->info('--dry указан: отправка в МойСклад не производится.');
            return self::SUCCESS;
        }

        // Подтверждение
        if (! $this->confirm("Отправить заказ #{$order->order_number} в МойСклад?", true)) {
            $this->line('Отменено.');
            return self::SUCCESS;
        }

        // Отправка
        $this->newLine();
        $this->line('Отправляю...');

        try {
            $service = new OrderService();
            $msOrderId = $service->pushOrder($order);

            $this->newLine();
            $this->info("Заказ успешно выгружен в МойСклад!");
            $this->line("  UUID в МС: <fg=cyan>{$msOrderId}</>");
            $this->line("  Проверить: https://online.moysklad.ru/app/#customerorder/edit?id={$msOrderId}");

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('Ошибка выгрузки: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
