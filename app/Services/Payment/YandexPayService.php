<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\YandexPayWebhookException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Серверная интеграция с Merchant API Яндекс Пэй (базовая механика полной
 * оплаты, методы CARD и SPLIT). См. docs/tasks/yandex-pay-integration.md.
 *
 * Источник истины по оплате — проверенное уведомление Яндекс Пэй либо
 * серверный запрос статуса заказа. Ни callback SDK, ни redirect браузера
 * заказ не оплачивают.
 */
class YandexPayService
{
    private const PROVIDER = 'yandexpay';

    /**
     * Способ оплаты заказа → preferredPaymentMethod формы Яндекс Пэй.
     * Значение приходит из сохранённого заказа, а не из запроса браузера.
     */
    public const METHODS = [
        'yandex_pay' => 'FULLPAYMENT',
        'yandex_pay_split' => 'SPLIT',
    ];

    /** Методы, доступные на форме. Оба сценария живут на одной форме. */
    private const AVAILABLE_METHODS = ['CARD', 'SPLIT'];

    public function isAvailable(): bool
    {
        return (bool) config('payment.providers.yandexpay.enabled')
            && filled(config('payment.providers.yandexpay.merchant_id'))
            && filled(config('payment.providers.yandexpay.api_key'));
    }

    public function supports(?string $paymentMethod): bool
    {
        return $paymentMethod !== null && array_key_exists($paymentMethod, self::METHODS);
    }

    /** Публичные параметры для Web SDK. Merchant API key сюда не попадает. */
    public function publicConfig(Order $order): array
    {
        return [
            'available' => $this->isAvailable() && $this->supports($order->payment_method) && $order->canBePaid(),
            'env' => config('payment.providers.yandexpay.env') === 'production' ? 'PRODUCTION' : 'SANDBOX',
            'merchant_id' => (string) config('payment.providers.yandexpay.merchant_id'),
            'currency_code' => 'RUB',
            'total_amount' => $this->money((float) $order->total_amount),
            'available_payment_methods' => self::AVAILABLE_METHODS,
            'preferred_payment_method' => self::METHODS[$order->payment_method] ?? null,
        ];
    }

    /**
     * Создаёт (или переиспользует) заказ Яндекс Пэй и возвращает ссылку оплаты.
     *
     * Повторный запрос по неоплаченному заказу не создаёт второй локальный
     * платёж и второй заказ Яндекс Пэй: `orderId` в Merchant API — ключ
     * идемпотентности, а он выводится из id локальной попытки.
     */
    public function intent(Order $order): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Яндекс Пэй временно недоступен.');
        }
        if (! $this->supports($order->payment_method)) {
            throw new RuntimeException('Для этого заказа недоступна оплата Яндекс Пэй.');
        }
        if ($order->isPaid()) {
            throw new RuntimeException('Заказ уже оплачен.');
        }
        if (! $order->canBePaid()) {
            throw new RuntimeException('Для этого заказа недоступна онлайн-оплата.');
        }

        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Для заказа нет суммы к оплате.');
        }

        $payment = $this->currentAttempt($order, $amount);

        // Ссылка живёт ttl секунд. Пока попытка свежая, отдаём сохранённую
        // ссылку: так двойной клик не порождает второй запрос в Яндекс Пэй.
        $storedUrl = data_get($payment->provider_data, 'payment_url');
        if (is_string($storedUrl) && $storedUrl !== '') {
            return ['payment_url' => $storedUrl, 'payment_id' => $payment->id];
        }

        $externalOrderId = (string) $payment->provider_payment_id;
        $response = $this->request('post', '/api/merchant/v1/orders', $this->payload($order, $externalOrderId));
        $paymentUrl = data_get($response, 'data.paymentUrl');
        if (! is_string($paymentUrl) || $paymentUrl === '') {
            throw new RuntimeException('Яндекс Пэй не вернул ссылку на оплату.');
        }

        $payment->update([
            'provider_data' => array_merge($payment->provider_data ?? [], [
                'external_order_id' => $externalOrderId,
                'payment_url' => $paymentUrl,
                'create_order' => $response,
            ]),
        ]);

        return ['payment_url' => $paymentUrl, 'payment_id' => $payment->id];
    }

    /**
     * Сверка статуса заказа через Merchant API.
     *
     * Нужна как fallback: браузерный redirect не является подтверждением
     * оплаты, а уведомление может задержаться. Возвращает локальный статус
     * платежа либо null, если попыток оплаты ещё не было.
     */
    public function syncStatus(Order $order): ?string
    {
        $payment = $this->latestAttempt($order);
        if (! $payment || ! filled($payment->provider_payment_id)) {
            return null;
        }
        if ($payment->isCompleted()) {
            return $payment->status;
        }

        $remoteOrder = $this->remoteOrder((string) $payment->provider_payment_id);
        $this->assertRemoteMatchesPayment($payment, $remoteOrder);
        $this->applyRemoteStatus($payment, $remoteOrder, ['source' => 'status_sync']);

        return $payment->fresh()?->status;
    }

    /**
     * Проверяет ES256-подпись до разбора payload и сверяет получателя.
     *
     * @throws YandexPayWebhookException
     */
    public function verifiedWebhookPayload(string $body): array
    {
        $jwt = trim($body);
        if ($jwt === '') {
            throw new YandexPayWebhookException('UNAUTHORIZED', 'Пустое уведомление Яндекс Пэй.', 403);
        }

        try {
            $payload = JWT::decode($jwt, JWK::parseKeySet($this->jwks()));
        } catch (ExpiredException $exception) {
            throw new YandexPayWebhookException('TOKEN_EXPIRED', 'Срок действия уведомления истёк.', 403, $exception);
        } catch (\Throwable $exception) {
            // Подпись могла не сойтись из-за ротации ключей — сбрасываем кэш
            // JWKS и пробуем один раз ещё, прежде чем отказывать.
            Cache::forget($this->jwksCacheKey());
            try {
                $payload = JWT::decode($jwt, JWK::parseKeySet($this->jwks()));
            } catch (\Throwable $retry) {
                throw new YandexPayWebhookException('UNAUTHORIZED', 'Недействительная подпись уведомления Яндекс Пэй.', 403, $retry);
            }
        }

        $payload = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new YandexPayWebhookException('OTHER', 'Некорректный payload уведомления.', 400);
        }

        $merchantId = (string) config('payment.providers.yandexpay.merchant_id');
        if ((string) ($payload['merchantId'] ?? '') !== $merchantId) {
            throw new YandexPayWebhookException('FORBIDDEN', 'Уведомление предназначено для другого магазина.', 403);
        }

        return $payload;
    }

    /**
     * Обрабатывает проверенное уведомление.
     *
     * Идемпотентно: повторный CAPTURED не создаёт второй Payment, не меняет
     * оплаченный заказ и не запускает post-payment процесс заново.
     *
     * @throws YandexPayWebhookException
     */
    public function processWebhook(array $payload): void
    {
        // OPERATION_STATUS_UPDATED и подписки в первую поставку не входят:
        // отвечаем 200, чтобы Яндекс Пэй не переотправлял их сутки.
        if (($payload['event'] ?? null) !== 'ORDER_STATUS_UPDATED') {
            return;
        }

        $externalOrderId = (string) data_get($payload, 'order.orderId');
        $status = (string) data_get($payload, 'order.paymentStatus');
        if ($externalOrderId === '' || $status === '') {
            throw new YandexPayWebhookException('OTHER', 'В уведомлении нет заказа или статуса оплаты.');
        }

        $payment = Payment::query()->with('order')
            ->where('provider', self::PROVIDER)
            ->where('provider_payment_id', $externalOrderId)
            ->first();
        if (! $payment || ! $payment->order) {
            throw new YandexPayWebhookException('ORDER_NOT_FOUND', 'Платёж Яндекс Пэй не найден.', 404);
        }
        if ($payment->order->isPaid() && ! $payment->isCompleted()) {
            throw new YandexPayWebhookException('CONFLICT', 'Заказ уже оплачен другим платежом.', 409);
        }

        // Сумма в уведомлении не передаётся. Перечитываем заказ в Merchant API
        // и сверяем с неизменяемым локальным платежом, прежде чем что-то менять.
        $remoteOrder = $this->remoteOrder($externalOrderId);
        $this->assertRemoteMatchesPayment($payment, $remoteOrder);
        $this->applyRemoteStatus($payment, $remoteOrder, ['webhook' => $payload]);
    }

    /**
     * Берёт актуальную попытку оплаты или создаёт новую.
     *
     * Ротация нужна, когда прежняя попытка провалилась, просрочена или сумма
     * заказа изменилась: `orderId` в Яндекс Пэй переиспользовать нельзя,
     * он уже в терминальном состоянии.
     */
    private function currentAttempt(Order $order, float $amount): Payment
    {
        return DB::transaction(function () use ($order, $amount): Payment {
            $existing = Payment::query()
                ->where('order_id', $order->id)
                ->where('provider', self::PROVIDER)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing?->isCompleted()) {
                throw new RuntimeException('Заказ уже оплачен.');
            }

            $reusable = $existing
                && $existing->isPending()
                && filled($existing->provider_payment_id)
                && round((float) $existing->amount, 2) === $amount
                && ! $this->isStale($existing);

            if ($reusable) {
                return $existing;
            }

            if ($existing && $existing->isPending()) {
                $existing->update([
                    'status' => Payment::STATUS_FAILED,
                    'error_message' => 'Попытка оплаты заменена новой.',
                ]);
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => self::PROVIDER,
                'amount' => $amount,
                'currency' => 'RUB',
                'status' => Payment::STATUS_PENDING,
            ]);

            $externalOrderId = sprintf('again-%d-%d', $order->id, $payment->id);
            $payment->update([
                'provider_payment_id' => $externalOrderId,
                'provider_data' => ['external_order_id' => $externalOrderId],
            ]);

            return $payment;
        });
    }

    private function latestAttempt(Order $order): ?Payment
    {
        return Payment::query()->with('order')
            ->where('order_id', $order->id)
            ->where('provider', self::PROVIDER)
            ->latest('id')
            ->first();
    }

    /**
     * Ссылка Яндекс Пэй живёт ttl секунд с момента создания заказа, поэтому
     * срок считаем от created_at: обновления provider_data его не сдвигают.
     */
    private function isStale(Payment $payment): bool
    {
        $ttl = max(120, (int) config('payment.providers.yandexpay.order_ttl', 1800));

        return $payment->created_at === null || $payment->created_at->lt(now()->subSeconds($ttl));
    }

    /** Применяет статус из Merchant API. Транзакция + блокировка = идемпотентность. */
    private function applyRemoteStatus(Payment $payment, array $remoteOrder, array $context): void
    {
        $status = (string) ($remoteOrder['paymentStatus'] ?? '');

        DB::transaction(function () use ($payment, $remoteOrder, $context, $status): void {
            /** @var Payment|null $locked */
            $locked = Payment::query()->with('order')->whereKey($payment->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->order) {
                return;
            }

            $providerData = array_merge($locked->provider_data ?? [], $context, ['order_status' => $remoteOrder]);

            // PENDING и AUTHORIZED успешной оплатой не считаем. Возвраты в
            // первую поставку не входят — статус платежа не меняем.
            if ($status === 'CAPTURED') {
                if ($locked->isCompleted()) {
                    return;
                }
                $locked->update([
                    'status' => Payment::STATUS_COMPLETED,
                    'provider_data' => $providerData,
                    'error_message' => null,
                ]);
                if (! $locked->order->isPaid()) {
                    $locked->order->updatePaymentStatus(PaymentStatus::PAID, (string) $locked->provider_payment_id);
                }

                return;
            }

            if (in_array($status, ['FAILED', 'VOIDED'], true) && ! $locked->isCompleted()) {
                $locked->update([
                    'status' => Payment::STATUS_FAILED,
                    'provider_data' => $providerData,
                    'error_message' => Str::limit(trim(implode(': ', array_filter([
                        $remoteOrder['reasonCode'] ?? null,
                        $remoteOrder['reason'] ?? null,
                    ]))) ?: 'Платёж не завершён', 250),
                ]);
                if (! $locked->order->isPaid()) {
                    $locked->order->updatePaymentStatus(PaymentStatus::FAILED);
                }

                return;
            }

            // PENDING/AUTHORIZED/возвраты: локальное состояние не меняем и не
            // трогаем запись, чтобы не сдвигать срок жизни попытки.
        });
    }

    /** @throws YandexPayWebhookException */
    private function remoteOrder(string $externalOrderId): array
    {
        try {
            $response = $this->request('get', '/api/merchant/v1/orders/'.rawurlencode($externalOrderId));
        } catch (\Throwable $exception) {
            throw new YandexPayWebhookException('OTHER', 'Не удалось получить заказ в Яндекс Пэй.', 503, $exception);
        }

        $remoteOrder = data_get($response, 'data.order');
        if (! is_array($remoteOrder) || $remoteOrder === []) {
            throw new YandexPayWebhookException('ORDER_NOT_FOUND', 'Заказ не найден в Яндекс Пэй.', 404);
        }

        return $remoteOrder;
    }

    /** @throws YandexPayWebhookException */
    private function assertRemoteMatchesPayment(Payment $payment, array $remoteOrder): void
    {
        $merchantId = (string) config('payment.providers.yandexpay.merchant_id');
        if ((string) ($remoteOrder['merchantId'] ?? '') !== $merchantId) {
            throw new YandexPayWebhookException('FORBIDDEN', 'Заказ принадлежит другому магазину.', 403);
        }
        if ((string) ($remoteOrder['orderId'] ?? '') !== (string) $payment->provider_payment_id) {
            throw new YandexPayWebhookException('ORDER_DETAILS_MISMATCH', 'Идентификатор заказа не совпал.');
        }
        if (round((float) ($remoteOrder['orderAmount'] ?? -1), 2) !== round((float) $payment->amount, 2)) {
            throw new YandexPayWebhookException('ORDER_AMOUNT_MISMATCH', 'Сумма заказа не совпала с локальным платежом.');
        }
    }

    /**
     * Собирает тело запроса создания заказа из серверных данных заказа.
     * Клиентские сумма и состав корзины источником истины не являются.
     */
    private function payload(Order $order, string $externalOrderId): array
    {
        $items = [];
        $itemsTotal = 0.0;

        foreach ($order->items()->with('product')->orderBy('id')->get() as $item) {
            /** @var OrderItem $item */
            $quantity = max(1, (int) $item->quantity);
            $unitPrice = round((float) $item->price, 2);
            $unitDiscount = round((float) $item->discount, 2);
            $lineTotal = round($unitPrice * $quantity, 2);
            $itemsTotal += $lineTotal;

            $items[] = $this->cartItem(
                'item-'.$item->id,
                $item->product?->name ?? $item->legacy_name ?? 'Товар',
                $quantity,
                $lineTotal,
                round(($unitPrice + $unitDiscount) * $quantity, 2),
                $unitPrice + $unitDiscount,
                $unitPrice,
            );
        }

        // Доставку Яндекс Пэй ждёт отдельной позицией корзины.
        $deliveryCost = round((float) $order->delivery_cost, 2);
        if ($deliveryCost > 0) {
            $itemsTotal += $deliveryCost;
            $items[] = $this->cartItem('delivery-'.$order->id, 'Доставка', 1, $deliveryCost);
        }

        $amount = round((float) $order->total_amount, 2);
        $itemsTotal = round($itemsTotal, 2);
        $externalAmount = 0.0;

        if ($itemsTotal > $amount) {
            // Подарочная карта и прочая внешняя оплата: Яндекс Пэй требует
            // amount + externalAmount == сумме позиций корзины.
            $externalAmount = round($itemsTotal - $amount, 2);
        } elseif ($itemsTotal < $amount) {
            $items[] = $this->cartItem('adjustment-'.$order->id, 'Доплата по заказу', 1, round($amount - $itemsTotal, 2));
        }

        $orderUrl = rtrim((string) config('app.frontend_url'), '/').'/orders/'.$order->view_token;
        $phone = $order->address?->recipient_phone ?? $order->phone;
        $contact = $order->email ?? $order->client?->email ?? $phone;
        $total = ['amount' => $this->money($amount)];
        if ($externalAmount > 0) {
            $total['externalAmount'] = $this->money($externalAmount);
        }

        return array_filter([
            'orderId' => $externalOrderId,
            'currencyCode' => 'RUB',
            'availablePaymentMethods' => self::AVAILABLE_METHODS,
            'preferredPaymentMethod' => self::METHODS[$order->payment_method] ?? 'FULLPAYMENT',
            'orderSource' => 'WEBSITE',
            'ttl' => max(120, (int) config('payment.providers.yandexpay.order_ttl', 1800)),
            'cart' => [
                'externalId' => $externalOrderId,
                'items' => $items,
                'total' => $total,
            ],
            'redirectUrls' => [
                'onSuccess' => $orderUrl.'?payment=success',
                'onError' => $orderUrl.'?payment=error',
                'onAbort' => $orderUrl.'?payment=abort',
            ],
            // Контакт нужен для электронного чека, телефон повышает
            // вероятность одобрения Сплита.
            'fiscalContact' => $contact,
            'billingPhone' => $phone,
            'metadata' => json_encode([
                'local_order_id' => $order->id,
                'order_number' => (string) ($order->order_number ?? $order->id),
            ], JSON_THROW_ON_ERROR),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function cartItem(
        string $productId,
        string $title,
        int $quantity,
        float $total,
        ?float $subtotal = null,
        ?float $unitPrice = null,
        ?float $discountedUnitPrice = null,
    ): array {
        $receiptTax = config('payment.providers.yandexpay.receipt_tax');

        return array_filter([
            'productId' => $productId,
            'title' => Str::limit($title, 200, ''),
            'quantity' => ['count' => (string) $quantity],
            'unitPrice' => $unitPrice !== null ? $this->money($unitPrice) : null,
            'discountedUnitPrice' => $discountedUnitPrice !== null ? $this->money($discountedUnitPrice) : null,
            'subtotal' => $subtotal !== null ? $this->money($subtotal) : null,
            'total' => $this->money($total),
            // Ставку НДС отправляем только когда онлайн-касса включена в
            // кабинете: иначе Яндекс Пэй чек не формирует и поле не нужно.
            'receipt' => filled($receiptTax) ? ['tax' => (int) $receiptTax] : null,
        ], static fn ($value) => $value !== null);
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $response = Http::acceptJson()
            ->timeout(20)
            ->withHeaders([
                'Authorization' => 'Api-Key '.config('payment.providers.yandexpay.api_key'),
                'X-Request-Id' => (string) Str::uuid(),
                // Merchant API принимает дедлайн 1000..10000 мс.
                'X-Request-Timeout' => '10000',
            ])
            ->{$method}(rtrim((string) config('payment.providers.yandexpay.api_url'), '/').$path, $body);

        if (! $response->successful()) {
            Log::warning('Yandex Pay API request failed', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new RuntimeException('Яндекс Пэй временно не отвечает.');
        }

        return (array) $response->json();
    }

    private function jwks(): array
    {
        return Cache::remember($this->jwksCacheKey(), now()->addMinutes(10), function (): array {
            $response = Http::timeout(15)->get($this->jwkUrl());
            if (! $response->successful() || ! is_array($response->json())) {
                throw new RuntimeException('Не удалось получить публичные ключи Яндекс Пэй.');
            }

            return $response->json();
        });
    }

    private function jwksCacheKey(): string
    {
        return 'yandex_pay.jwks.'.config('payment.providers.yandexpay.env');
    }

    private function jwkUrl(): string
    {
        return config('payment.providers.yandexpay.env') === 'production'
            ? 'https://pay.yandex.ru/api/jwks'
            : 'https://sandbox.pay.yandex.ru/api/jwks';
    }
}
