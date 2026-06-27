<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductRestockSubscription;
use App\Services\Notifications\Jobs\SendNotificationJob;
use App\Traits\PhoneFormatterTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class NotifyRestockSubscribersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PhoneFormatterTrait;

    public $tries = 3;
    public $backoff = [60, 300, 900];

    public function __construct(
        protected int $productId
    ) {
    }

    /**
     * Не запускать параллельно для одного товара (гонка при массовом синке).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->productId))->expireAfter(180)];
    }

    public function handle(): void
    {
        $product = Product::with('main_image')->find($this->productId);

        if (!$product) {
            Log::warning('NotifyRestockSubscribersJob: product not found', [
                'product_id' => $this->productId,
            ]);
            return;
        }

        // Шлём только если товар действительно доступен к покупке.
        if (!$product->is_active || (float)$product->stock_quantity <= 0) {
            return;
        }

        $frontendUrl = rtrim(env('FRONTEND_URL', 'https://againdev2.ru'), '/');
        $productUrl = $frontendUrl . '/catalog/' . $product->slug;

        // Идемпотентность (#6): обрабатываем только pending; после рассылки → notified.
        ProductRestockSubscription::query()
            ->forProduct($product->id)
            ->pending()
            ->with('client.profile')
            ->chunkById(100, function ($subscriptions) use ($product, $productUrl) {
                foreach ($subscriptions as $subscription) {
                    $this->notifySubscription($subscription, $product, $productUrl);
                }
            });
    }

    protected function notifySubscription(
        ProductRestockSubscription $subscription,
        Product $product,
        string $productUrl
    ): void {
        $textMessage = sprintf(
            '«%s» уже в наличии! Успейте заказать: %s',
            $product->name,
            $productUrl
        );

        // Email — всегда (email обязателен).
        if ($subscription->email) {
            $html = View::make('emails.product-restock', [
                'product' => $product,
                'productUrl' => $productUrl,
            ])->render();

            SendNotificationJob::dispatch(
                channel: 'email',
                recipientId: $subscription->email,
                message: $html,
                data: ['subject' => 'Уже в наличии — Again'],
            );
        }

        // WhatsApp — если есть телефон.
        if ($subscription->phone) {
            $phone = $this->formatPhoneForWhatsApp($subscription->phone);
            if ($phone) {
                SendNotificationJob::dispatch('whatsapp', $phone, $textMessage);
            }
        }

        // Telegram / VK — только привязанному клиенту.
        $profile = $subscription->client?->profile;
        if ($profile?->telegram_user_id) {
            SendNotificationJob::dispatch('telegram', (string)$profile->telegram_user_id, $textMessage);
        }
        if ($profile?->vk_user_id) {
            SendNotificationJob::dispatch('vk', (string)$profile->vk_user_id, $textMessage);
        }

        // Терминальный статус — повторно не уведомляем.
        $subscription->update([
            'status' => ProductRestockSubscription::STATUS_NOTIFIED,
            'notified_at' => Carbon::now(),
        ]);
    }
}
