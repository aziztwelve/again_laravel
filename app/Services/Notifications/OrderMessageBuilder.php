<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Support\PublicUrl;
use Illuminate\Support\Number;

class OrderMessageBuilder
{
    public function buildOrderCreated(Order $order): string
    {
        $order->loadMissing([
            'address',
            'client.profile',
            'deliveryMethod',
            'deliveryTarget',
            'items.product',
            'payment',
        ]);

        $lines = [
            'Новый заказ №' . ($order->order_number ?? $order->id),
            'Магазин '.PublicUrl::shopHost(),
            'Клиент: ' . $this->customerLine($order),
            'Способ доставки: ' . ($this->deliveryMethodLine($order) ?: 'Не указан'),
            'Адрес доставки: ' . ($this->deliveryAddressLine($order) ?: 'Не указан'),
            '',
            'Состав заказа:',
        ];

        foreach ($order->items as $item) {
            $name = $item->legacy_name ?: $item->product?->name ?: 'Товар';
            $price = (float) ($item->price ?? 0);
            $quantity = (int) ($item->quantity ?? 1);
            $lines[] = sprintf('- %s. %s x %d шт', $name, $this->money($price), $quantity);
        }

        $lines[] = '';
        $lines[] = 'Способ оплаты: ' . ($this->paymentMethodLabel($order->payment_method) ?: 'Не указан');
        $lines[] = 'Сумма: ' . $this->money((float) $order->total_amount);
        $lines[] = '';
        $lines[] = 'Данный номер для связи с нашими покупателями, пожалуйста, не блокируйте его. Если сообщение пришло к вам случайно, приносим извинения 🫶🏻';

        return implode("\n", $lines);
    }

    /**
     * Письмо «Оплата получена»: сумма, куда и как доставим, что будет дальше и ссылка на заказ.
     *
     * @return array{message:string, html:string, subject:string}
     */
    public function buildOrderPaid(Order $order): array
    {
        $order->loadMissing(['address', 'client.profile', 'deliveryMethod']);
        $orderNumber = $order->order_number ?? $order->id;

        $blocks = [[
            OrderMessageText::greeting($order),
            "Мы получили оплату заказа №{$orderNumber} на сумму ".OrderMessageText::money((float) $order->total_amount).'. Спасибо!',
        ]];

        $method = $this->deliveryMethodLine($order);
        if ($method) {
            $pickup = OrderMessageText::isPickup($order);
            $address = OrderMessageText::address($order, $pickup);
            $blocks[] = array_values(array_filter([
                'Доставка: '.$method,
                $address ? ($pickup ? 'Пункт выдачи: ' : 'Адрес доставки: ').$address : null,
            ]));
        }

        $blocks[] = ['Что дальше: передадим заказ в доставку и пришлём номер для отслеживания.'];

        if (filled($order->view_token)) {
            $blocks[] = [['label' => 'Открыть заказ', 'url' => PublicUrl::to('/orders/'.$order->view_token)]];
        }

        return [
            'message' => OrderMessageText::plain($blocks),
            'html' => OrderMessageText::html($blocks),
            'subject' => "Оплата получена — заказ №{$orderNumber}",
        ];
    }

    protected function customerLine(Order $order): string
    {
        $profile = $order->client?->profile;
        $address = $order->address;

        // В заказе могут быть данные получателя, отличающиеся от старого
        // профиля клиента (например, заказ оформляет другой человек). В
        // уведомлении показываем именно актуального получателя заказа.
        $recipientName = trim(implode(' ', array_filter([
            $address?->recipient_first_name,
            $address?->recipient_last_name,
        ])));

        $name = $recipientName
            ?: $profile?->full_name
            ?: 'Клиент';

        $phone = $address?->recipient_phone ?: $profile?->phone;

        return $phone ? "{$name} ({$phone})" : $name;
    }

    protected function deliveryMethodLine(Order $order): ?string
    {
        $deliveryMethodName = $order->deliveryMethod?->name ?? $order->legacy_delivery_method ?? null;
        $deliveryTargetName = $order->deliveryTarget?->name ?? null;

        if ($deliveryMethodName && $deliveryTargetName && $deliveryMethodName !== $deliveryTargetName) {
            return "{$deliveryMethodName} ({$deliveryTargetName})";
        }

        return $deliveryMethodName ?: $deliveryTargetName;
    }

    protected function deliveryAddressLine(Order $order): ?string
    {
        $address = $order->address;
        if (!$address) {
            return null;
        }

        return trim(implode(', ', array_filter([
            $address->country,
            $address->city,
            $address->region,
            $address->address,
        ])));
    }

    protected function paymentMethodLabel(?string $paymentMethod): ?string
    {
        if (!$paymentMethod) {
            return null;
        }

        return [
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
            'card' => 'Оплата картой РФ',
            'yookassa' => 'Оплата картой РФ',
            'online' => 'Оплата картой РФ',
            'split' => 'Яндекс Пэй и Сплит',
            'cash' => 'Наличными или картой при получении',
            'cod' => 'Наличными или картой при получении',
            'sbp' => 'SberPay, рассрочка, иностранная карта',
            'bank_transfer' => 'Оплата картой РФ',
        ][$paymentMethod] ?? $paymentMethod;
    }

    protected function money(float $amount): string
    {
        return Number::currency($amount, 'RUB', locale: 'ru');
    }
}
