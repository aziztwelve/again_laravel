<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Создаём минимально валидный заказ. Связанные сущности (client / delivery_*)
     * НЕ создаём через их фабрики намеренно:
     *  - orders.delivery_date_id в схеме нет, а DeliveryDateFactory создаёт
     *    order_id => Order::factory() — это давало бесконечную взаимную рекурсию
     *    фабрик (Order → DeliveryDate → Order → …) и переполнение стека;
     *  - DeliveryMethodFactory / DeliveryTargetFactory устарели и пишут колонки,
     *    которых нет в таблицах.
     * Все эти FK (client_id, delivery_method_id, delivery_target_id) в orders
     * nullable, поэтому оставляем их пустыми. Нужным тестам клиент/доставка
     * проставляются явно через состояние фабрики.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => $this->faker->unique()->numerify('########'), // Уникальный номер заказа
            'status' => $this->faker->randomElement(OrderStatus::values()),
            'payment_status' => $this->faker->randomElement(PaymentStatus::values()),
            'total_amount' => $this->faker->randomFloat(2, 10, 1000),
            'discount_amount' => $this->faker->randomFloat(2, 0, 100),
            'created_at' => $this->faker->dateTimeBetween('-1 year', 'now'),
        ];
    }
}
