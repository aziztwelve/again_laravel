<?php

namespace App\Services\Notifications;

use App\Models\CdekOrder;

class CdekDeliveryMessageBuilder
{
    private const TITLES = [
        'ACCEPTED' => 'Заказ передан в доставку',
        'IN_TRANSIT' => 'Заказ в пути',
        'READY_FOR_PICKUP' => 'Заказ можно получить',
        'DELIVERED' => 'Заказ доставлен',
        'NOT_DELIVERED' => 'Не удалось доставить заказ',
        'RETURNED_TO_SENDER' => 'Заказ возвращается отправителю',
    ];

    /** @return array{message:string,html:string,subject:string} */
    public function build(CdekOrder $cdekOrder, string $statusCode): array
    {
        $cdekOrder->loadMissing('order');
        $order = $cdekOrder->order;
        $orderNumber = $order->order_number ?? $order->id;
        $title = self::TITLES[$statusCode] ?? 'Статус доставки изменился';
        $lines = ["{$title} - заказ №{$orderNumber}"];

        if ($statusCode !== 'DELIVERED' && $cdekOrder->cdek_number) {
            $lines[] = 'Номер отправления: '.$cdekOrder->cdek_number;
        }
        if ($cdekOrder->tracking_url) {
            $lines[] = 'Отследить доставку: '.$cdekOrder->tracking_url;
        }
        if (in_array($statusCode, ['NOT_DELIVERED', 'RETURNED_TO_SENDER'], true)) {
            $lines[] = 'Если потребуется помощь, ответьте на это сообщение.';
        }
        if ($statusCode === 'DELIVERED') {
            $lines[] = 'Спасибо за покупку в again8.ru.';
        }

        $message = implode("\n\n", $lines);
        $html = implode('', array_map(fn (string $line) => $cdekOrder->tracking_url && $line === 'Отследить доставку: '.$cdekOrder->tracking_url
            ? '<p><a href="'.e($cdekOrder->tracking_url).'" style="display:inline-block;padding:12px 18px;background:#292725;color:#fff;text-decoration:none">Отследить доставку</a></p>'
            : '<p>'.e($line).'</p>', $lines));

        return ['message' => $message, 'html' => '<div style="font-family:Arial,sans-serif;color:#292725;line-height:1.5">'.$html.'</div>', 'subject' => $title.' - заказ №'.$orderNumber];
    }
}
