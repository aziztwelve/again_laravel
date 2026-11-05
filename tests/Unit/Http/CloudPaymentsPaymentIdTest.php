<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\Api\Public\Order\CloudPaymentsController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Номер платежа в уведомлениях CloudPayments. Номер заказа магазина передаётся
 * в InvoiceId, поэтому платёж ищем по данным виджета, а не по InvoiceId.
 */
class CloudPaymentsPaymentIdTest extends TestCase
{
    private function paymentId(array $payload): ?int
    {
        $method = new ReflectionMethod(CloudPaymentsController::class, 'paymentIdFromRequest');
        $method->setAccessible(true);

        return $method->invoke(app(CloudPaymentsController::class), Request::create('/webhook', 'POST', $payload));
    }

    public function test_real_webhook_shape_with_nested_metadata(): void
    {
        $data = json_encode(['Metadata' => ['payment_id' => 76, 'order_id' => 40249], 'CloudPayments' => ['CustomerReceipt' => []]]);

        // InvoiceId — номер заказа, а не payment-N.
        $this->assertSame(76, $this->paymentId(['InvoiceId' => '40249', 'Data' => $data]));
    }

    public function test_data_may_arrive_as_array(): void
    {
        $this->assertSame(77, $this->paymentId(['InvoiceId' => '40250', 'Data' => ['Metadata' => ['payment_id' => 77]]]));
    }

    public function test_flat_metadata_is_still_supported(): void
    {
        $this->assertSame(5, $this->paymentId(['Data' => json_encode(['payment_id' => 5])]));
    }

    public function test_legacy_invoice_id_format_is_still_resolved(): void
    {
        $this->assertSame(72, $this->paymentId(['InvoiceId' => 'payment-72']));
        $this->assertSame(72, $this->paymentId(['ExternalId' => 'payment-72']));
    }

    public function test_order_number_without_metadata_is_not_mistaken_for_a_payment_id(): void
    {
        $this->assertNull($this->paymentId(['InvoiceId' => '40249']));
        $this->assertNull($this->paymentId([]));
    }

    public function test_metadata_wins_over_legacy_invoice_id(): void
    {
        $this->assertSame(76, $this->paymentId(['InvoiceId' => 'payment-1', 'Data' => json_encode(['Metadata' => ['payment_id' => 76]])]));
    }
}
