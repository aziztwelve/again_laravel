<?php

namespace App\Services\Notifications;

use App\Models\Order;

/**
 * Общие части текстов писем покупателю о заказе («Оплата получена», «Заказ отправлен»):
 * приветствие, адрес, срок, сумма, сборка текста и HTML из блоков.
 *
 * Текст состоит из блоков (абзацев). Блок — список строк; строка — текст либо ссылка
 * ['label' => 'Отследить доставку', 'url' => '…']: в текстовых каналах это «Отследить доставку: …»,
 * в письме — кнопка.
 */
class OrderMessageText
{
    public static function greeting(Order $order): string
    {
        $order->loadMissing('client.profile', 'address');
        $name = trim((string) ($order->client?->profile?->first_name ?: $order->address?->recipient_first_name));

        return $name !== '' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';
    }

    /** Доставка в пункт выдачи/постамат (иначе курьер). По данным заказа, а если их нет — по коду способа доставки. */
    public static function isPickup(Order $order): bool
    {
        $type = data_get($order->delivery_data, 'delivery_type');
        if (filled($type)) {
            return in_array($type, ['pickup', 'postamat'], true);
        }

        $code = (string) $order->loadMissing('deliveryMethod')->deliveryMethod?->code;

        return str_ends_with($code, 'pickup') || str_ends_with($code, 'postamat');
    }

    /** Адрес пункта выдачи или адрес доставки. Город добавляется, если его нет в самом адресе. */
    public static function address(Order $order, bool $pickup): ?string
    {
        $order->loadMissing('address');
        $delivery = is_array($order->delivery_data) ? $order->delivery_data : [];

        // На чекауте в «город» иногда записана вся строка («Жуковский, городской округ Жуковский, Московская
        // область, Россия»): берём только название города до первой запятой.
        $city = trim(explode(',', (string) ($order->address?->city ?: data_get($delivery, 'destination.city')))[0]);
        $street = trim((string) ($pickup
            ? data_get($delivery, 'pvz.address')
            : ($order->address?->address ?: data_get($delivery, 'destination.address'))));

        if ($street === '') {
            return $city !== '' ? $city : null;
        }
        if ($city === '' || mb_stripos($street, $city) !== false) {
            return $street;
        }

        return $city.', '.$street;
    }

    /** «4–5 дней» из срока тарифа (min/max в днях) или null. */
    public static function period(mixed $period): ?string
    {
        if (! is_array($period)) {
            return null;
        }

        $min = (int) ($period['min'] ?? 0);
        $max = (int) ($period['max'] ?? 0);
        if ($min <= 0 && $max <= 0) {
            return null;
        }
        $min = $min > 0 ? $min : $max;
        $max = $max > 0 ? $max : $min;
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        return ($min === $max ? (string) $max : "{$min}–{$max}").' '.self::dayWord($max);
    }

    private static function dayWord(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 14) {
            return 'дней';
        }

        return match ($n % 10) {
            1 => 'день',
            2, 3, 4 => 'дня',
            default => 'дней',
        };
    }

    /** «7 580 ₽»; копейки показываются, только если они есть. */
    public static function money(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return number_format($amount, $decimals, ',', ' ').' ₽';
    }

    /** @param array<int, array<int, string|array{label:string,url:string}>> $blocks */
    public static function plain(array $blocks): string
    {
        return implode("\n\n", array_map(
            fn (array $block) => implode("\n", array_map(
                fn ($line) => is_array($line) ? $line['label'].': '.$line['url'] : $line,
                $block,
            )),
            $blocks,
        ));
    }

    /** @param array<int, array<int, string|array{label:string,url:string}>> $blocks */
    public static function html(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $text = [];
            foreach ($block as $line) {
                if (is_array($line)) {
                    if ($text !== []) {
                        $out .= '<p>'.implode('<br>', $text).'</p>';
                        $text = [];
                    }
                    $out .= '<p><a href="'.e($line['url']).'" style="display:inline-block;padding:12px 18px;background:#292725;color:#fff;text-decoration:none">'.e($line['label']).'</a></p>';

                    continue;
                }
                $text[] = e($line);
            }
            if ($text !== []) {
                $out .= '<p>'.implode('<br>', $text).'</p>';
            }
        }

        return '<div style="font-family:Arial,sans-serif;color:#292725;line-height:1.5">'.$out.'</div>';
    }

    /** Telegram разбирает текст как HTML: служебные символы нужно экранировать. */
    public static function forChannel(string $channel, string $message): string
    {
        return $channel === 'telegram' ? htmlspecialchars($message, ENT_QUOTES, 'UTF-8') : $message;
    }
}
