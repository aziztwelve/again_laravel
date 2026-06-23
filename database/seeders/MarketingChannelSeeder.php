<?php

namespace Database\Seeders;

use App\Models\MarketingChannel;
use Illuminate\Database\Seeder;

class MarketingChannelSeeder extends Seeder
{
    /**
     * Дефолтные каналы маркетинга. Все системные (is_system=true) —
     * удалить их через CRUD нельзя (см. docs/tasks/utm-tracking.md, решение #11).
     */
    public function run(): void
    {
        $channels = [
            ['name' => 'Instagram', 'code' => 'ig'],
            ['name' => 'Telegram', 'code' => 'tg'],
            ['name' => 'VK', 'code' => 'vk'],
            ['name' => 'Email', 'code' => 'email'],
            ['name' => 'WhatsApp', 'code' => 'wa'],
            ['name' => 'MAX', 'code' => 'max'],
        ];

        foreach ($channels as $index => $channel) {
            MarketingChannel::updateOrCreate(
                ['code' => $channel['code']],
                [
                    'name' => $channel['name'],
                    'is_system' => true,
                    'is_active' => true,
                    'sort' => $index,
                ]
            );
        }
    }
}
