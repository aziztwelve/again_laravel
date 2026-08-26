<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Server-side integration with the Yandex Pay Merchant API. */
class YandexPayService
{
    private const PROVIDER = 'yandexpay';

    public function isAvailable(): bool
    {
        return (bool) config('payment.providers.yandexpay.enabled')
            && filled(config('payment.providers.yandexpay.merchant_id'))
            && filled(config('payment.providers.yandexpay.api_key'));
    }

    public function intent(Order $order): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Яндекс Пэй временно недоступен.');
        }
        if (! $order->canBePaid()) {
            throw new RuntimeException('Для этого заказа недоступна онлайн-оплата.');
        }

        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Для заказа нет суммы к оплате.');
        }

        $externalOrderId = 'again-order-'.$order->id;
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->where('provider', self::PROVIDER)
            ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
            ->latest('id')
            ->first();

        if (! $payment) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => self::PROVIDER,
                'amount' => $amount,
                'currency' => 'RUB',
                'status' => Payment::STATUS_PENDING,
                'provider_payment_id' => $externalOrderId,
                'provider_data' => ['external_order_id' => $externalOrderId],
            ]);
        } elseif ($payment->isFailed()) {
            $payment->update(['status' => Payment::STATUS_PENDING, 'error_message' => null]);
        }

        $response = $this->request('post', '/api/merchant/v1/orders', $this->payload($order, $externalOrderId));
        $paymentUrl = data_get($response, 'data.paymentUrl');
        if (! is_string($paymentUrl) || $paymentUrl === '') {
            throw new RuntimeException('Яндекс Пэй не вернул ссылку на оплату.');
        }

        $payment->update([
            'provider_data' => array_merge($payment->provider_data ?? [], ['create_order' => $response]),
        ]);

        return ['payment_url' => $paymentUrl];
    }

    /** Verifies the ES256 JWT before exposing its payload. */
    public function verifiedWebhookPayload(string $jwt): array
    {
        if ($jwt === '') {
            throw new RuntimeException('Пустое уведомление Яндекс Пэй.');
        }
        $jwks = Cache::remember('yandex_pay.jwks.'.config('payment.providers.yandexpay.env'), now()->addMinutes(10), function (): array {
            $response = Http::timeout(15)->get($this->jwkUrl());
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('Не удалось получить публичные ключи Яндекс Пэй.');
            }
            return $response->json();
        });

        try {
            $payload = (array) JWT::decode($jwt, JWK::parseKeySet($jwks));
        } catch (\Throwable $exception) {
            throw new RuntimeException('Недействительная подпись уведомления Яндекс Пэй.', previous: $exception);
        }

        if (($payload['merchantId'] ?? null) !== config('payment.providers.yandexpay.merchant_id')) {
            throw new RuntimeException('Уведомление предназначено для другого магазина.');
        }

        return $payload;
    }

    public function processWebhook(array $payload): void
    {
        if (($payload['event'] ?? null) !== 'ORDER_STATUS_UPDATED') {
            return;
        }
        $externalOrderId = (string) data_get($payload, 'order.orderId');
        $status = (string) data_get($payload, 'order.paymentStatus');
        $payment = Payment::query()->with('order')
            ->where('provider', self::PROVIDER)
            ->where('provider_payment_id', $externalOrderId)
            ->first();
        if (! $payment || ! $payment->order) {
            throw new RuntimeException('Платёж Яндекс Пэй не найден.');
        }

        // The webhook does not contain a trustworthy amount. Re-read the merchant
        // order and check it against our immutable local payment before changing it.
        $remote = $this->request('get', '/api/merchant/v1/orders/'.rawurlencode($externalOrderId));
        $remoteOrder = data_get($remote, 'data.order', []);
        if (! is_array($remoteOrder)
            || (string) ($remoteOrder['merchantId'] ?? '') !== (string) config('payment.providers.yandexpay.merchant_id')
            || round((float) ($remoteOrder['orderAmount'] ?? -1), 2) !== round((float) $payment->amount, 2)) {
            throw new RuntimeException('Данные заказа Яндекс Пэй не совпали с локальным платежом.');
        }

        $providerData = array_merge($payment->provider_data ?? [], ['webhook' => $payload, 'order_status' => $remote]);
        if ($status === 'CAPTURED' && ! $payment->isCompleted()) {
            $payment->update(['status' => Payment::STATUS_COMPLETED, 'provider_data' => $providerData, 'error_message' => null]);
            if (! $payment->order->isPaid()) {
                $payment->order->updatePaymentStatus(PaymentStatus::PAID, $externalOrderId);
            }
        } elseif (in_array($status, ['FAILED', 'VOIDED'], true) && ! $payment->isCompleted()) {
            $payment->update(['status' => Payment::STATUS_FAILED, 'provider_data' => $providerData, 'error_message' => (string) ($remoteOrder['reason'] ?? 'Платёж не завершён')]);
            if (! $payment->order->isPaid()) {
                $payment->order->updatePaymentStatus(PaymentStatus::FAILED);
            }
        }
    }

    private function payload(Order $order, string $externalOrderId): array
    {
        $items = $order->items()->with(['product', 'variant'])->get()->map(function ($item): array {
            $quantity = (int) $item->quantity;
            $unitPrice = number_format((float) $item->price, 2, '.', '');
            $total = number_format((float) $item->price * $quantity, 2, '.', '');
            return [
                'productId' => 'order-item-'.$item->id,
                'title' => $item->product?->name ?? $item->legacy_name ?? 'Товар',
                'quantity' => ['count' => (string) $quantity],
                'unitPrice' => $unitPrice,
                'discountedUnitPrice' => $unitPrice,
                'subtotal' => $total,
                'total' => $total,
            ];
        })->all();
        if ((float) $order->delivery_cost > 0) {
            $cost = number_format((float) $order->delivery_cost, 2, '.', '');
            $items[] = ['productId' => 'delivery-'.$order->id, 'title' => 'Доставка', 'quantity' => ['count' => '1'], 'unitPrice' => $cost, 'discountedUnitPrice' => $cost, 'subtotal' => $cost, 'total' => $cost];
        }
        $amount = number_format((float) $order->total_amount, 2, '.', '');
        $contact = $order->email ?? $order->client?->email ?? $order->address?->recipient_phone;

        return array_filter([
            'merchantId' => config('payment.providers.yandexpay.merchant_id'),
            'orderId' => $externalOrderId,
            'orderAmount' => $amount,
            'currencyCode' => 'RUB',
            'availablePaymentMethods' => ['CARD', 'SPLIT'],
            'isPrepayment' => true,
            'cart' => ['externalId' => $externalOrderId, 'items' => $items, 'total' => ['amount' => $amount]],
            'fiscalContact' => $contact,
            'metadata' => json_encode(['local_order_id' => $order->id], JSON_THROW_ON_ERROR),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $response = Http::acceptJson()->timeout(20)->withHeaders([
            'Authorization' => 'Api-Key '.config('payment.providers.yandexpay.api_key'),
            'X-Request-Id' => (string) Str::uuid(),
            'X-Request-Timeout' => '20000',
        ])->{$method}(rtrim((string) config('payment.providers.yandexpay.api_url'), '/').$path, $body);
        if (! $response->successful()) {
            throw new RuntimeException('Яндекс Пэй временно не отвечает.');
        }
        return $response->json();
    }

    private function jwkUrl(): string
    {
        return config('payment.providers.yandexpay.env') === 'production'
            ? 'https://pay.yandex.ru/api/jwks'
            : 'https://sandbox.pay.yandex.ru/api/jwks';
    }
}
