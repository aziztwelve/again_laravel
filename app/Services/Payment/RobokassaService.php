<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Оплата через Robokassa: SberPay, иностранные карты, рассрочка Подели и
 * остальные способы выбираются покупателем на странице Robokassa
 * (IncCurrLabel не передаём). См. docs/integrations/robokassa.md.
 *
 * Источник истины по оплате — Result URL с проверенной подписью (Пароль #2).
 * Редирект на Success/Fail URL оплату не подтверждает.
 */
class RobokassaService
{
    public const PROVIDER = 'robokassa';

    /**
     * Номер счёта Robokassa (InvId) = номер нашего платежа + сдвиг. Магазин `again8` принимал платежи
     * и раньше (InvId = номера заказов InSales, 1004…40225), и Robokassa не даёт использовать номер,
     * по которому уже есть операция (так «сломались» 2020 и 2021). Сдвиг уводит нас в свободный диапазон.
     */
    public const INVOICE_OFFSET = 100_000_000;

    /** Платежи до введения сдвига (id ≤ 2022) уходили в Robokassa с InvId = id; их уведомления ещё принимаем. */
    private const LEGACY_MAX_PAYMENT_ID = 2022;

    /** Код `orders.payment_method` для пункта чекаута «SberPay, иностранная карта, Подели». */
    public const PAYMENT_METHOD = 'robokassa';

    /** Ставка НДС (%) → значение `tax` в Receipt Robokassa. */
    private const TAX_MAP = [
        0 => 'vat0',
        5 => 'vat5',
        7 => 'vat7',
        10 => 'vat10',
        20 => 'vat20',
        22 => 'vat22',
    ];

    public static function invoiceId(int $paymentId): int
    {
        return self::INVOICE_OFFSET + $paymentId;
    }

    /** Обратное преобразование: InvId из уведомления → id нашего платежа (null — не наш номер). */
    public static function paymentIdFromInvoice(int $invId): ?int
    {
        if ($invId >= self::INVOICE_OFFSET) {
            return $invId - self::INVOICE_OFFSET;
        }

        return $invId > 0 && $invId <= self::LEGACY_MAX_PAYMENT_ID ? $invId : null;
    }

    public function isAvailable(): bool
    {
        return (bool) config('payment.providers.robokassa.enabled')
            && filled(config('payment.providers.robokassa.merchant_login'))
            && filled($this->password1())
            && filled($this->password2());
    }

    public function supports(?string $paymentMethod): bool
    {
        return $paymentMethod === self::PAYMENT_METHOD;
    }

    /** Нужно ли показывать кнопку «Оплатить онлайн» для заказа на странице заказа. */
    public function isAvailableForOrder(Order $order): bool
    {
        return $this->supports($order->payment_method)
            && $this->isAvailable()
            && $this->isAllowedForOrder($order)
            && $order->canBePaid();
    }

    /**
     * Email клиентов, которым способ доступен на закрытом тесте (в нижнем регистре).
     * Пустой список — ограничения нет.
     *
     * @return array<int, string>
     */
    public function allowedEmails(): array
    {
        return array_values(array_filter(array_map(
            fn ($email) => mb_strtolower(trim((string) $email)),
            (array) config('payment.providers.robokassa.allowed_emails', []),
        )));
    }

    public function isAllowedForEmail(?string $email): bool
    {
        $allowed = $this->allowedEmails();
        if ($allowed === []) {
            return true;
        }

        return $email !== null && in_array(mb_strtolower(trim($email)), $allowed, true);
    }

    public function isAllowedForOrder(Order $order): bool
    {
        return $this->isAllowedForEmail($order->email ?? $order->client?->email);
    }

    /** Показывать ли способ в чекауте этому покупателю (гость = null). */
    public function isOfferedTo(?Client $client): bool
    {
        return $this->isAvailable() && $this->isAllowedForEmail($client?->email);
    }

    /**
     * Создаёт попытку оплаты и возвращает поля формы для POST на страницу
     * Robokassa. Чек может быть большим, поэтому POST, а не ссылка.
     *
     * @return array{payment_id: int, payment_url: string, fields: array<string, string|int>}
     */
    public function intent(Order $order): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Оплата через Robokassa временно недоступна.');
        }

        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            throw new RuntimeException('Для заказа нет суммы к оплате.');
        }

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => self::PROVIDER,
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
        ]);
        $payment->update(['provider_payment_id' => (string) $this->chooseInvoiceId($order, $payment)]);

        return [
            'payment_id' => $payment->id,
            'payment_url' => (string) config('payment.providers.robokassa.payment_url'),
            'fields' => $this->buildFields($order, $payment),
        ];
    }

    /**
     * Номер счёта для Robokassa. В кабинете Robokassa в колонке «№ заказа» виден именно он, поэтому по
     * возможности берём номер заказа магазина (как в админке). Если номер заказа не подходит —
     * не число, уже использован нашим платежом (повторная попытка оплаты) или в Robokassa по нему уже есть
     * операция (старые заказы магазина), — берём технический номер: id платежа + сдвиг.
     */
    private function chooseInvoiceId(Order $order, Payment $payment): int
    {
        $fallback = self::invoiceId((int) $payment->id);
        $number = (string) $order->order_number;

        if (! ctype_digit($number) || strlen($number) > 9) {
            return $fallback;
        }

        $candidate = (int) $number;
        if ($candidate <= self::LEGACY_MAX_PAYMENT_ID || $candidate >= self::INVOICE_OFFSET) {
            return $fallback;
        }

        $usedHere = Payment::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_payment_id', (string) $candidate)
            ->where('id', '!=', $payment->id)
            ->exists();

        return ! $usedHere && $this->invoiceIsFree($candidate) ? $candidate : $fallback;
    }

    /** Свободен ли номер счёта в Robokassa (нет операции; любая ошибка запроса — «не свободен»). */
    private function invoiceIsFree(int $invId): bool
    {
        try {
            $login = (string) config('payment.providers.robokassa.merchant_login');
            $response = Http::timeout(10)->get((string) config('payment.providers.robokassa.op_state_ext_url'), [
                'MerchantLogin' => $login,
                'InvoiceID' => $invId,
                'Signature' => $this->hash("{$login}:{$invId}:" . $this->password2()),
            ]);

            return $response->successful()
                && preg_match('~<Result>\s*<Code>(\d+)</Code>~', $response->body(), $m) === 1
                && (int) $m[1] === 3;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Платёж по номеру счёта из уведомления: сначала по сохранённому номеру, затем по старой схеме (id + сдвиг). */
    private function paymentByInvoice(int $invId, bool $lock = false): ?Payment
    {
        $query = Payment::query()->where('provider', self::PROVIDER);
        if ($lock) {
            $query->lockForUpdate();
        }

        $found = (clone $query)->where('provider_payment_id', (string) $invId)->first();
        if ($found) {
            return $found;
        }

        $paymentId = self::paymentIdFromInvoice($invId);

        return $paymentId === null ? null : $query->find($paymentId);
    }

    /**
     * Поля формы оплаты вместе с подписью.
     * Подпись: MerchantLogin:OutSum:InvId[:Receipt]:Пароль1:Shp_*
     *
     * @return array<string, string|int>
     */
    public function buildFields(Order $order, Payment $payment): array
    {
        $login = (string) config('payment.providers.robokassa.merchant_login');
        $outSum = $this->money((float) $payment->amount);
        $invId = (string) ($payment->provider_payment_id ?: self::invoiceId((int) $payment->id));

        $fields = [
            'MerchantLogin' => $login,
            'OutSum' => $outSum,
            'InvId' => $invId,
            'Description' => $this->description($order),
            'Culture' => 'ru',
        ];

        $email = $order->email ?? $order->client?->email;
        if (filled($email)) {
            $fields['Email'] = (string) $email;
        }

        if (config('payment.providers.robokassa.is_test')) {
            $fields['IsTest'] = 1;
        }

        // Защита страницы возврата после неуспешной оплаты (см. controller).
        $fields['Shp_pay'] = $this->shpToken($invId);

        $signatureParts = [$login, $outSum, $invId];

        $receipt = config('payment.providers.robokassa.send_receipt')
            ? $this->buildReceipt($order, (float) $payment->amount)
            : null;
        if ($receipt !== null) {
            // В подпись и в форму уходит URL-кодированный JSON — как в официальном
            // PHP-примере Robokassa. Браузер кодирует значение поля ещё раз,
            // Robokassa раскодирует один раз и получит ту же строку.
            $encoded = urlencode(json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $fields['Receipt'] = $encoded;
            $signatureParts[] = $encoded;
        }

        $signatureParts[] = $this->password1();
        $signatureParts[] = 'Shp_pay=' . $fields['Shp_pay'];

        $fields['SignatureValue'] = $this->hash(implode(':', $signatureParts));

        return $fields;
    }

    /**
     * Чек 54-ФЗ для Robokassa. Сумма позиций должна равняться OutSum, поэтому
     * при частичной оплате сертификатом разница уходит в `payments` как зачёт
     * аванса (type=2), а не отрицательной позицией.
     *
     * @return array{items: array<int, array<string, mixed>>, payments?: array<int, array<string, mixed>>}|null
     */
    public function buildReceipt(Order $order, float $paymentAmount): ?array
    {
        $order->loadMissing(['items.product']);
        $defaultVat = (int) config('payment.providers.robokassa.receipt_vat', 5);

        $items = $order->items
            ->where('is_gift', false)
            ->map(function ($item) use ($defaultVat): array {
                $quantity = max(1, (int) $item->quantity);

                return [
                    'name' => $this->itemName((string) ($item->product?->name ?? $item->legacy_name ?? 'Товар')),
                    'quantity' => $quantity,
                    // price — финальная цена единицы после скидок (см. Order::updateTotalAmount).
                    'sum' => round((float) $item->price * $quantity, 2),
                    'payment_method' => 'full_payment',
                    'payment_object' => 'commodity',
                    'tax' => $this->tax($item->product?->receiptVat() ?? $defaultVat),
                ];
            })
            ->filter(fn (array $item) => $item['sum'] > 0)
            ->values();

        $deliveryCost = round((float) ($order->delivery_cost ?? 0), 2);
        if ($deliveryCost > 0) {
            $items->push([
                'name' => 'Доставка',
                'quantity' => 1,
                'sum' => $deliveryCost,
                'payment_method' => 'full_payment',
                'payment_object' => 'service',
                'tax' => $this->tax($defaultVat),
            ]);
        }

        $receiptTotal = round((float) $items->sum('sum'), 2);
        if ($items->isEmpty() || $receiptTotal < round($paymentAmount, 2)) {
            Log::warning('Robokassa receipt was not attached: inconsistent order totals', [
                'order_id' => $order->id,
                'receipt_total' => $receiptTotal,
                'payment_amount' => $paymentAmount,
            ]);

            return null;
        }

        $receipt = ['items' => $items->all()];

        $advance = round($receiptTotal - round($paymentAmount, 2), 2);
        if ($advance > 0) {
            $receipt['payments'] = [
                ['type' => 1, 'sum' => round($paymentAmount, 2)],
                ['type' => 2, 'sum' => $advance],
            ];
        }

        return $receipt;
    }

    /**
     * Подпись уведомления Result URL: OutSum:InvId:Пароль2[:Shp_*].
     * OutSum берём ровно в том виде, в каком пришёл (в проде — 6 знаков).
     */
    public function isValidResultSignature(array $data): bool
    {
        return $this->isValidSignature($data, $this->password2());
    }

    /** Подпись редиректа Success URL: OutSum:InvId:Пароль1[:Shp_*]. */
    public function isValidSuccessSignature(array $data): bool
    {
        return $this->isValidSignature($data, $this->password1());
    }

    public function isValidShpToken(array $data): bool
    {
        $invId = (string) ($data['InvId'] ?? '');
        $token = (string) ($data['Shp_pay'] ?? '');

        return ctype_digit($invId) && $token !== '' && hash_equals($this->shpToken($invId), $token);
    }

    /**
     * Обрабатывает проверенное уведомление Result URL. Идемпотентно: повторное
     * уведомление не создаёт повторных переходов статуса.
     *
     * @throws RuntimeException платёж не найден или данные не сходятся — Robokassa повторит уведомление
     */
    public function confirmPayment(array $data): Payment
    {
        $invId = (int) ($data['InvId'] ?? 0);

        $payment = DB::transaction(function () use ($data, $invId): Payment {
            $payment = $this->paymentByInvoice($invId, lock: true);

            if (! $payment) {
                throw new RuntimeException("Robokassa result: платёж {$invId} не найден");
            }

            if (round((float) ($data['OutSum'] ?? 0), 2) !== round((float) $payment->amount, 2)) {
                throw new RuntimeException("Robokassa result: сумма платежа {$invId} не совпадает");
            }

            if (! $payment->isCompleted()) {
                $payment->update([
                    'status' => Payment::STATUS_COMPLETED,
                    'provider_payment_id' => (string) $invId,
                    'provider_data' => collect($data)->except('SignatureValue')->all(),
                    'error_message' => null,
                ]);
            }

            return $payment;
        });

        // Статус заказа — вне транзакции и всегда, если заказ ещё не оплачен:
        // так повтор уведомления доводит заказ до «оплачен», даже если прошлая
        // попытка оборвалась после фиксации платежа.
        $order = $payment->order()->first();
        if ($order && ! $order->isPaid()) {
            $order->updatePaymentStatus(PaymentStatus::PAID, (string) $invId);
        }

        return $payment;
    }

    /**
     * Возврат оплаты через API возвратов Robokassa (JWT, подпись Password3).
     * Выполняется полный возврат; если чек был передан — вместе с чеком возврата прихода (54-ФЗ).
     *
     * @return array{request_id: string, label: string|null}
     *
     * @throws RuntimeException возврат невозможен или отклонён (текст можно показывать менеджеру)
     */
    public function refund(Payment $payment, ?float $amount = null): array
    {
        $password3 = (string) config('payment.providers.robokassa.password3');
        if ($password3 === '') {
            throw new RuntimeException('Возврат Robokassa не настроен: сгенерируйте Password3 в кабинете Robokassa и впишите его в ROBOKASSA_PASSWORD3.');
        }

        if ($payment->provider !== self::PROVIDER || ! $payment->isCompleted()) {
            throw new RuntimeException('Вернуть можно только оплаченный платёж Robokassa.');
        }

        $existing = data_get($payment->provider_data, 'refund.request_id');
        if ($existing) {
            return ['request_id' => (string) $existing, 'label' => data_get($payment->provider_data, 'refund.label')];
        }

        if ($amount !== null && round($amount, 2) < round((float) $payment->amount, 2)) {
            throw new RuntimeException('Частичный возврат Robokassa из админки не поддерживается — сделайте его в кабинете Robokassa.');
        }

        $invId = (int) data_get($payment->provider_data, 'InvId', 0) ?: (int) $payment->provider_payment_id ?: self::invoiceId((int) $payment->id);
        $payload = ['OpKey' => $this->opKey($invId)];

        $items = $this->refundItems($payment);
        if ($items !== null) {
            $payload['InvoiceItems'] = $items;
        }

        $response = Http::withBody(json_encode($this->jwt($payload, $password3)), 'application/json')
            ->timeout(30)
            ->post((string) config('payment.providers.robokassa.refund_url'));

        $json = $response->json() ?? [];
        if (! ($json['success'] ?? false)) {
            $message = (string) ($json['message'] ?? ('HTTP ' . $response->status()));
            Log::warning('Robokassa refund rejected', ['payment_id' => $payment->id, 'message' => $message, 'http' => $response->status()]);

            throw new RuntimeException(match ($message) {
                'NotEnoughOperationFunds' => 'Robokassa: недостаточно средств на балансе магазина для возврата.',
                default => 'Robokassa отклонила возврат: ' . $message,
            });
        }

        $requestId = (string) ($json['requestId'] ?? '');
        $label = $requestId !== '' ? $this->refundLabel($requestId) : null;

        $payment->update([
            'status' => Payment::STATUS_REFUNDED,
            'provider_data' => array_merge($payment->provider_data ?? [], [
                'refund' => ['request_id' => $requestId, 'label' => $label, 'at' => now()->toIso8601String()],
            ]),
        ]);

        Log::info('Robokassa refund created', ['payment_id' => $payment->id, 'request_id' => $requestId, 'label' => $label]);

        return ['request_id' => $requestId, 'label' => $label];
    }

    /** OpKey операции из OpStateExt (подпись — Пароль #2). Только для оплаченных счетов. */
    private function opKey(int $invId): string
    {
        $login = (string) config('payment.providers.robokassa.merchant_login');
        $response = Http::timeout(20)->get((string) config('payment.providers.robokassa.op_state_ext_url'), [
            'MerchantLogin' => $login,
            'InvoiceID' => $invId,
            'Signature' => $this->hash("{$login}:{$invId}:" . $this->password2()),
        ]);

        $xml = $response->body();
        $paid = preg_match('~<State>\s*<Code>(\d+)</Code>~', $xml, $state) && (int) $state[1] === 100;

        if (! $response->successful() || ! $paid || ! preg_match('~<OpKey>([^<]+)</OpKey>~', $xml, $key)) {
            throw new RuntimeException('Robokassa не подтвердила оплату этого счёта — возврат невозможен.');
        }

        return trim($key[1]);
    }

    /**
     * Позиции для чека возврата прихода — те же, что ушли в чеке оплаты (Cost — цена за единицу).
     * null — чек при оплате не передавался, возвращаются только деньги.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function refundItems(Payment $payment): ?array
    {
        if (! config('payment.providers.robokassa.send_receipt')) {
            return null;
        }

        $order = $payment->order()->first();
        $receipt = $order ? $this->buildReceipt($order, (float) $payment->amount) : null;

        if ($receipt === null || isset($receipt['payments'])) {
            // Чек не прикреплялся или оплата была частично сертификатом — состав возврата вручную.
            throw new RuntimeException('Автоматический возврат с чеком для этого заказа не поддерживается — сделайте возврат в кабинете Robokassa.');
        }

        return array_map(fn (array $item) => [
            'Name' => $item['name'],
            'Quantity' => $item['quantity'],
            'Cost' => round($item['sum'] / max(1, (int) $item['quantity']), 2),
            'Tax' => $item['tax'],
            'PaymentMethod' => $item['payment_method'],
            'PaymentObject' => $item['payment_object'],
        ], $receipt['items']);
    }

    /** Статус заявки на возврат (finished / processing / canceled) или null, если спросить не удалось. */
    private function refundLabel(string $requestId): ?string
    {
        try {
            $r = Http::timeout(15)->get((string) config('payment.providers.robokassa.refund_state_url'), ['id' => $requestId]);

            return $r->successful() ? ($r->json('label') ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** JWT HS256 с компактным JSON (требование Robokassa). */
    private function jwt(array $payload, string $secret): string
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], $flags));
        $body = $b64(json_encode($payload, $flags));

        return "{$head}.{$body}." . $b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
    }

    public function findPayment(array $data): ?Payment
    {
        return $this->paymentByInvoice((int) ($data['InvId'] ?? 0))?->load('order');
    }

    private function isValidSignature(array $data, string $password): bool
    {
        $signature = strtolower((string) ($data['SignatureValue'] ?? ''));
        if ($signature === '' || $password === '' || ! isset($data['OutSum'], $data['InvId'])) {
            return false;
        }

        $parts = [(string) $data['OutSum'], (string) $data['InvId'], $password];

        // Shp_* — в алфавитном порядке, в виде Shp_name=value.
        $shp = collect($data)
            ->filter(fn ($value, $key) => is_string($key) && str_starts_with($key, 'Shp_'))
            ->sortKeys()
            ->map(fn ($value, $key) => $key . '=' . $value)
            ->values()
            ->all();

        $calculated = strtolower($this->hash(implode(':', array_merge($parts, $shp))));

        return hash_equals($calculated, $signature);
    }

    private function hash(string $value): string
    {
        $algo = strtolower((string) config('payment.providers.robokassa.hash_algo', 'md5'));
        if (! in_array($algo, ['md5', 'sha256', 'sha384', 'sha512'], true)) {
            $algo = 'md5';
        }

        return hash($algo, $value);
    }

    /** Подпись номера счёта секретом приложения (в Shp_pay). */
    private function shpToken(string $invId): string
    {
        return substr(hash_hmac('sha256', 'robokassa:' . $invId, (string) config('app.key')), 0, 24);
    }

    private function password1(): string
    {
        $key = config('payment.providers.robokassa.is_test') ? 'test_password1' : 'password1';

        return (string) config("payment.providers.robokassa.{$key}");
    }

    private function password2(): string
    {
        $key = config('payment.providers.robokassa.is_test') ? 'test_password2' : 'password2';

        return (string) config("payment.providers.robokassa.{$key}");
    }

    /** Сумма: точка как разделитель, два знака. */
    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function tax(int $vatPercent): string
    {
        // Неизвестная ставка товара не должна давать чек «без НДС»: берём ставку по умолчанию (5 %).
        return self::TAX_MAP[$vatPercent]
            ?? self::TAX_MAP[(int) config('payment.providers.robokassa.receipt_vat', 5)]
            ?? 'vat5';
    }

    /** Описание платежа: до 100 символов, без спецсимволов. */
    private function description(Order $order): string
    {
        return Str::limit("Оплата заказа №{$order->order_number}", 100, '');
    }

    /** Название позиции чека: до 128 символов, без кавычек и обратных слэшей. */
    private function itemName(string $name): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F"\\\\]+/u', ' ', $name)));

        return Str::limit($clean !== '' ? $clean : 'Товар', 128, '');
    }
}
