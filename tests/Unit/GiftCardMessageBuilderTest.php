<?php

namespace Tests\Unit;

use App\Models\GiftCard\GiftCard;
use App\Services\Notifications\GiftCardMessageBuilder;
use Tests\TestCase;

class GiftCardMessageBuilderTest extends TestCase
{
    public function test_delivery_confirmation_matches_reference_format(): void
    {
        $card = new GiftCard([
            'code' => 'D8JNWKA3K8DS',
            'nominal' => 10000,
            'recipient_name' => 'Антон',
        ]);
        $card->sent_at = \Carbon\Carbon::create(2026, 2, 19, 9, 38);

        $message = (new GiftCardMessageBuilder())->buildDeliveryConfirmation($card);

        $this->assertStringContainsString('Ваша подарочная карта успешно доставлена!', $message);
        $this->assertStringContainsString('Получатель: Антон', $message);
        $this->assertStringContainsString('Номинал: 10000.00 ₽', $message);
        $this->assertStringContainsString('Код: D8JNWKA3K8DS', $message);
        $this->assertStringContainsString('Доставлено: 19.02.2026 09:38', $message);
        // По ТЗ строки «Заказ #...» быть не должно.
        $this->assertStringNotContainsString('Заказ #', $message);
    }

    public function test_issued_message_contains_core_fields(): void
    {
        $card = new GiftCard([
            'code' => 'ABC123',
            'nominal' => 5000,
            'recipient_name' => 'Мария',
        ]);

        $message = (new GiftCardMessageBuilder())->buildIssued($card);

        $this->assertStringContainsString('Подарочная карта успешно оформлена!', $message);
        $this->assertStringContainsString('Получатель: Мария', $message);
        $this->assertStringContainsString('Код: ABC123', $message);
    }
}
