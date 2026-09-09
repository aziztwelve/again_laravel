<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checkout now exposes two payment groups only. Existing rules could still
 * contain old CloudPayments and Yandex Split implementation codes, making the
 * same payment condition appear several times in the admin list.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aliases = [
            'cloudpayments_tpay' => 'card_ru',
            'cloudpayments_sbp' => 'card_ru',
            'cloudpayments_sberpay' => 'card_ru',
            'cloudpayments_mirpay' => 'card_ru',
            'yandex_pay_split' => 'yandex_pay',
        ];

        DB::table('free_shipping_rules')->orderBy('id')->each(function (object $rule) use ($aliases) {
            $methods = is_string($rule->payment_methods)
                ? json_decode($rule->payment_methods, true)
                : $rule->payment_methods;

            if (! is_array($methods)) return;

            $normalized = array_values(array_unique(array_map(
                fn ($method) => $aliases[$method] ?? $method,
                $methods,
            )));

            if ($normalized !== $methods) {
                DB::table('free_shipping_rules')->where('id', $rule->id)->update([
                    'payment_methods' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // The old codes were aliases of the current payment groups and cannot
        // be restored unambiguously.
    }
};
