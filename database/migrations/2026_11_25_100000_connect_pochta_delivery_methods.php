<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Способы доставки Почты России получают рабочий класс и названия из заявки
 * («Почта РФ: …»). Записи уже есть (с ними связаны старые заказы), поэтому
 * только обновляем name/description/provider_class по code. Остальные поля
 * (id, settings, is_active) не трогаем.
 */
return new class extends Migration
{
    private const METHODS = [
        'russian_post_office' => ['Почта РФ: в отделение', 'Получение в отделении Почты России'],
        'russian_post_courier' => ['Почта РФ: Курьер', 'Курьерская доставка Почтой России'],
        'international_pickup' => ['Почта РФ: международная в пвз', 'Международная доставка Почтой России в пункт выдачи'],
        'international_courier' => ['Почта РФ: международная курьер', 'Международная курьерская доставка Почтой России'],
    ];

    public function up(): void
    {
        foreach (self::METHODS as $code => [$name, $description]) {
            DB::table('delivery_methods')->where('code', $code)->update([
                'name' => $name,
                'description' => $description,
                'provider_class' => \App\Services\Delivery\PochtaDeliveryService::class,
            ]);
        }
    }

    public function down(): void
    {
        $legacy = [
            'russian_post_office' => 'Доставка в отделение Почты России или почтомат',
            'russian_post_courier' => 'Доставка курьером Почты России',
            'international_pickup' => 'Доставка международных отправлений в ПВЗ',
            'international_courier' => 'Доставка международных отправлений курьером',
        ];
        foreach ($legacy as $code => $name) {
            DB::table('delivery_methods')->where('code', $code)->update([
                'name' => $name,
                'provider_class' => \App\Services\Delivery\DeliveryService::class,
            ]);
        }
    }
};
