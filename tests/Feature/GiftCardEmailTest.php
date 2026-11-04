<?php

namespace Tests\Feature;

use App\Models\GiftCard\GiftCard;
use App\Services\Notifications\GiftCardMessageBuilder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GiftCardEmailTest extends TestCase
{
    private function card(array $attrs = []): GiftCard
    {
        return (new GiftCard)->forceFill(array_merge([
            'code' => 'AGAIN-TEST-2000',
            'nominal' => 2000,
            'balance' => 2000,
            'recipient_name' => 'Анна',
        ], $attrs));
    }

    public function test_email_contains_the_approved_copy_amount_and_code(): void
    {
        $html = app(GiftCardMessageBuilder::class)->buildEmailHtml($this->card());

        $this->assertStringContainsString('Анна, заботиться о себе — это здорово!', $html);
        $this->assertStringContainsString('сумму <strong>2 000 ₽</strong>', $html);
        $this->assertStringContainsString('AGAIN-TEST-2000', $html);
        $this->assertStringContainsString('Как использовать промокод?', $html);
        $this->assertStringContainsString('нажми на кнопку «Оформить заказ». В поле «Подарочная карта» введи код, указанный выше.', $html);
        $this->assertStringNotContainsString('Введите промокод', $html);
        $this->assertStringContainsString('Ты супер!', $html);
        $this->assertStringContainsString('Again.', $html);
    }

    public function test_email_has_support_buttons_with_working_links(): void
    {
        $html = app(GiftCardMessageBuilder::class)->buildEmailHtml($this->card());

        $this->assertStringContainsString('href="https://wa.me/79213828888"', $html);
        $this->assertStringContainsString('href="https://t.me/again8help_bot"', $html);
        $this->assertStringContainsString('href="https://max.ru/'.config('services.messenger_deeplinks.max_bot').'"', $html);
        $this->assertStringContainsString('href="'.rtrim(config('app.frontend_url'), '/').'"', $html);
    }

    public function test_greeting_falls_back_when_name_is_missing_and_personal_message_is_kept(): void
    {
        $builder = app(GiftCardMessageBuilder::class);

        $html = $builder->buildEmailHtml($this->card(['recipient_name' => null, 'sender_name' => 'Мария', 'message' => 'С днём рождения!']));

        $this->assertStringContainsString('Заботиться о себе — это здорово!', $html);
        $this->assertStringNotContainsString(', заботиться', $html);
        $this->assertStringContainsString('Сообщение от Мария', $html);
        $this->assertStringContainsString('С днём рождения!', $html);
    }

    public function test_user_supplied_text_is_escaped(): void
    {
        $html = app(GiftCardMessageBuilder::class)->buildEmailHtml(
            $this->card(['recipient_name' => '<script>alert(1)</script>', 'message' => '<b>x</b>'])
        );

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_fractional_amount_keeps_kopecks(): void
    {
        $html = app(GiftCardMessageBuilder::class)->buildEmailHtml($this->card(['nominal' => 1500.5]));

        $this->assertStringContainsString('1 500,50 ₽', $html);
    }

    public function test_certificate_image_is_shown_for_nominals_that_have_one(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('gift-certificates/2000.jpg', 'x');
        $builder = app(GiftCardMessageBuilder::class);

        $with = $builder->buildEmailHtml($this->card(['nominal' => 2000]));
        $without = $builder->buildEmailHtml($this->card(['nominal' => 2500]));

        $this->assertStringContainsString('src="'.url('/storage/gift-certificates/2000.jpg').'"', $with);
        $this->assertStringNotContainsString('<img', $without);
    }
}
