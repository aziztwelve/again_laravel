<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductRestockSubscription;
use App\Services\Notifications\CustomerChannelResolver;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

class NotifyRestockSubscribersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        protected int $productId
    ) {}

    /**
     * Не запускать параллельно для одного товара (гонка при массовом синке).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->productId))->expireAfter(180)];
    }

    public function handle(CustomerChannelResolver $customerChannelResolver): void
    {
        $product = Product::with('main_image')->find($this->productId);

        if (! $product) {
            Log::warning('NotifyRestockSubscribersJob: product not found', [
                'product_id' => $this->productId,
            ]);

            return;
        }

        // Шлём только если товар действительно доступен к покупке.
        if (! $product->is_active || (float) $product->stock_quantity <= 0) {
            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $productUrl = $frontendUrl.'/catalog/'.$product->slug;
        $imageAttachment = $this->productImageAttachment($product);

        // Идемпотентность (#6): обрабатываем только pending; после рассылки → notified.
        ProductRestockSubscription::query()
            ->forProduct($product->id)
            ->pending()
            ->with('client.profile')
            ->chunkById(100, function ($subscriptions) use ($product, $productUrl, $imageAttachment, $customerChannelResolver) {
                $availableColorIds = $product->variants()
                    ->where('stock_quantity', '>', 0)
                    ->whereNotNull('color_id')
                    ->pluck('color_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                foreach ($subscriptions as $subscription) {
                    $selectedColorIds = collect($subscription->color_ids ?? [])->map(fn ($id) => (int) $id)->all();
                    if ($selectedColorIds && ! array_intersect($selectedColorIds, $availableColorIds)) {
                        continue;
                    }

                    $this->notifySubscription($subscription, $product, $productUrl, $imageAttachment, $customerChannelResolver);
                }
            });
    }

    /**
     * Возвращает одновременно локальный путь и публичный URL: разные
     * мессенджеры используют разные способы загрузки изображения.
     */
    protected function productImageAttachment(Product $product): ?array
    {
        $image = $product->main_image;

        if (! $image) {
            return null;
        }

        if (! empty($image->url)) {
            return [
                'type' => 'image',
                'url' => $image->url,
            ];
        }

        if (empty($image->path)) {
            return null;
        }

        $storedPath = ltrim($image->path, '/');
        $fileName = basename($storedPath);
        $candidates = [
            $storedPath,
            'products/'.$storedPath,
            'products/original_'.$fileName,
            'products/lg_'.$fileName,
            'products/md_'.$fileName,
            'products/sm_'.$fileName,
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (Storage::disk('public')->exists($candidate)) {
                return [
                    'type' => 'image',
                    'file_path' => $candidate,
                    'url' => Storage::disk('public')->url($candidate),
                ];
            }
        }

        return null;
    }

    protected function notifySubscription(
        ProductRestockSubscription $subscription,
        Product $product,
        string $productUrl,
        ?array $imageAttachment,
        CustomerChannelResolver $customerChannelResolver
    ): void {
        $textMessage = sprintf(
            '«%s» уже в наличии! Успейте заказать: %s',
            $product->name,
            $productUrl
        );

        $html = View::make('emails.product-restock', [
            'product' => $product,
            'productUrl' => $productUrl,
        ])->render();

        foreach ($customerChannelResolver->resolve($subscription->client, $subscription->email) as $recipient) {
            SendNotificationJob::dispatch(
                $recipient['channel'],
                $recipient['recipient_id'],
                $recipient['channel'] === 'email' ? $html : $textMessage,
                [
                    'type' => 'product_restock',
                    'product_id' => $product->id,
                    'product_url' => $productUrl,
                    'image_url' => $imageAttachment['url'] ?? null,
                    'attachments' => $imageAttachment ? [$imageAttachment] : [],
                    'subject' => 'Уже в наличии — Again',
                    'html' => $recipient['channel'] === 'email' ? $html : null,
                    'mirror_conversation' => [
                        'source' => $recipient['source'],
                        'external_id' => $recipient['recipient_id'],
                        'client_id' => $subscription->client_id,
                    ],
                ]
            );
        }

        // Терминальный статус — повторно не уведомляем.
        $subscription->update([
            'status' => ProductRestockSubscription::STATUS_NOTIFIED,
            'notified_at' => Carbon::now(),
        ]);

        $subscription->histories()->create([
            'action' => 'notified',
            'description' => 'Клиенту отправлено уведомление о поступлении товара',
        ]);
    }
}
