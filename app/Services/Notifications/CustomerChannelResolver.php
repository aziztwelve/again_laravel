<?php

namespace App\Services\Notifications;

use App\Enums\CommunicationChannel;
use App\Models\Client;

/**
 * Resolves the transactional channels that are actually available to a client.
 *
 * SMS and WhatsApp are deliberately absent: transactional notifications use
 * only Email, Telegram, MAX and VK.
 */
class CustomerChannelResolver
{
    /**
     * @return array<int, array{channel:string, source:string, recipient_id:string}>
     */
    public function resolve(?Client $client, ?string $fallbackEmail = null): array
    {
        $client?->loadMissing('profile');
        $profile = $client?->profile;

        $contacts = [
            CommunicationChannel::EMAIL->value => $client?->email ?: $fallbackEmail,
            CommunicationChannel::TELEGRAM->value => $profile?->telegram_chat_id ?: $profile?->telegram_user_id,
            CommunicationChannel::MAX->value => $profile?->max_user_id,
            CommunicationChannel::VK->value => $profile?->vk_user_id,
        ];

        $recipients = [];
        foreach ($contacts as $channel => $recipientId) {
            if (empty($recipientId)) {
                continue;
            }

            $recipients[] = [
                'channel' => $channel,
                'source' => $channel,
                'recipient_id' => (string) $recipientId,
            ];
        }

        return $recipients;
    }

    public function recipientFor(?Client $client, string $channel, ?string $fallbackEmail = null): ?string
    {
        foreach ($this->resolve($client, $fallbackEmail) as $recipient) {
            if ($recipient['channel'] === $channel) {
                return $recipient['recipient_id'];
            }
        }

        return null;
    }
}
