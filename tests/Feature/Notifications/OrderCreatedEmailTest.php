<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\EmailUnsubscribe;
use App\Models\Order;
use App\Services\Notifications\OrderMessageBuilder;
use App\Support\EmailBranding;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Письмо 2 «Заказ оформлен»: тема, текст, кнопка оплаты, мессенджеры, логотип и подвал с отпиской.
 * Отписка отключает только рекламные письма.
 */
class OrderCreatedEmailTest extends TestCase
{
    use DatabaseTransactions;

    private function order(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'view_token' => bin2hex(random_bytes(16)),
            'total_amount' => 7900,
            'payment_status' => 'pending',
        ], $attrs));
    }

    private function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    public function test_subject_headline_pay_button_and_messengers(): void
    {
        $order = $this->order();
        $email = app(OrderMessageBuilder::class)->buildOrderCreatedEmail($order);

        $number = $order->order_number ?? $order->id;
        $this->assertSame("AGAIN | Заказ {$number} принят!", $email['subject']);
        $this->assertStringContainsString('выбрали комфорт. А мы уже готовим ваш заказ!', $email['html']);
        $this->assertStringContainsString("Ваш заказ {$number} успешно создан.", $email['html']);
        $this->assertStringContainsString('Перейти к оплате', $email['html']);
        $this->assertStringContainsString('/orders/'.$order->view_token, $email['html']);
        $this->assertStringContainsString('https://t.me/again8help_bot', $email['html']);
        $this->assertStringContainsString('https://max.ru/id4707052811_1_bot', $email['html']);
        $this->assertStringContainsString('https://wa.me/79991178888', $email['html']);
        $this->assertStringContainsString('Итого:', $email['html']);
    }

    public function test_paid_order_has_no_pay_button(): void
    {
        $order = $this->order(['payment_status' => 'paid']);

        $html = app(OrderMessageBuilder::class)->buildOrderCreatedEmail($order)['html'];

        $this->assertStringNotContainsString('Перейти к оплате', $html);
    }

    public function test_every_email_has_logo_and_footer_with_unsubscribe(): void
    {
        $html = app(OrderMessageBuilder::class)->buildOrderCreatedEmail($this->order())['html'];

        $this->assertStringContainsString('/img/email/logo.png', $html);
        $this->assertStringContainsString('Умное бельё и косметика', $html);
        $this->assertStringContainsString('vk.com/again8ru', $html);
        $this->assertStringContainsString('instagram.com/again8.ru', $html);
        $this->assertStringContainsString('рекламные письма', $html);
        $this->assertStringContainsString(EmailBranding::UNSUBSCRIBE_PLACEHOLDER, $html);
    }

    public function test_sending_replaces_placeholder_with_personal_signed_link(): void
    {
        $html = app(OrderMessageBuilder::class)->buildOrderCreatedEmail($this->order())['html'];

        Mail::html($html, fn ($m) => $m->to('Client@Example.com')->subject('t'));

        $sent = $this->sentMessages();
        $body = end($sent)->getOriginalMessage()->getHtmlBody();
        $this->assertStringNotContainsString(EmailBranding::UNSUBSCRIBE_PLACEHOLDER, $body);
        $this->assertStringContainsString('/email/unsubscribe', $body);
        $this->assertStringContainsString('signature=', $body);
        $this->assertStringContainsString('client%40example.com', $body);
    }

    public function test_unsubscribed_address_does_not_get_marketing_but_gets_order_emails(): void
    {
        EmailUnsubscribe::unsubscribe('client@example.com');
        $before = count($this->sentMessages());

        Mail::html(EmailBranding::MARKETING_MARKER.'<p>Вы забыли корзину</p>', fn ($m) => $m->to('client@example.com')->subject('реклама'));
        $this->assertCount($before, $this->sentMessages());

        $orderHtml = app(OrderMessageBuilder::class)->buildOrderCreatedEmail($this->order())['html'];
        Mail::html($orderHtml, fn ($m) => $m->to('client@example.com')->subject('заказ'));
        $this->assertCount($before + 1, $this->sentMessages());
    }

    public function test_marketing_goes_to_subscribed_address(): void
    {
        $before = count($this->sentMessages());

        Mail::html(EmailBranding::MARKETING_MARKER.'<p>Снова в наличии</p>', fn ($m) => $m->to('other@example.com')->subject('реклама'));

        $this->assertCount($before + 1, $this->sentMessages());
    }

    public function test_unsubscribe_link_shows_confirmation_and_post_unsubscribes(): void
    {
        $client = Client::factory()->create(['email' => 'client@example.com', 'subscribed_to_newsletter' => true]);
        $url = URL::signedRoute('email.unsubscribe', ['email' => 'client@example.com']);

        $this->get($url)->assertOk()->assertSee('Отписаться от рассылки?');
        $this->assertFalse(EmailUnsubscribe::isUnsubscribed('client@example.com'));

        $this->post($url)->assertOk()->assertSee('Вы отписались');
        $this->assertTrue(EmailUnsubscribe::isUnsubscribed('client@example.com'));
        $this->assertFalse((bool) $client->fresh()->subscribed_to_newsletter);
    }

    public function test_unsubscribe_link_with_changed_address_is_rejected(): void
    {
        $url = URL::signedRoute('email.unsubscribe', ['email' => 'client@example.com']);

        $this->post(str_replace('client%40example.com', 'victim%40example.com', $url))->assertStatus(403);
        $this->assertFalse(EmailUnsubscribe::isUnsubscribed('victim@example.com'));
    }
}
