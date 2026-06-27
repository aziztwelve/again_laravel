<?php

namespace App\Services\Cart;

use App\Helpers\NumberHelper;
use App\Models\Cart;
use App\Models\CartCommunication;
use App\Models\PromoCode;
use App\Services\Notifications\Jobs\SendNotificationJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Брошенная корзина: детект + триггерная цепочка напоминаний.
 * См. docs/tasks/abandoned-cart.md.
 */
class AbandonedCartService
{
    /**
     * Пометить активные корзины (status = 'active') брошенными, если последняя
     * активность была раньше порога. Активность = COALESCE(last_activity_at,
     * updated_at, created_at). Помечаем и клиентов, и гостей (для аналитики);
     * фильтрация «есть кому/с согласием слать» выполняется в processChain.
     *
     * @return int кол-во помеченных корзин
     */
    public function markAbandonedCarts(): int
    {
        $hours = (int) config('abandoned_cart.abandon_after_hours', 24);
        $threshold = now()->subHours($hours);

        $carts = Cart::query()
            ->where('status', 'active')
            ->whereHas('items')
            ->whereRaw('COALESCE(last_activity_at, updated_at, created_at) <= ?', [$threshold])
            ->get();

        $count = 0;
        foreach ($carts as $cart) {
            $cart->update([
                'status' => 'abandoned',
                'abandoned_at' => now(),
                'recovery_token' => $this->generateRecoveryToken(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Пройтись по брошенным корзинам и отправить готовые к отправке шаги
     * цепочки. Идемпотентно: один шаг — максимум одна запись cart_communications
     * (UNIQUE cart_id+step). Уважает окно отправки.
     *
     * @return array{sent:int, skipped:int, window:bool}
     */
    public function processChain(): array
    {
        if (! $this->withinSendWindow(now())) {
            return ['sent' => 0, 'skipped' => 0, 'window' => false];
        }

        $steps = config('abandoned_cart.steps', []);
        $now = now();
        $sent = 0;
        $skipped = 0;

        $carts = Cart::query()
            ->with(['client.profile', 'items.product', 'items.productVariant', 'items.color', 'communications'])
            ->where('status', 'abandoned')
            ->whereNotNull('abandoned_at')
            ->whereHas('items')
            ->get();

        foreach ($carts as $cart) {
            foreach ($steps as $step) {
                $stepNum = (int) $step['step'];
                $dueAt = $cart->abandoned_at->copy()->addHours((int) $step['after_hours']);

                // Ещё не время для этого шага.
                if ($dueAt->gt($now)) {
                    continue;
                }

                // Уже отправляли (или в очереди) этот шаг.
                if ($cart->communications->firstWhere('step', $stepNum)) {
                    continue;
                }

                [$channel, $recipient] = $this->resolveChannel($cart);

                // Идемпотентность: создаём запись до диспатча. UNIQUE(cart_id, step)
                // защищает от гонки параллельных запусков команды.
                $comm = CartCommunication::firstOrCreate(
                    ['cart_id' => $cart->id, 'step' => $stepNum],
                    [
                        'channel' => $channel ?? 'none',
                        'type' => 'trigger',
                        'status' => 'queued',
                    ]
                );

                if (! $comm->wasRecentlyCreated) {
                    continue; // кто-то уже застолбил этот шаг
                }

                if (! $channel || ! $recipient) {
                    $comm->update(['status' => 'failed']);
                    $skipped++;
                    continue;
                }

                // Промокод-стимул на последнем шаге (фаза 2), если включён.
                $promoCode = $this->maybeIssuePromo($cart, $stepNum);

                $message = $this->buildMessage($cart, $stepNum, $promoCode);

                SendNotificationJob::dispatch(
                    $channel,
                    (string) $recipient,
                    $message['body'],
                    ['subject' => $message['subject']]
                );

                $comm->update(['status' => 'sent', 'sent_at' => now()]);
                $sent++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'window' => true];
    }

    /**
     * Выбрать первый доступный канал по приоритету и контакт под него.
     * Источник контактов: профиль клиента, иначе — контакты из самой корзины
     * (гостевой email/phone). Для гостя отправка разрешена только при наличии
     * явного согласия (marketing_consent). Сервис уведомлений не должен знать,
     * авторизован ли владелец. См. docs/tasks/universal-cart.md.
     *
     * @return array{0:?string,1:?string} [channel, recipientId]
     */
    public function resolveChannel(Cart $cart): array
    {
        $client = $cart->client;
        $profile = $client?->profile;

        // Гость без явного согласия на рассылку — не шлём (анти-спам, 152-ФЗ).
        if (! $client && ! $cart->marketing_consent) {
            return [null, null];
        }

        foreach (config('abandoned_cart.channel_priority', []) as $channel) {
            $recipient = match ($channel) {
                'telegram' => $profile?->telegram_chat_id ?: $profile?->telegram_user_id,
                'email' => $client?->email ?: $cart->email,
                'whatsapp' => $profile?->phone ?: $cart->phone,
                'vk' => $profile?->vk_user_id,
                default => null,
            };

            if (! empty($recipient)) {
                return [$channel, (string) $recipient];
            }
        }

        return [null, null];
    }

    /**
     * Контакт под конкретный канал (для ручной отправки с явным выбором канала).
     * Профиль клиента имеет приоритет, иначе — контакты из корзины (гость).
     */
    public function recipientForChannel(Cart $cart, string $channel): ?string
    {
        $profile = $cart->client?->profile;

        $recipient = match ($channel) {
            'telegram' => $profile?->telegram_chat_id ?: $profile?->telegram_user_id,
            'email' => $cart->client?->email ?: $cart->email,
            'whatsapp' => $profile?->phone ?: $cart->phone,
            'vk' => $profile?->vk_user_id,
            default => null,
        };

        return ! empty($recipient) ? (string) $recipient : null;
    }

    /**
     * Ручная отправка напоминания из админки (шаг F, см. docs/tasks/abandoned-cart.md).
     * Вне триггерной цепочки: пишет cart_communications с type='manual', step=NULL.
     * Уважает согласие гостя (marketing_consent) и троттлинг.
     *
     * @return array{ok:bool, reason?:string, communication?:CartCommunication}
     */
    public function sendManual(Cart $cart, ?string $channel = null): array
    {
        // Нельзя слать по оформленной или пустой корзине.
        if ($cart->status === 'ordered' || ! $cart->items()->exists()) {
            return ['ok' => false, 'reason' => 'not_eligible'];
        }

        // Гость без явного согласия — не шлём (анти-спам, 152-ФЗ).
        if (! $cart->client_id && ! $cart->marketing_consent) {
            return ['ok' => false, 'reason' => 'no_consent'];
        }

        // Троттлинг: не чаще, чем раз в N минут на корзину.
        $throttle = (int) config('abandoned_cart.manual_throttle_minutes', 10);
        $recent = CartCommunication::where('cart_id', $cart->id)
            ->where('type', 'manual')
            ->where('created_at', '>=', now()->subMinutes($throttle))
            ->exists();

        if ($recent) {
            return ['ok' => false, 'reason' => 'throttled'];
        }

        // Канал: явный из запроса или приоритетный.
        if ($channel) {
            $recipient = $this->recipientForChannel($cart, $channel);
        } else {
            [$channel, $recipient] = $this->resolveChannel($cart);
        }

        if (! $channel || ! $recipient) {
            return ['ok' => false, 'reason' => 'no_contact'];
        }

        $message = $this->buildMessage($cart, 1);

        SendNotificationJob::dispatch(
            $channel,
            (string) $recipient,
            $message['body'],
            ['subject' => $message['subject']]
        );

        $comm = CartCommunication::create([
            'cart_id' => $cart->id,
            'channel' => $channel,
            'step' => null,
            'type' => 'manual',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return ['ok' => true, 'communication' => $comm];
    }

    /**
     * Собрать текст сообщения для шага. Текст plain (с переносами) — корректно
     * рендерится и в email (nl2br), и в мессенджерах.
     *
     * @param  string|null  $promoCode  Промокод-стимул (шаг 2, фаза 2), если выдан.
     * @return array{subject:string, body:string}
     */
    public function buildMessage(Cart $cart, int $step, ?string $promoCode = null): array
    {
        $link = $this->recoveryUrl($cart);
        $itemsBlock = $this->itemsBlock($cart);
        $total = NumberHelper::formatRussian($cart->total, 0);

        // Копирайт по шагам. Шаг 1 (24ч) — нейтральное напоминание; шаг 2 (72ч) —
        // повтор с более тёплым тоном и, при наличии, промокодом-стимулом.
        if ($step >= 2) {
            $intro = 'Вы так и не завершили заказ — товары всё ещё ждут вас в корзине. '
                .'Возвращайтесь, пока они в наличии:';
            $subject = 'Возвращайтесь — товары ждут в корзине';
        } else {
            $intro = 'В вашей корзине остались товары.';
            $subject = 'В вашей корзине остались товары';
        }

        $body = $intro."\n"
            ."Завершить оформление заказа можно прямо сейчас на сайте: {$link}\n\n"
            ."Состав заказа:\n"
            .$itemsBlock."\n\n"
            ."Оформить заказ: {$link}\n\n"
            ."Сумма: {$total} ₽";

        if ($promoCode) {
            $body .= "\n\n".$this->promoBlock($promoCode);
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * Текстовый блок с промокодом-стимулом для письма/сообщения.
     */
    protected function promoBlock(string $code): string
    {
        $cfg = config('abandoned_cart.promo', []);
        $amount = $cfg['discount_amount'] ?? 0;
        $type = $cfg['discount_type'] ?? 'percentage';
        $ttlDays = (int) ($cfg['ttl_days'] ?? 7);

        $discountText = $type === 'fixed'
            ? NumberHelper::formatRussian($amount, 0).' ₽'
            : rtrim(rtrim((string) $amount, '0'), '.').'%';

        return "Специально для вас — скидка {$discountText} по промокоду: {$code}\n"
            ."Промокод действует {$ttlDays} дн. Введите его в корзине при оформлении.";
    }

    /**
     * Выдать корзине персональный промокод на нужном шаге (фаза 2), если фича
     * включена. Идемпотентно: код генерируется один раз и сохраняется в
     * cart.recovery_promo_code. См. docs/tasks/abandoned-cart.md.
     */
    protected function maybeIssuePromo(Cart $cart, int $step): ?string
    {
        $cfg = config('abandoned_cart.promo', []);

        if (! ($cfg['enabled'] ?? false) || (int) ($cfg['step'] ?? 2) !== $step) {
            return null;
        }

        // Уже выдавали этой корзине — переиспользуем тот же код.
        if (! empty($cart->recovery_promo_code)) {
            return $cart->recovery_promo_code;
        }

        $code = $this->generatePromoCode((string) ($cfg['code_prefix'] ?? 'CART'));

        PromoCode::create([
            'code' => $code,
            'description' => 'Брошенная корзина — стимул на шаге '.$step,
            'discount_amount' => $cfg['discount_amount'] ?? 10,
            'discount_type' => $cfg['discount_type'] ?? 'percentage',
            // STACK — добавляется поверх возможных скидок товара (мягкий стимул).
            'discount_behavior' => PromoCode::DISCOUNT_BEHAVIOR_STACK,
            'starts_at' => now(),
            'expires_at' => now()->addDays((int) ($cfg['ttl_days'] ?? 7)),
            'max_uses' => 1,
            'times_used' => 0,
            'is_active' => true,
            // Доступен всем (в т.ч. гостю без client_id) и ко всем товарам.
            'applies_to_all_products' => true,
            'applies_to_all_clients' => true,
            'type' => 'all',
        ]);

        $cart->update(['recovery_promo_code' => $code]);

        return $code;
    }

    /**
     * Уникальный человекочитаемый код вида PREFIX-XXXXXX.
     */
    protected function generatePromoCode(string $prefix): string
    {
        do {
            $code = strtoupper($prefix.'-'.substr(bin2hex(random_bytes(4)), 0, 6));
        } while (PromoCode::where('code', $code)->exists());

        return $code;
    }

    /**
     * Список позиций в виде строк «- Название (вариант / цвет). 1 990 ₽ x 1 шт».
     */
    protected function itemsBlock(Cart $cart): string
    {
        $lines = [];

        foreach ($cart->items as $item) {
            $name = $item->productVariant?->name ?: $item->product?->name ?: 'Товар';

            $colorName = $item->color?->name;
            if ($colorName) {
                $name .= " ({$colorName})";
            }

            $price = NumberHelper::formatRussian($item->price, 0);
            $qty = (int) $item->quantity;

            $lines[] = "- {$name}. {$price} ₽ x {$qty} шт";
        }

        return implode("\n", $lines);
    }

    protected function recoveryUrl(Cart $cart): string
    {
        $base = rtrim((string) config('abandoned_cart.recovery_url'), '/');

        return $base.'/'.$cart->recovery_token;
    }

    protected function withinSendWindow(Carbon $now): bool
    {
        $start = (int) config('abandoned_cart.send_window.start_hour', 10);
        $end = (int) config('abandoned_cart.send_window.end_hour', 21);
        $hour = (int) $now->format('G');

        return $hour >= $start && $hour < $end;
    }

    protected function generateRecoveryToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (Cart::where('recovery_token', $token)->exists());

        return $token;
    }
}
