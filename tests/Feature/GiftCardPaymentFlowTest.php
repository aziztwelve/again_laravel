<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Jobs\GiftCard\SendGiftCardJob;
use App\Models\GiftCard\GiftCard;
use App\Models\Order;
use App\Services\GiftCard\GiftCardDeliveryService;
use App\Services\GiftCard\GiftCardService;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Подарочный сертификат: до оплаты заказа карта неактивна и никуда не уходит; после оплаты она
 * включается, покупатель получает «карта оформлена», а получателю ставится доставка.
 * Заказ 41509 (17.11.2026): карта ушла получателю за 21 минуту до оплаты.
 */
class GiftCardPaymentFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function order(string $payment = 'pending'): Order
    {
        $order = Order::factory()->create([
            'client_id' => null, 'email' => 'buyer@example.com', 'status' => 'new', 'payment_status' => $payment,
            'paid_at' => $payment === 'paid' ? now() : null, 'total_amount' => 2000, 'created_at' => now(),
            'moysklad_order_uuid' => null,
        ]);
        $order->address()->create(['recipient_first_name' => 'Анна']);

        return $order->fresh();
    }

    private function createCard(Order $order, array $data = []): GiftCard
    {
        return app(GiftCardService::class)->createFromOrder($order, array_merge([
            'recipient_name' => 'Вера', 'recipient_email' => 'vera@example.com',
            'delivery_type' => 'immediate', 'delivery_channel' => 'email',
        ], $data), 2000);
    }

    private function pay(Order $order): void
    {
        $order->updatePaymentStatus(PaymentStatus::PAID, 'tx-'.uniqid());
    }

    public function test_creating_the_order_does_not_send_anything_to_anyone(): void
    {
        $card = $this->createCard($this->order());

        $this->assertSame(GiftCard::STATUS_INACTIVE, $card->fresh()->status);
        Bus::assertNotDispatched(SendGiftCardJob::class);
        Bus::assertNotDispatched(SendNotificationJob::class);
    }

    public function test_payment_activates_the_card_tells_the_buyer_and_queues_the_delivery(): void
    {
        $order = $this->order();
        $card = $this->createCard($order);

        $this->pay($order);

        $this->assertSame(GiftCard::STATUS_ACTIVE, $card->fresh()->status);
        Bus::assertDispatched(SendGiftCardJob::class, 1);
        Bus::assertDispatched(SendNotificationJob::class, fn ($job) => str_contains((new \ReflectionClass($job))->getProperty('message')->getValue($job), 'успешно оформлена'));
        Bus::assertDispatched(SendNotificationJob::class, 1);
    }

    public function test_a_repeated_payment_event_does_not_send_the_card_twice(): void
    {
        $order = $this->order();
        $this->createCard($order);

        $this->pay($order);
        $order->update(['payment_status' => 'pending']);
        $this->pay($order->fresh());

        Bus::assertDispatched(SendGiftCardJob::class, 1);
    }

    public function test_an_already_paid_order_activates_the_card_right_away(): void
    {
        $order = $this->order('paid');
        $card = $this->createCard($order);

        $this->assertSame(1, app(GiftCardService::class)->activatePaidOrderCards($order));

        $this->assertSame(GiftCard::STATUS_ACTIVE, $card->fresh()->status);
        Bus::assertDispatched(SendGiftCardJob::class, 1);
    }

    public function test_a_card_scheduled_for_the_future_is_sent_at_that_time_not_right_after_payment(): void
    {
        $order = $this->order();
        $this->createCard($order, ['delivery_type' => 'scheduled', 'scheduled_date' => now()->addDays(3)->format('Y-m-d'), 'scheduled_time' => '12:00', 'timezone' => 'Europe/Moscow']);

        $this->pay($order);

        Bus::assertDispatched(SendGiftCardJob::class, fn ($job) => $job->delay !== null);
    }

    public function test_plastic_cards_are_activated_but_not_sent_electronically(): void
    {
        $order = $this->order();
        $card = $this->createCard($order, ['type' => GiftCard::TYPE_PLASTIC]);
        $card->update(['type' => GiftCard::TYPE_PLASTIC]);

        $this->pay($order);

        $this->assertSame(GiftCard::STATUS_ACTIVE, $card->fresh()->status);
        Bus::assertNotDispatched(SendGiftCardJob::class);
    }

    public function test_cancelled_cards_are_left_alone(): void
    {
        $order = $this->order();
        $card = $this->createCard($order);
        $card->update(['status' => GiftCard::STATUS_CANCELLED]);

        $this->pay($order);

        $this->assertSame(GiftCard::STATUS_CANCELLED, $card->fresh()->status);
        Bus::assertNotDispatched(SendGiftCardJob::class);
    }

    public function test_an_orders_without_a_card_are_not_affected(): void
    {
        $this->pay($this->order());

        Bus::assertNotDispatched(SendGiftCardJob::class);
    }

    // ---- страховка в самом задании отправки

    private function runJob(GiftCard $card, bool $expectSend): void
    {
        $delivery = $this->mock(GiftCardDeliveryService::class);
        if ($expectSend) {
            $delivery->shouldReceive('send')->once()->andReturn(true);
            $delivery->shouldReceive('sendDeliveryConfirmation')->once();
        } else {
            $delivery->shouldNotReceive('send');
        }

        (new SendGiftCardJob($card->id))->handle($delivery);
    }

    public function test_the_send_job_refuses_to_send_a_card_of_an_unpaid_order(): void
    {
        $card = $this->createCard($this->order());

        $this->runJob($card, expectSend: false);

        $this->assertNull($card->fresh()->sent_at);
    }

    public function test_the_send_job_sends_a_card_of_a_paid_order(): void
    {
        $card = $this->createCard($this->order('paid'));

        $this->runJob($card, expectSend: true);
    }

    public function test_the_send_job_refuses_a_cancelled_card(): void
    {
        $card = $this->createCard($this->order('paid'));
        $card->update(['status' => GiftCard::STATUS_CANCELLED]);

        $this->runJob($card, expectSend: false);
    }

    public function test_a_card_without_an_order_can_still_be_sent(): void
    {
        $card = GiftCard::create([
            'code' => 'T'.strtoupper(substr(uniqid(), -9)), 'purchase_order_id' => null, 'nominal' => 1000, 'balance' => 1000,
            'type' => 'electronic', 'status' => 'active', 'recipient_email' => 'x@example.com', 'delivery_channel' => 'email',
        ]);

        $this->runJob($card, expectSend: true);
    }
}
