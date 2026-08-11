<?php

namespace App\Services\Notifications;

use App\Models\YandexOrder;

class YandexDeliveryMessageBuilder
{
    private const TITLES = [
        'delivery_created' => 'Доставка оформлена',
        'handed_over' => 'Заказ передан в доставку',
        'in_transit' => 'Заказ в пути',
        'ready_for_pickup' => 'Заказ можно получить',
        'courier_today' => 'Курьер доставит заказ сегодня',
        'delivered' => 'Заказ доставлен',
        'delivery_problem' => 'Возникла проблема с доставкой',
        'cancelled' => 'Доставка отменена',
        'returning' => 'Заказ возвращается отправителю',
    ];

    /** @return array{message:string,html:string,subject:string} */
    public function build(YandexOrder $yandexOrder, string $customerStatus): array
    {
        $yandexOrder->loadMissing('order');
        $order = $yandexOrder->order;
        $delivery = $order->delivery_data ?? [];
        $orderNumber = $order->order_number ?? $order->id;
        $title = self::TITLES[$customerStatus] ?? 'Статус доставки изменился';

        $lines = ["{$title} — заказ №{$orderNumber}"];
        $description = $this->description($customerStatus, $yandexOrder->delivery_type);
        if ($description) {
            $lines[] = $description;
        }

        if ($customerStatus !== 'delivered') {
            $trackingNumber = $yandexOrder->tracking_number ?: $yandexOrder->claim_id;
            if ($trackingNumber) {
                $lines[] = 'Номер отправления: '.$trackingNumber;
            }

            if ($customerStatus === 'delivery_created') {
                $lines[] = 'Способ доставки: '.($yandexOrder->delivery_type === 'pickup' ? 'пункт выдачи' : 'курьер');
            }

            if ($yandexOrder->delivery_type === 'pickup' && in_array($customerStatus, ['delivery_created', 'ready_for_pickup'], true)) {
                $pvzAddress = data_get($delivery, 'pvz.address');
                if ($pvzAddress) {
                    $lines[] = 'Пункт выдачи: '.$pvzAddress;
                }
            }

            $interval = $this->deliveryInterval($delivery);
            if ($interval && in_array($customerStatus, ['delivery_created', 'handed_over', 'courier_today'], true)) {
                $lines[] = 'Ожидаемая доставка: '.$interval;
            }
        }

        if ($yandexOrder->tracking_url) {
            $lines[] = 'Отследить доставку: '.$yandexOrder->tracking_url;
        }

        if ($customerStatus === 'delivered') {
            $lines[] = 'Спасибо за покупку в again8.ru.';
        } elseif (in_array($customerStatus, ['delivery_problem', 'cancelled', 'returning'], true)) {
            $lines[] = 'Если потребуется помощь, ответьте на это сообщение.';
        }

        $message = implode("\n\n", $lines);

        return [
            'message' => $message,
            'html' => $this->html($lines, $yandexOrder->tracking_url),
            'subject' => $title.' — заказ №'.$orderNumber,
        ];
    }

    private function description(string $status, string $deliveryType): ?string
    {
        return match ($status) {
            'handed_over' => 'Мы передали ваш заказ Яндекс.Доставке.',
            'in_transit' => $deliveryType === 'pickup'
                ? 'Заказ направляется в выбранный пункт выдачи.'
                : 'Курьер забрал заказ и везёт его к вам.',
            'ready_for_pickup' => 'Заказ прибыл в выбранный пункт выдачи.',
            'courier_today' => 'Курьер находится на финальном этапе доставки.',
            'delivery_problem' => 'Яндекс.Доставка сообщила, что доставить заказ сейчас не удалось.',
            'cancelled' => 'Доставка заказа была отменена.',
            'returning' => 'Служба доставки возвращает заказ отправителю.',
            default => null,
        };
    }

    private function deliveryInterval(array $delivery): ?string
    {
        $from = data_get($delivery, 'delivery_interval.from');
        $to = data_get($delivery, 'delivery_interval.to');
        if ($from && $to) {
            return $from.' — '.$to;
        }

        return $from ?: $to ?: ($delivery['delivery_date'] ?? null);
    }

    private function html(array $lines, ?string $trackingUrl): string
    {
        $paragraphs = array_map(function (string $line) use ($trackingUrl): string {
            if ($trackingUrl && $line === 'Отследить доставку: '.$trackingUrl) {
                return '<p><a href="'.e($trackingUrl).'" style="display:inline-block;padding:12px 18px;background:#292725;color:#fff;text-decoration:none">Отследить доставку</a></p>';
            }

            return '<p>'.e($line).'</p>';
        }, $lines);

        return '<div style="font-family:Arial,sans-serif;color:#292725;line-height:1.5">'.implode('', $paragraphs).'</div>';
    }
}
