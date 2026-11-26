<?php

namespace App\Services\Payment;

use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
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

        return [
            'payment_id' => $payment->id,
            'payment_url' => (string) config('payment.providers.robokassa.payment_url'),
            'fields' => $this->buildFields($order, $payment),
        ];
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
        $invId = (string) self::invoiceId((int) $payment->id);

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
        $fields['Shp_pay'] = $this->shpToken($payment->id);

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
        $paymentId = self::paymentIdFromInvoice((int) ($data['InvId'] ?? 0));
        $token = (string) ($data['Shp_pay'] ?? '');

        return $paymentId !== null && $token !== '' && hash_equals($this->shpToken($paymentId), $token);
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
        $paymentId = self::paymentIdFromInvoice($invId);

        $payment = DB::transaction(function () use ($data, $invId, $paymentId): Payment {
            $payment = $paymentId === null ? null : Payment::query()
                ->where('provider', self::PROVIDER)
                ->lockForUpdate()
                ->find($paymentId);

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

    public function findPayment(array $data): ?Payment
    {
        $paymentId = self::paymentIdFromInvoice((int) ($data['InvId'] ?? 0));

        return $paymentId !== null
            ? Payment::with('order')->where('provider', self::PROVIDER)->find($paymentId)
            : null;
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

    /** Подпись номера платежа секретом приложения (в Shp_pay). */
    private function shpToken(int $paymentId): string
    {
        return substr(hash_hmac('sha256', 'robokassa:' . $paymentId, (string) config('app.key')), 0, 24);
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
