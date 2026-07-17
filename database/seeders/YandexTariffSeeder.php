<?php

namespace Database\Seeders;

use App\Models\YandexTariff;
use Illuminate\Database\Seeder;

class YandexTariffSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'express', 'title' => 'Экспресс (1–2 ч)', 'taxi_class' => 'express', 'sort' => 10],
            ['code' => 'next_day', 'title' => 'Завтра', 'taxi_class' => 'courier', 'sort' => 20],
            ['code' => 'scheduled', 'title' => 'По расписанию', 'taxi_class' => 'courier', 'sort' => 30],
            ['code' => 'cargo', 'title' => 'Грузовой', 'taxi_class' => 'cargo', 'sort' => 40],
        ] as $tariff) {
            YandexTariff::updateOrCreate(['code' => $tariff['code']], $tariff + ['is_active' => true]);
        }
    }
}
