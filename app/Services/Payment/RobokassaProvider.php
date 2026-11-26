<?php

namespace App\Services\Payment;

use App\DTOs\PaymentDTO;
use App\Models\Payment;
use RuntimeException;

/**
 * Тонкая обёртка под PaymentProviderInterface (нужна PaymentService::refundPayment
 * и т.п.). Вся логика оплаты живёт в RobokassaService; оплата идёт через
 * RobokassaController, а не через этот класс.
 */
class RobokassaProvider extends AbstractPaymentProvider
{
    public static function getProviderCode(): string
    {
        return RobokassaService::PROVIDER;
    }

    public function initializePayment(PaymentDTO $paymentDTO): array
    {
        throw new RuntimeException('Оплата Robokassa создаётся через /api/public/orders/{viewToken}/robokassa/intent.');
    }

    public function processPayment(array $data): Payment
    {
        return app(RobokassaService::class)->confirmPayment($data);
    }

    public function checkPaymentStatus(Payment $payment): string
    {
        return $payment->status;
    }

    public function refundPayment(Payment $payment, ?float $amount = null): bool
    {
        app(RobokassaService::class)->refund($payment, $amount);

        return true;
    }
}
