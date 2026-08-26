<?php

namespace App\Services\Notifications;

use App\Models\CdekOrder;
use App\Support\PublicUrl;

class CdekDeliveryMessageBuilder
{
    private const TITLES = [
        'handed_over' => 'Заказ передан в доставку',
        'courier_in_transit' => 'Заказ в пути к вам',
        'ready_for_pickup' => 'Заказ можно получить',
        'delivered' => 'Заказ доставлен',
        'delivery_problem' => 'Возникла проблема с доставкой',
        'returning' => 'Заказ возвращается отправителю',
    ];

    /** @return array{message:string,html:string,subject:string} */
    public function build(CdekOrder $cdekOrder, string $statusCode): array
    {
        $cdekOrder->loadMissing('order');
        $order = $cdekOrder->order;
        $orderNumber = $order->order_number ?? $order->id;
        $title = self::TITLES[$statusCode] ?? 'Статус доставки изменился';
        $lines = ["{$title} - заказ №{$orderNumber}"];

        if ($statusCode !== 'delivered' && $cdekOrder->cdek_number) {
            $lines[] = 'Номер отправления: '.$cdekOrder->cdek_number;
        }
        if ($cdekOrder->tracking_url) {
            $lines[] = 'Отследить доставку: '.$cdekOrder->tracking_url;
        }
        if ($statusCode === 'ready_for_pickup' && data_get($order->delivery_data, 'pvz.address')) {
            $lines[] = 'Пункт выдачи: '.data_get($order->delivery_data, 'pvz.address');
        }
        if (in_array($statusCode, ['delivery_problem', 'returning'], true)) {
            $lines[] = 'Если потребуется помощь, ответьте на это сообщение.';
        }
        if ($statusCode === 'delivered') {
            $lines[] = 'Спасибо за покупку в '.PublicUrl::shopHost().'.';
        }

        $message = implode("\n\n", $lines);
        $html = implode('', array_map(fn (string $line) => $cdekOrder->tracking_url && $line === 'Отследить доставку: '.$cdekOrder->tracking_url
            ? '<p><a href="'.e($cdekOrder->tracking_url).'" style="display:inline-block;padding:12px 18px;background:#292725;color:#fff;text-decoration:none">Отследить доставку</a></p>'
            : '<p>'.e($line).'</p>', $lines));

        return ['message' => $message, 'html' => '<div style="font-family:Arial,sans-serif;color:#292725;line-height:1.5">'.$html.'</div>', 'subject' => $title.' - заказ №'.$orderNumber];
    }
}
