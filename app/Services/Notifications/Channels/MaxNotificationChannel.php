<?php

namespace App\Services\Notifications\Channels;

use App\Enums\CommunicationChannel;
use App\Services\Messaging\Adapters\MaxAdapter;
use App\Services\Notifications\BaseNotificationChannel;
use Illuminate\Support\Facades\Log;

class MaxNotificationChannel extends BaseNotificationChannel
{
    protected ?MaxAdapter $adapter = null;

    public function __construct()
    {
        try {
            $this->adapter = new MaxAdapter();
        } catch (\Exception $e) {
            Log::warning('MaxAdapter not available: ' . $e->getMessage());
        }
    }

    public function send(string $recipientId, string $message, array $data = []): bool
    {
        try {
            if (!$this->adapter) {
                $this->logSend($recipientId, $this->getChannelName(), $message, false);
                return false;
            }

            $success = $this->adapter->sendMessage($recipientId, $message);

            $this->logSend($recipientId, $this->getChannelName(), $message, $success);
            return $success;
        } catch (\Exception $e) {
            $this->handleError($this->getChannelName(), $e);
            $this->logSend($recipientId, $this->getChannelName(), $message, false);
            return false;
        }
    }

    public function getChannelName(): string
    {
        return CommunicationChannel::MAX->value;
    }
}
