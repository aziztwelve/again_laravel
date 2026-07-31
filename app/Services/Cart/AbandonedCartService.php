<?php

namespace App\Services\Cart;

use App\Helpers\NumberHelper;
use App\Models\Cart;
use App\Models\CartCommunication;
use App\Models\PromoCode;
use App\Services\Notifications\CustomerChannelResolver;
use App\Services\Notifications\Jobs\SendNotificationJob;

/**
 * Брошенная корзина: детект + триггерная цепочка напоминаний.
 * См. docs/tasks/abandoned-cart.md.
 */
class AbandonedCartService
{
    public function __construct(protected CustomerChannelResolver $customerChannelResolver) {}

    /**
     * Начать цепочку для активных корзин, если последняя активность была раньше
     * порога. До третьего касания статус остаётся active; abandoned_at хранит
     * момент начала цепочки. Активность = COALESCE(last_activity_at, updated_at,
     * created_at). Гостевые корзины не участвуют в сценарии.
     *
     * @return int кол-во корзин, добавленных в цепочку
     */
    public function markAbandonedCarts(): int
    {
        $minutes = (int) config('abandoned_cart.abandon_after_minutes', 30);
        $threshold = now()->subMinutes($minutes);

        $carts = Cart::query()
            ->where('status', 'active')
            ->whereNull('abandoned_at')
            ->whereNotNull('client_id')
            ->whereHas('items')
            ->whereRaw('COALESCE(last_activity_at, updated_at, created_at) <= ?', [$threshold])
            ->get();

        $count = 0;
        foreach ($carts as $cart) {
            $cart->update([
                'abandoned_at' => now(),
                'recovery_token' => $this->generateRecoveryToken(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Пройтись по брошенным корзинам и отправить готовые к отправке шаги
     * цепочки. Идемпотентно: один шаг — максимум одна запись на каждый канал
     * (UNIQUE cart_id+step+channel). Отправка не зависит от часового пояса.
     *
     * @return array{sent:int, skipped:int}
     */
    public function processChain(): array
    {
        $steps = config('abandoned_cart.steps', []);
        $now = now();
        $sent = 0;
        $skipped = 0;

        $carts = Cart::query()
            ->with(['client.profile', 'items.product', 'items.productVariant', 'items.color', 'communications'])
            ->whereIn('status', ['active', 'abandoned'])
            ->whereNotNull('client_id')
            ->whereNotNull('abandoned_at')
            ->whereHas('items')
            ->get();

        foreach ($carts as $cart) {
            foreach ($steps as $step) {
                $stepNum = (int) $step['step'];
                $offsetMinutes = array_key_exists('after_minutes', $step)
                    ? (int) $step['after_minutes']
                    : (int) ($step['after_hours'] ?? 0) * 60;
                $dueAt = $cart->abandoned_at->copy()->addMinutes($offsetMinutes);

                // Ещё не время для этого шага.
                if ($dueAt->gt($now)) {
                    continue;
                }

                $recipients = $this->resolveChannels($cart);
                if ($recipients === []) {
                    $skipped++;

                    continue;
                }

                // Промокод-стимул на последнем шаге (фаза 2), если включён.
                $promoCode = $this->maybeIssuePromo($cart, $stepNum);

                $thirdStepQueued = false;
                foreach ($recipients as $recipient) {
                    // Идемпотентность отдельна для каждого канала, поэтому
                    // повторный запуск не создаст дубль и не заблокирует остальные.
                    $comm = CartCommunication::firstOrCreate(
                        ['cart_id' => $cart->id, 'step' => $stepNum, 'channel' => $recipient['channel']],
                        ['type' => 'trigger', 'status' => 'queued']
                    );

                    if (! $comm->wasRecentlyCreated) {
                        $thirdStepQueued = $thirdStepQueued || $stepNum === 3;
                        continue;
                    }
                    $message = $this->buildMessage($cart, $stepNum, $promoCode, $comm->id);

                    SendNotificationJob::dispatch(
                        $recipient['channel'],
                        $recipient['recipient_id'],
                        $message['body'],
                        $this->notificationData($cart, $stepNum, $recipient, $message, $comm)
                    );
                    $sent++;
                    $thirdStepQueued = $thirdStepQueued || $stepNum === 3;
                }

                // Корзина считается брошенной лишь после третьего касания.
                // Если заказ был оформлен раньше, он уже имеет status=ordered и
                // не попадает в выборку выше.
                if ($stepNum === 3 && $thirdStepQueued && $cart->status === 'active') {
                    $cart->update(['status' => 'abandoned']);
                }
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Обратная совместимость для старых вызовов: возвращает первый доступный
     * канал. Новая цепочка использует resolveChannels().
     *
     * @return array{0:?string,1:?string} [channel, recipientId]
     */
    public function resolveChannel(Cart $cart): array
    {
        $recipient = $this->resolveChannels($cart)[0] ?? null;

        return $recipient
            ? [$recipient['channel'], $recipient['recipient_id']]
            : [null, null];
    }

    /** @return array<int, array{channel:string, source:string, recipient_id:string}> */
    public function resolveChannels(Cart $cart): array
    {
        return $this->customerChannelResolver->resolve($cart->client);
    }

    /**
     * Контакт под конкретный канал (для ручной отправки с явным выбором канала).
     * Профиль/аккаунт клиента. Для гостевых корзин контакты не используются.
     */
    public function recipientForChannel(Cart $cart, string $channel): ?string
    {
        if (! $cart->client) {
            return null;
        }

        return $this->customerChannelResolver->recipientFor($cart->client, $channel);
    }

    /**
     * Ручная отправка напоминания из админки (шаг F, см. docs/tasks/abandoned-cart.md).
     * Вне триггерной цепочки: пишет cart_communications с type='manual', step=NULL.
     * Доступна только для клиентских корзин и уважает троттлинг.
     *
     * @return array{ok:bool, reason?:string, communication?:CartCommunication}
     */
    public function sendManual(Cart $cart, ?string $channel = null): array
    {
        // Нельзя слать гостю, по оформленной или пустой корзине.
        if (! $cart->client_id || $cart->status === 'ordered' || ! $cart->items()->exists()) {
            return ['ok' => false, 'reason' => 'not_eligible'];
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

        // Явный канал отправляет только в него; без выбора — во все доступные.
        if ($channel) {
            $recipient = $this->recipientForChannel($cart, $channel);
            $recipients = $recipient ? [[
                'channel' => $channel,
                'source' => $channel,
                'recipient_id' => $recipient,
            ]] : [];
        } else {
            $recipients = $this->resolveChannels($cart);
        }

        if ($recipients === []) {
            return ['ok' => false, 'reason' => 'no_contact'];
        }

        $communications = [];
        foreach ($recipients as $recipient) {
            $comm = CartCommunication::create([
                'cart_id' => $cart->id,
                'channel' => $recipient['channel'],
                'step' => null,
                'type' => 'manual',
                'status' => 'queued',
            ]);
            $communications[] = $comm;
            $message = $this->buildMessage($cart, 1, null, $comm->id);

            SendNotificationJob::dispatch(
                $recipient['channel'],
                $recipient['recipient_id'],
                $message['body'],
                $this->notificationData($cart, 1, $recipient, $message, $comm)
            );
        }

        return ['ok' => true, 'communication' => $communications[0], 'communications' => $communications];
    }

    private function notificationData(Cart $cart, int $step, array $recipient, array $message, CartCommunication $comm): array
    {
        return [
            'type' => 'abandoned_cart',
            'cart_id' => $cart->id,
            'cart_step' => $step,
            'cart_communication_id' => $comm->id,
            'subject' => $message['subject'],
            'html' => $recipient['channel'] === 'email' ? $message['html'] : null,
            'mirror_conversation' => [
                'source' => $recipient['source'],
                'external_id' => $recipient['recipient_id'],
                'client_id' => $cart->client_id,
            ],
        ];
    }

    /**
     * Собрать текст сообщения для шага. Текст plain (с переносами) — корректно
     * рендерится и в email (nl2br), и в мессенджерах.
     *
     * @param  string|null  $promoCode  Промокод-стимул (шаг 2, фаза 2), если выдан.
     * @return array{subject:string, body:string}
     */
    public function buildMessage(Cart $cart, int $step, ?string $promoCode = null, ?int $communicationId = null): array
    {
        $link = $this->recoveryUrl($cart, $communicationId);
        $itemsBlock = $this->itemsBlock($cart);
        $copy = $this->funnelCopy($cart, $step);
        $body = $copy['text']."\n\n👉 {$copy['cta']}: {$link}\n\n"
            ."Состав корзины:\n{$itemsBlock}\n\nСумма: ".NumberHelper::formatRussian($cart->total, 0).' ₽';

        return [
            'subject' => $copy['subject'],
            'body' => $body,
            'html' => view('emails.abandoned-cart', [
                'cart' => $cart,
                'link' => $link,
                'total' => NumberHelper::formatRussian($cart->total, 0),
                'headline' => $copy['headline'],
                'greeting' => $this->greeting($cart),
                'messageText' => $copy['text'],
                'cta' => $copy['cta'],
                'promoCode' => null,
            ])->render(),
        ];
    }

    /** @return array{subject:string,headline:string,text:string,cta:string} */
    private function funnelCopy(Cart $cart, int $step): array
    {
        $name = $this->firstName($cart);
        $returning = $cart->client?->orders()->exists() ?? false;

        if ($returning) {
            return match ($step) {
                2 => [
                    'subject' => 'Бесплатная доставка для вас 📦 (уже в корзине)',
                    'headline' => 'Бесплатная доставка для вас',
                    'text' => "{$name}ваш выбор всё ещё ждёт оформления.\n\nКак постоянный клиент, вы получаете бесплатную доставку на этот заказ автоматически. Никаких кодов не нужно.\n\n• Быстрая отправка в знакомом вам качестве\n• Лёгкий обмен размера при соблюдении условий\n• Любимые модели разбирают быстро\n\nP.S. Корзина сохранится ещё 48 часов, но лучше не рисковать 😉",
                    'cta' => 'Оформить заказ в 1 клик',
                ],
                3 => [
                    'subject' => 'Последний шанс: ваш заказ уходит с резерва 🕊️',
                    'headline' => 'Последний шанс',
                    'text' => "{$name}мы держали комплект для вас, но через несколько часов система вернёт товары на склад.\n\nЧтобы не потерять выбранный размер и не ждать новую партию, завершите заказ сейчас.\n\nВы уже с нами — давайте сделаем эту покупку простой и приятной. Если что-то смущает, ответьте на письмо. Мы на связи.\n\nP.S. Спасибо, что выбираете AGAIN снова. Это много значит для нас 🤍",
                    'cta' => 'Завершить заказ сейчас',
                ],
                default => [
                    'subject' => ($name ?: 'Вы ').'вы снова выбрали нас ❤️',
                    'headline' => 'Спасибо, что вернулись',
                    'text' => "{$name}спасибо, что вернулись! Заметили, что в корзине осталось кое-что важное.\n\nВы уже знаете, как сидит наше бельё, и мы ценим ваш вкус. Нужен совет по сочетанию с прошлыми покупками или размеру? Пишите — поможем сразу.\n\nP.S. Для постоянных клиентов у нас приоритетная обработка.",
                    'cta' => 'Вернуться в корзину',
                ],
            };
        }

        return match ($step) {
            2 => [
                'subject' => 'Дарим 10% на первый заказ 🤍',
                'headline' => 'Скидка на первый заказ',
                'text' => "{$name}чтобы знакомство с AGAIN было идеальным, дарим скидку 10% на эту корзину.\n\nПромокод: FIRST10. Действует 48 часов.\n\n✓ Точные лекала и дышащие ткани\n✓ Простой обмен размера, если бельё не надевали\n✓ Дискретная упаковка и отправка в день заказа\n\nP.S. Скидка сгорает через 2 дня. Ваше бельё ждёт.",
                'cta' => 'Оформить заказ со скидкой',
            ],
            3 => [
                'subject' => 'Осталось 6 часов: корзина освободится ⏳',
                'headline' => 'Ваш выбор ждёт',
                'text' => "{$name}мы бережём ваш выбор, но через 6 часов товары вернутся в продажу.\n\nЗавершите заказ сейчас, чтобы гарантировать нужный размер и цвет, получить бесплатную доставку и подарок к первому заказу.\n\nP.S. Если передумали — просто проигнорируйте. Мы не будем навязываться 😊",
                'cta' => 'Завершить заказ сейчас',
            ],
            default => [
                'subject' => 'Ваш выбор ждёт ✨ (и мы поможем с размером)',
                'headline' => 'Ваш выбор ждёт',
                'text' => "{$name}заметили, что вы положили в корзину кое-что особенное. Выбор белья — это про комфорт и уверенность, и иногда нужно время, чтобы решиться.\n\nВаш комплект уже отложен. Если сомневаетесь в размере, ткани или посадке — просто ответьте на это письмо. Наши стилисты подскажут за 2 минуты.\n\nP.S. Для первого заказа у нас действует бесплатная доставка.",
                'cta' => 'Вернуться в корзину',
            ],
        };
    }

    private function firstName(Cart $cart): string
    {
        $firstName = trim((string) ($cart->client?->profile?->first_name ?? ''));

        return $firstName !== '' ? $firstName.', ' : '';
    }

    private function greeting(Cart $cart): string
    {
        return $this->firstName($cart) ?: 'Здравствуйте!';
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
            // Промокод доступен ко всем товарам; abandoned-cart сценарий
            // запускается только для клиентских корзин.
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

    protected function recoveryUrl(Cart $cart, ?int $communicationId = null): string
    {
        $base = rtrim((string) config('abandoned_cart.recovery_url'), '/');

        // Токен обязателен: без него ссылка выходит вида «{base}/» и упирается в
        // 404 на витрине (маршрут существует только как /cart/recovery/{token}).
        // markAbandoned() уже выдаёт токен, но ручная отправка (sendManual) идёт
        // по active-корзине без токена — генерируем лениво и сохраняем.
        if (empty($cart->recovery_token)) {
            $cart->recovery_token = $this->generateRecoveryToken();
            $cart->save();
        }

        return $base.'/'.$cart->recovery_token.($communicationId ? '?communication='.$communicationId : '');
    }

    protected function generateRecoveryToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (Cart::where('recovery_token', $token)->exists());

        return $token;
    }
}
