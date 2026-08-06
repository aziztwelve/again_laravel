<?php

namespace App\Http\Controllers\Api\Public\Order;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CloudPaymentsController extends Controller
{
    public function intent(string $viewToken): JsonResponse
    {
        if (! config('payment.providers.cloudpayment.enabled')) {
            return response()->json(['success' => false, 'message' => 'Онлайн-оплата временно недоступна.'], 503);
        }

        $order = Order::query()->where('view_token', $viewToken)->first();
        if (! $order || ! $order->canBePaid() || $order->payment_method !== 'card_ru') {
            return response()->json(['success' => false, 'message' => 'Этот заказ нельзя оплатить картой.'], 422);
        }

        $amount = round((float) $order->total_amount, 2);
        if ($amount <= 0) {
            return response()->json(['success' => false, 'message' => 'Для заказа нет суммы к оплате.'], 422);
        }

        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'cloudpayment',
            'amount' => $amount,
            'currency' => 'RUB',
            'status' => Payment::STATUS_PENDING,
        ]);

        return response()->json([
            'success' => true,
            'payment' => [
                'publicTerminalId' => config('payment.providers.cloudpayment.public_id'),
                'amount' => $amount,
                'currency' => 'RUB',
                'description' => "Оплата заказа №{$order->order_number}",
                'externalId' => "payment-{$payment->id}",
                'paymentSchema' => 'Single',
                'culture' => 'ru-RU',
                'metadata' => ['payment_id' => $payment->id, 'order_id' => $order->id],
                'receiptEmail' => $order->email ?? $order->client?->email,
            ],
        ]);
    }

    public function check(Request $request): JsonResponse
    {
        if (! $this->isValidSignature($request)) {
            return response()->json(['code' => 13], 403);
        }

        $payment = $this->resolvePayment($request);
        if (! $payment || ! $this->matchesPayment($payment, $request) || ! $payment->order->canBePaid()) {
            return response()->json(['code' => 12]);
        }

        return response()->json(['code' => 0]);
    }

    public function pay(Request $request): JsonResponse
    {
        if (! $this->isValidSignature($request)) {
            return response()->json(['code' => 13], 403);
        }

        $payment = $this->resolvePayment($request);
        if (! $payment || ! $this->matchesPayment($payment, $request)) {
            return response()->json(['code' => 13]);
        }

        if (! $payment->isCompleted()) {
            $payment->update([
                'status' => Payment::STATUS_COMPLETED,
                'provider_payment_id' => (string) $request->input('TransactionId'),
                'provider_data' => $request->all(),
                'error_message' => null,
            ]);

            if (! $payment->order->isPaid()) {
                $payment->order->updatePaymentStatus(PaymentStatus::PAID, (string) $request->input('TransactionId'));
            }
        }

        return response()->json(['code' => 0]);
    }

    public function fail(Request $request): JsonResponse
    {
        if (! $this->isValidSignature($request)) {
            return response()->json(['code' => 13], 403);
        }

        $payment = $this->resolvePayment($request);
        if (! $payment || ! $this->matchesPayment($payment, $request)) {
            return response()->json(['code' => 13]);
        }

        if (! $payment->isCompleted()) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'provider_payment_id' => (string) $request->input('TransactionId'),
                'provider_data' => $request->all(),
                'error_message' => (string) ($request->input('Reason') ?? $request->input('CardHolderMessage') ?? 'Платёж отклонён'),
            ]);

            if (! $payment->order->isPaid()) {
                $payment->order->updatePaymentStatus(PaymentStatus::FAILED);
            }
        }

        return response()->json(['code' => 0]);
    }

    private function isValidSignature(Request $request): bool
    {
        $secret = (string) config('payment.providers.cloudpayment.api_secret');
        $signature = $request->header('Content-HMAC') ?: $request->header('X-Content-HMAC');

        if ($secret === '' || ! is_string($signature)) {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        return hash_equals($calculated, $signature);
    }

    private function resolvePayment(Request $request): ?Payment
    {
        $metadata = $request->input('Data', $request->input('metadata', []));
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        $paymentId = is_array($metadata) ? ($metadata['payment_id'] ?? null) : null;
        $externalId = (string) ($request->input('ExternalId') ?? $request->input('InvoiceId') ?? '');
        if (! $paymentId && preg_match('/^payment-(\d+)$/', $externalId, $matches)) {
            $paymentId = $matches[1];
        }

        return $paymentId ? Payment::with('order')->find($paymentId) : null;
    }

    private function matchesPayment(Payment $payment, Request $request): bool
    {
        return $payment->provider === 'cloudpayment'
            && round((float) $request->input('Amount'), 2) === round((float) $payment->amount, 2)
            && strtoupper((string) $request->input('Currency', 'RUB')) === $payment->currency;
    }
}
