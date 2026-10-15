<?php

namespace App\Services\Cart;

use App\Models\Setting;

class CartRecoverySettings
{
    public const CHANNELS = ['email', 'telegram', 'max', 'vk'];

    public function read(): array
    {
        $stored = Setting::get('abandoned_cart_settings', []);

        return [
            'enabled' => (bool) ($stored['enabled'] ?? true),
            'channels' => array_replace(array_fill_keys(self::CHANNELS, true), $stored['channels'] ?? []),
            'templates' => $stored['templates'] ?? [],
        ];
    }

    public function save(array $settings): void
    {
        Setting::set('abandoned_cart_settings', $settings, 'abandoned_cart');
    }

    public function template(string $audience, int $step, string $channel): ?array
    {
        foreach ($this->read()['templates'] as $template) {
            if ($template['audience'] === $audience && (int) $template['step'] === $step && $template['channel'] === $channel) {
                return $template;
            }
        }

        return null;
    }
}
