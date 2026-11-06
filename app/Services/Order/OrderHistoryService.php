<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Auth;

/**
 * Сервис записи событий истории заказа.
 *
 * Все методы пишут в order_histories запись с:
 *   - action      — тип события (created/updated/deleted/item_added/item_removed)
 *   - description — человекочитаемый текст в стиле InSales
 *   - user_id     — текущий аутентифицированный пользователь (если есть)
 *   - status      — текущий status заказа (для обратной совместимости)
 */
class OrderHistoryService
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_ITEM_ADDED = 'item_added';
    public const ACTION_ITEM_REMOVED = 'item_removed';

    /**
     * Поля заказа, изменения которых отражаем в истории.
     * Ключ — поле в БД, значение — человекочитаемое название.
     */
    private const TRACKED_FIELDS = [
        'status' => 'Статус заказа',
        'payment_status' => 'Статус оплаты',
        'payment_method' => 'Способ оплаты',
        'total_amount' => 'Сумма заказа',
        'delivery_method_id' => 'Способ доставки',
        'delivery_cost' => 'Стоимость доставки',
        'notes' => 'Комментарий',
        'seller_comment' => 'Комментарий продавца',
        'assigned_user_id' => 'Менеджер',
    ];

    public const ACTION_COPIED = 'copied';

    public function logCreated(Order $order): void
    {
        $this->write($order, self::ACTION_CREATED, 'Заказ создан');
    }

    public function logCopiedFrom(Order $newOrder, Order $sourceOrder): void
    {
        $this->write($newOrder, self::ACTION_COPIED, "Заказ скопирован из заказа №{$sourceOrder->order_number}");
    }

    public function logCopiedTo(Order $sourceOrder, Order $newOrder): void
    {
        $this->write($sourceOrder, self::ACTION_COPIED, "Заказ скопирован в новый заказ №{$newOrder->order_number}");
    }

    public function logDeleted(Order $order, ?string $reason = null): void
    {
        $description = 'Заказ удалён';
        if ($reason) {
            $description .= " (причина: {$reason})";
        }
        $this->write($order, self::ACTION_DELETED, $description);
    }

    /**
     * Сравнивает старые и новые значения, пишет по одной записи на каждое
     * изменившееся поле в стиле «X изменён с 'A' на 'B'».
     */
    public function logUpdated(Order $order, array $original, array $updated): void
    {
        foreach (self::TRACKED_FIELDS as $field => $label) {
            if (! array_key_exists($field, $updated)) {
                continue;
            }

            $oldRaw = $original[$field] ?? null;
            $newRaw = $updated[$field] ?? null;

            if ($this->valuesEqual($oldRaw, $newRaw)) {
                continue;
            }

            $oldLabel = $this->formatValue($field, $oldRaw);
            $newLabel = $this->formatValue($field, $newRaw);

            $description = "{$label} изменён с '{$oldLabel}' на '{$newLabel}'";

            $this->write($order, self::ACTION_UPDATED, $description);
        }
    }

    /**
     * Смена статуса заказа не через форму редактирования: отмена (в том числе автоотмена
     * через 2 часа), статусы служб доставки, кнопки смены статуса. Пишет одну запись
     * «Статус заказа изменён с 'A' на 'B'» (+ причину, если есть).
     */
    public function logStatusChange(Order $order, ?OrderStatus $from, OrderStatus $to, ?string $reason = null): void
    {
        if ($from === $to) {
            return;
        }

        $description = "Статус заказа изменён с '".($from?->label() ?? '—')."' на '{$to->label()}'";
        if ($reason) {
            $description .= " ({$reason})";
        }

        $this->write($order, self::ACTION_UPDATED, $description);
    }

    public function logItemAdded(Order $order, OrderItem $item): void
    {
        $name = $item->product?->name ?? "позиция #{$item->id}";
        $qty = (int) ($item->quantity ?? 0);
        $this->write($order, self::ACTION_ITEM_ADDED, "Добавлена позиция: {$name} (x{$qty})");
    }

    public function logItemRemoved(Order $order, OrderItem $item): void
    {
        $name = $item->product?->name ?? "позиция #{$item->id}";
        $this->write($order, self::ACTION_ITEM_REMOVED, "Удалена позиция: {$name}");
    }

    /**
     * Снимок «подробностей» заказа для истории: адрес, получатель, пункт выдачи,
     * позиции и промокод. Берётся до и после обновления заказа.
     */
    public function detailsSnapshot(Order $order): array
    {
        $order->load(['address', 'items.product', 'items.variant']);

        $a = is_array($order->delivery_address) ? $order->delivery_address : [];
        $date = $a['delivery_date'] ?? null;
        try {
            $date = $date ? \Carbon\Carbon::parse($date)->format('d.m.Y') : null;
        } catch (\Throwable) {
            // оставляем как есть
        }

        $recipient = trim(implode(' ', array_filter([
            $a['recipient_last_name'] ?? null,
            $a['recipient_first_name'] ?? null,
            $a['recipient_middle_name'] ?? null,
        ])));
        if (! empty($a['recipient_phone'])) {
            $recipient = trim($recipient.' '.$a['recipient_phone']);
        }

        $pvz = (array) data_get($order->delivery_data, 'pvz', []);
        $pvzLabel = trim(($pvz['code'] ?? $pvz['id'] ?? '').' '.($pvz['address'] ?? ''));

        $items = [];
        foreach ($order->items as $item) {
            $name = $item->product?->name ?? $item->legacy_name ?? "позиция #{$item->id}";
            $variant = $item->variant?->name;
            if ($variant) {
                $name .= " ({$variant})";
            }
            $key = ($item->product_id ?? 'x').'-'.($item->product_variant_id ?? '0').'-'.($item->is_gift ? 'g' : 'n');
            if (isset($items[$key])) {
                $items[$key]['qty'] += (int) $item->quantity;
                continue;
            }
            $items[$key] = ['name' => $name, 'qty' => (int) $item->quantity, 'price' => (float) $item->price];
        }

        $promo = $order->promo_code_id
            ? \App\Models\PromoCode::withTrashed()->whereKey($order->promo_code_id)->value('code')
            : null;

        return [
            'address' => [
                'Страна' => $a['country'] ?? null,
                'Регион' => $a['region'] ?? null,
                'Город' => $a['city'] ?? null,
                'Адрес' => $a['address'] ?? null,
                'Индекс' => $a['postal_code'] ?? null,
                'Дата доставки' => $date,
            ],
            'recipient' => $recipient ?: null,
            'pvz' => $pvzLabel ?: null,
            'items' => $items,
            'promo' => $promo,
        ];
    }

    /**
     * Пишет в историю то, что не входит в TRACKED_FIELDS: адрес и дату доставки,
     * получателя, пункт выдачи, правки позиций и смену промокода.
     */
    public function logDetails(Order $order, array $before, array $after): void
    {
        $dash = fn ($v) => ($v === null || $v === '') ? '—' : (string) $v;

        $changes = [];
        foreach (($after['address'] ?? []) as $label => $new) {
            $old = $before['address'][$label] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[] = "{$label}: '{$dash($old)}' → '{$dash($new)}'";
            }
        }
        if ($changes) {
            $this->write($order, self::ACTION_UPDATED, 'Адрес доставки изменён: '.implode('; ', $changes));
        }

        if (($before['recipient'] ?? null) !== ($after['recipient'] ?? null)) {
            $this->write($order, self::ACTION_UPDATED, "Получатель изменён с '{$dash($before['recipient'] ?? null)}' на '{$dash($after['recipient'] ?? null)}'");
        }

        if (($before['pvz'] ?? null) !== ($after['pvz'] ?? null)) {
            $this->write($order, self::ACTION_UPDATED, "Пункт выдачи изменён с '{$dash($before['pvz'] ?? null)}' на '{$dash($after['pvz'] ?? null)}'");
        }

        $beforeItems = $before['items'] ?? [];
        $afterItems = $after['items'] ?? [];
        foreach ($afterItems as $key => $item) {
            if (! isset($beforeItems[$key])) {
                $this->write($order, self::ACTION_ITEM_ADDED, "Добавлена позиция: {$item['name']} (x{$item['qty']})");
                continue;
            }
            $old = $beforeItems[$key];
            if ($old['qty'] !== $item['qty']) {
                $this->write($order, self::ACTION_UPDATED, "Позиция «{$item['name']}»: количество {$old['qty']} → {$item['qty']}");
            }
            if (abs($old['price'] - $item['price']) > 0.001) {
                $this->write($order, self::ACTION_UPDATED, "Позиция «{$item['name']}»: цена ".number_format($old['price'], 2, '.', ' ').' → '.number_format($item['price'], 2, '.', ' '));
            }
        }
        foreach ($beforeItems as $key => $item) {
            if (! isset($afterItems[$key])) {
                $this->write($order, self::ACTION_ITEM_REMOVED, "Удалена позиция: {$item['name']}");
            }
        }

        if (($before['promo'] ?? null) !== ($after['promo'] ?? null)) {
            $this->write($order, self::ACTION_UPDATED, "Промокод изменён с '{$dash($before['promo'] ?? null)}' на '{$dash($after['promo'] ?? null)}'");
        }
    }

    private function write(Order $order, string $action, string $description): void
    {
        $userId = Auth::id();

        $order->history()->create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'status' => $this->statusValue($order->status) ?? '',
            'payment_status' => $this->statusValue($order->payment_status),
            'comment' => $description,
        ]);
    }

    private function valuesEqual($a, $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a == $b;
    }

    private function formatValue(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($field === 'status') {
            $enum = $value instanceof OrderStatus ? $value : OrderStatus::tryFrom((string) $value);
            return $enum?->label() ?? (string) $value;
        }

        if ($field === 'payment_status') {
            $enum = $value instanceof PaymentStatus ? $value : PaymentStatus::tryFrom((string) $value);
            return $enum?->label() ?? (string) $value;
        }

        if ($field === 'payment_method') {
            // Должно соответствовать списку в админке (OrderStatuses.vue) и
            // OrderController::sendReceipt. Легаси-коды тоже маппим.
            $labels = [
                'card_ru' => 'Оплата картами РФ, TPay, СБП',
                'cloudpayments_tpay' => 'T-Pay',
                'cloudpayments_sbp' => 'СБП',
                'cloudpayments_sberpay' => 'SberPay',
                'cloudpayments_mirpay' => 'Mir Pay',
                'sberpay' => 'SberPay, рассрочка, иностранная карта',
                'yandex_pay' => 'Яндекс Пэй и Сплит',
                'yandex_pay_split' => 'Яндекс Сплит',
                'cash_on_delivery' => 'Наличными или картой при получении',
                'pickup_payment' => 'Оплата в точке самовывоза',
                'podeli' => 'Подели',
                'robokassa_mokka' => 'Robokassa X Мокка',
                'robokassa_yandex_split' => 'Robokassa X Яндекс Сплит',
                // Легаси
                'card' => 'Оплата картой РФ',
                'yookassa' => 'Оплата картой РФ',
                'online' => 'Оплата картой РФ',
                'split' => 'Яндекс Пэй и Сплит',
                'cash' => 'Наличными или картой при получении',
                'cod' => 'Наличными или картой при получении',
                'sbp' => 'SberPay, рассрочка, иностранная карта',
                'bank_transfer' => 'Оплата картой РФ',
            ];
            $code = (string) $value;
            return $labels[$code] ?? $code;
        }

        if ($field === 'delivery_method_id') {
            $method = \App\Models\DeliveryMethod::find($value);
            return $method?->name ?? (string) $value;
        }

        if ($field === 'assigned_user_id') {
            $user = \App\Models\User::with('profile')->find($value);
            if (! $user) {
                return (string) $value;
            }
            return $user->get_full_name() ?: ($user->email ?? (string) $value);
        }

        if (in_array($field, ['total_amount', 'delivery_cost'], true)) {
            return number_format((float) $value, 2, '.', ' ');
        }

        return (string) $value;
    }

    private function statusValue($status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return $status === null ? null : (string) $status;
    }
}
