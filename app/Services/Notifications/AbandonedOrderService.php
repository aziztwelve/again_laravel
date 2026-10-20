<?php

namespace App\Services\Notifications;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Support\PublicUrl;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;

class AbandonedOrderService
{
    public function __construct(
        private CustomerChannelResolver $resolver,
        private TransactionalNotificationDispatcher $dispatcher,
    ) {}

    public function dueOrders(): Builder
    {
        return Order::query()
            ->whereIn('status', [OrderStatus::NEW->value, OrderStatus::PROCESSING->value])
            ->where('payment_status', PaymentStatus::PENDING->value)
            ->whereNull('paid_at')
            ->whereNull('payment_interface_started_at')
            ->where('reservation_expires_at', '>', now())
            ->where('order_page_opened_at', '<=', now()->subMinutes(config('abandoned_order.after_minutes')))
            ->whereNotNull('view_token')
            ->where('total_amount', '>', 0);
    }

    public function isEligible(?Order $order): bool
    {
        return config('abandoned_order.enabled') && $order && $this->dueOrders()->whereKey($order->id)->exists();
    }

    public function remind(Order $order): void
    {
        if (! $this->isEligible($order)) {
            return;
        }

        $order->loadMissing('client.profile', 'address');
        $name = trim((string) ($order->client?->profile?->first_name ?: $order->address?->recipient_first_name));
        $subject = $name ? "$name, вы кое-что забыли!" : 'Вы кое-что забыли!';
        $greeting = $name ? "Привет, $name!" : 'Привет!';
        $url = PublicUrl::to('/orders/'.$order->view_token);
        $reservationPeriod = CarbonInterval::hours((int) config('abandoned_order.reservation_hours', 2))->locale('ru')->forHumans();
        $paragraphs = [
            $greeting,
            'Похоже, вы собрали отличную корзину в магазине Again, но так и не забрали её.',
            "Мы забронировали ваши товары, чтобы их случайно не купил кто-то другой, но бронь действует только {$reservationPeriod}.",
            'Не дайте вашим находкам ускользнуть!',
        ];
        $message = implode("\n\n", $paragraphs)."\n\n👉 Завершить покупку в 1 клик: ".$url;
        $html = view('emails.abandoned-order', compact('subject', 'paragraphs', 'url', 'reservationPeriod'))->render();

        foreach ($this->resolver->resolve($order->client, $order->email) as $recipient) {
            if (! in_array($recipient['channel'], ['email', 'telegram', 'max'], true)) {
                continue;
            }
            $this->dispatcher->dispatch('abandoned_order', 'order', $order->id, $recipient, $recipient['channel'] === 'telegram' ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : $message, [
                'type' => 'abandoned_order',
                'order_id' => $order->id,
                'subject' => $subject,
                'action_url' => $url,
                'action_label' => 'Завершить покупку в 1 клик',
                'attachments' => $recipient['channel'] === 'max'
                    ? [['type' => 'link_button', 'url' => $url, 'text' => 'Завершить покупку в 1 клик']]
                    : [],
                'html' => $html,
                'mirror_conversation' => [
                    'source' => $recipient['source'],
                    'external_id' => $recipient['recipient_id'],
                    'client_id' => $order->client_id,
                ],
            ]);
        }
    }
}
