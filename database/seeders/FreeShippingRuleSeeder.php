<?php

namespace Database\Seeders;

use App\Models\FreeShippingRule;
use Illuminate\Database\Seeder;

/**
 * Дефолтные правила бесплатной доставки (docs/tasks/free-shipping.md).
 *
 * Повторяют поведение, которое до этой фичи было захардкожено в
 * OrderCreationService::resolveDeliveryCost(): Яндекс курьер — от 7900 ₽,
 * Яндекс ПВЗ — от 4500 ₽. Без сидера после деплоя бесплатной доставки не
 * будет ни у кого.
 *
 * Идемпотентен: updateOrCreate по name — безопасно запускать повторно.
 * Запуск точечно (НЕ через полный db:seed):
 *   php artisan db:seed --class=FreeShippingRuleSeeder --force
 */
class FreeShippingRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name' => 'Яндекс.Доставка ПВЗ — бесплатно от 4500 ₽',
                'min_order_amount' => 4500,
                'services' => ['yandex'],
                'delivery_types' => ['pickup'],
                'priority' => 10,
            ],
            [
                'name' => 'Яндекс.Доставка курьер — бесплатно от 7900 ₽',
                'min_order_amount' => 7900,
                'services' => ['yandex'],
                'delivery_types' => ['courier'],
                'priority' => 10,
            ],
        ];

        foreach ($rules as $rule) {
            FreeShippingRule::updateOrCreate(
                ['name' => $rule['name']],
                [
                    'is_active' => true,
                    'priority' => $rule['priority'],
                    'min_order_amount' => $rule['min_order_amount'],
                    'services' => $rule['services'],
                    'delivery_types' => $rule['delivery_types'],
                    // Остальные условия пустые = «любые»: гео, товары и способы
                    // оплаты не ограничиваем — так было и в старом хардкоде.
                    'payment_methods' => null,
                    'starts_at' => null,
                    'ends_at' => null,
                ]
            );
        }
    }
}
