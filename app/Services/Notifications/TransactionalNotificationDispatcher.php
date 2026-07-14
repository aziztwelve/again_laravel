<?php

namespace App\Services\Notifications;

use App\Models\NotificationDispatch;
use App\Services\Notifications\Jobs\SendNotificationJob;

class TransactionalNotificationDispatcher
{
    public function dispatch(string $eventKey, string $entityType, int $entityId, array $recipient, string $message, array $data = []): bool
    {
        $dispatch = NotificationDispatch::firstOrCreate(
            [
                'event_key' => $eventKey,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'channel' => $recipient['channel'],
                'recipient_id' => $recipient['recipient_id'],
            ],
            ['status' => 'queued']
        );

        if (! $dispatch->wasRecentlyCreated) {
            return false;
        }

        SendNotificationJob::dispatch(
            $recipient['channel'],
            $recipient['recipient_id'],
            $message,
            array_merge($data, ['notification_dispatch_id' => $dispatch->id])
        );

        return true;
    }
}
