<?php

namespace Tests\Feature\Order;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Гость обязан указать email в чекауте: на него уходит чек и ссылка на заказ.
 * Вошедшему покупателю email подтягивается из аккаунта, менеджеру — из карточки клиента.
 */
class GuestCheckoutEmailRequiredTest extends TestCase
{
    use DatabaseTransactions;

    /** Минимум полей, чтобы остальные ошибки валидации не мешали смотреть на email. */
    private function payload(array $user = []): array
    {
        return [
            'user' => array_merge(['first_name' => 'Тест', 'last_name' => 'Покупатель', 'phone' => '+79990000000'], $user),
            'delivery_address' => ['country' => 'Россия', 'city' => 'Москва', 'address' => 'Тестовая, 1'],
            'items' => [['product_id' => 1, 'quantity' => 1, 'price' => 100]],
        ];
    }

    public function test_guest_without_email_is_rejected(): void
    {
        $this->postJson('/api/public/orders', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user.email']);
    }

    public function test_guest_with_blank_email_is_rejected(): void
    {
        $this->postJson('/api/public/orders', $this->payload(['email' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user.email']);
    }

    public function test_guest_with_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/public/orders', $this->payload(['email' => 'не-почта']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user.email']);
    }

    public function test_guest_with_email_passes_the_email_rule(): void
    {
        $this->postJson('/api/public/orders', $this->payload(['email' => 'guest@example.com']))
            ->assertJsonMissingValidationErrors(['user.email']);
    }

    public function test_logged_in_customer_does_not_need_to_send_email(): void
    {
        Sanctum::actingAs(new Client(['id' => 999999, 'email' => 'client@example.com']), ['*']);

        $this->postJson('/api/public/orders', $this->payload())
            ->assertJsonMissingValidationErrors(['user.email']);
    }

    public function test_staff_creating_an_order_for_a_client_does_not_need_email(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/orders', $this->payload())
            ->assertJsonMissingValidationErrors(['user.email']);
    }
}
