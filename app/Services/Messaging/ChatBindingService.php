<?php

namespace App\Services\Messaging;

use App\Models\ChatBindingToken;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\UserProfile;
use App\Models\VKSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Привязка переписки из мессенджеров к клиенту/заказу по deeplink-токену.
 * См. docs/tasks/messenger-deeplink-binding.md
 */
class ChatBindingService
{
    /** TTL токена привязки (часы). */
    protected const TOKEN_TTL_HOURS = 72;

    /** Разрешённые каналы deeplink-привязки. */
    protected const CHANNELS = ['telegram', 'max', 'vk'];

    /**
     * Создать (или переиспользовать) токен привязки для сессии витрины.
     *
     * Токен ищется по external_id веб-чата, чтобы не плодить записи на каждый
     * запрос ссылок. При смене клиента/заказа обновляем существующую запись.
     */
    public function createToken(?int $clientId = null, ?int $orderId = null, ?string $externalId = null): ChatBindingToken
    {
        $expiresAt = now()->addHours(self::TOKEN_TTL_HOURS);

        // Переиспользуем живой токен той же сессии витрины (по external_id).
        if ($externalId) {
            $existing = ChatBindingToken::query()
                ->where('external_id', $externalId)
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if ($existing) {
                $orderWasAdded = $orderId && (int) $existing->order_id !== $orderId;
                $existing->fill([
                    'client_id' => $clientId ?? $existing->client_id,
                    'order_id' => $orderId ?? $existing->order_id,
                    'expires_at' => $expiresAt,
                ])->save();

                if ($orderWasAdded) {
                    $this->tagResolvedChannelMessages($existing);
                }

                return $existing;
            }
        }

        return ChatBindingToken::create([
            'token' => $this->generateToken(),
            'client_id' => $clientId,
            'order_id' => $orderId,
            'external_id' => $externalId,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Собрать deeplink-ссылки мессенджеров с зашитым токеном.
     *
     * @return array<string, string> ['telegram' => ..., 'max' => ..., 'vk' => ...]
     */
    public function buildLinks(string $token): array
    {
        $links = [];

        $telegramBot = config('services.messenger_deeplinks.telegram_bot');
        if ($telegramBot) {
            $links['telegram'] = 'https://t.me/'.$telegramBot.'?start='.$token;
        }

        $maxBot = config('services.messenger_deeplinks.max_bot');
        if ($maxBot) {
            $links['max'] = 'https://max.ru/'.$maxBot.'?start='.$token;
        }

        $vkTarget = $this->resolveVkScreenName();
        if ($vkTarget) {
            $links['vk'] = 'https://vk.me/'.$vkTarget.'?ref='.$token;
        }

        return $links;
    }

    /**
     * Разобрать токен из первого входящего сообщения мессенджера и привязать
     * переписку к клиенту.
     *
     * - сохраняет messenger-id (telegram/max/vk) в user_profiles найденного клиента;
     * - дозаполняет client_id у Conversation (source, external_id);
     * - возвращает клиента (или null, если токен невалиден/протух).
     *
     * Идемпотентно: повторный вызов с тем же токеном ничего не ломает.
     */
    public function resolveBinding(?string $token, string $source, string $externalId): ?Client
    {
        if (! $token || ! in_array($source, self::CHANNELS, true)) {
            return null;
        }

        $binding = ChatBindingToken::query()
            ->where('token', $token)
            ->first();

        if (! $binding) {
            Log::info('ChatBinding: token not found', ['token' => $token, 'source' => $source]);

            return null;
        }

        if ($binding->isExpired()) {
            Log::info('ChatBinding: token expired', ['token' => $token, 'source' => $source]);

            return null;
        }

        $client = $binding->client;

        // Токен может нести только order_id (гостевой заказ). Пробуем взять
        // клиента из заказа, если он там есть.
        if (! $client && $binding->order_id) {
            $client = optional($binding->order)->client;
        }

        // Сохраняем messenger-id в профиль клиента, чтобы будущие сообщения
        // (без токена) тоже находили клиента штатным матчингом.
        if ($client) {
            $this->saveMessengerId($client, $source, $externalId);
        }

        // Дозаполняем client_id у диалога этого канала.
        $this->attachClientToConversation($source, $externalId, $client?->id);

        $this->rememberResolvedChannel($binding, $source, $externalId);

        // Следующие сообщения этого мессенджера тоже относятся к заказу, пока
        // живёт deeplink-сессия. Сам Conversation не привязываем к одному
        // заказу: у клиента может быть несколько заказов в одном диалоге.
        if ($binding->order_id) {
            cache()->put(
                $this->orderBindingCacheKey($source, $externalId),
                $binding->order_id,
                $binding->expires_at
            );
        }

        // Помечаем токен использованным (не инвалидируем — клиент может писать ещё).
        if (! $binding->used_at) {
            $binding->forceFill(['used_at' => now()])->save();
        }

        return $client;
    }

    /**
     * order_id, привязанный к токену (для пометки сообщений заказом).
     */
    public function resolveOrderId(?string $token): ?int
    {
        if (! $token) {
            return null;
        }

        return ChatBindingToken::query()
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->value('order_id');
    }

    /** Заказ, активный для текущей deeplink-сессии мессенджера. */
    public function resolveBoundOrderId(string $source, string $externalId): ?int
    {
        $orderId = cache()->get($this->orderBindingCacheKey($source, $externalId));

        return is_numeric($orderId) ? (int) $orderId : null;
    }

    protected function orderBindingCacheKey(string $source, string $externalId): string
    {
        return 'chat_binding_order:'.$source.':'.$externalId;
    }

    /** Запомнить канал, реально открытый по токену, для поздней привязки заказа. */
    protected function rememberResolvedChannel(ChatBindingToken $binding, string $source, string $externalId): void
    {
        $channels = $binding->resolved_channels ?? [];
        $externalIds = $channels[$source] ?? [];
        $externalIds[] = $externalId;
        $channels[$source] = array_values(array_unique(array_map('strval', $externalIds)));

        $binding->forceFill(['resolved_channels' => $channels])->save();
    }

    /**
     * Пользователь мог написать до оформления заказа. После того как тот же
     * токен получил order_id, привязываем уже сохранённые сообщения каналов.
     */
    protected function tagResolvedChannelMessages(ChatBindingToken $binding): void
    {
        foreach ($binding->resolved_channels ?? [] as $source => $externalIds) {
            $conversationIds = Conversation::query()
                ->where('source', $source)
                ->whereIn('external_id', $externalIds)
                ->pluck('id');

            Message::query()
                ->whereIn('conversation_id', $conversationIds)
                ->orderBy('id')
                ->each(function (Message $message) use ($binding) {
                    $sourceData = $message->source_data ?? [];
                    $sourceData['order_id'] = $binding->order_id;
                    $message->update(['source_data' => $sourceData]);
                });
        }
    }

    protected function saveMessengerId(Client $client, string $source, string $externalId): void
    {
        $column = match ($source) {
            'telegram' => 'telegram_user_id',
            'max' => 'max_user_id',
            'vk' => 'vk_user_id',
            default => null,
        };

        if (! $column) {
            return;
        }

        $profile = $client->profile;
        if (! $profile) {
            $profile = new UserProfile(['client_id' => $client->id]);
        }

        // Не перетираем уже сохранённый id (может отличаться chat_id vs user_id).
        if (blank($profile->{$column})) {
            $profile->{$column} = $externalId;
            $profile->client_id = $client->id;
            $profile->save();
        }
    }

    protected function attachClientToConversation(string $source, string $externalId, ?int $clientId): void
    {
        if (! $clientId) {
            return;
        }

        Conversation::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->whereNull('client_id')
            ->update(['client_id' => $clientId]);
    }

    protected function resolveVkScreenName(): ?string
    {
        $configured = config('services.messenger_deeplinks.vk_screen_name');
        if ($configured) {
            return $configured;
        }

        try {
            $communityId = optional(VKSettings::query()->first())->community_id;
        } catch (\Throwable $e) {
            Log::warning('ChatBinding: VKSettings unavailable: '.$e->getMessage());
            $communityId = null;
        }

        return $communityId ? 'public'.$communityId : null;
    }

    protected function generateToken(): string
    {
        do {
            $token = Str::random(24);
        } while (ChatBindingToken::query()->where('token', $token)->exists());

        return $token;
    }
}
