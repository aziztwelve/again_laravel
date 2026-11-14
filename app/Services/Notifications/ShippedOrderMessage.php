<?php

namespace App\Services\Notifications;

use App\Models\Order;

/**
 * Письмо «Заказ отправлен»: одинаковый текст для СДЭК и Яндекс.Доставки, с номером и
 * ссылкой для отслеживания. Уходит, когда служба доставки приняла посылку.
 */
class ShippedOrderMessage
{
    /**
     * @param  array{carrier:string, number:?string, url:?string, pickup:bool, pickup_noun?:string, address:?string, expected_label?:string, expected:?string}  $shipment
     * @return array{message:string, html:string, subject:string}
     */
    public function build(Order $order, array $shipment): array
    {
        $orderNumber = $order->order_number ?? $order->id;
        $number = filled($shipment['number'] ?? null) ? (string) $shipment['number'] : null;
        $url = filled($shipment['url'] ?? null) ? (string) $shipment['url'] : null;
        $pickup = (bool) ($shipment['pickup'] ?? true);

        $blocks = [
            [OrderMessageText::greeting($order), "Ваш заказ №{$orderNumber} отправлен."],
            array_values(array_filter([
                'Служба доставки: '.$shipment['carrier'],
                $number ? 'Номер для отслеживания: '.$number : null,
                $url ? ['label' => 'Отследить доставку', 'url' => $url] : null,
            ])),
        ];

        $where = array_values(array_filter([
            filled($shipment['address'] ?? null) ? ($pickup ? 'Куда приедет: ' : 'Адрес доставки: ').$shipment['address'] : null,
            filled($shipment['expected'] ?? null) ? ($shipment['expected_label'] ?? 'Ориентировочный срок').': '.$shipment['expected'] : null,
        ]));
        if ($where !== []) {
            $blocks[] = $where;
        }

        $blocks[] = [$pickup
            ? 'Когда заказ придёт в '.($shipment['pickup_noun'] ?? 'пункт выдачи').', пришлём ещё одно сообщение: после него его можно забирать.'
            : 'Курьер свяжется с вами перед доставкой.'];

        return [
            'message' => OrderMessageText::plain($blocks),
            'html' => OrderMessageText::html($blocks),
            'subject' => "Заказ №{$orderNumber} отправлен".($number ? " — номер для отслеживания {$number}" : ''),
        ];
    }
}
