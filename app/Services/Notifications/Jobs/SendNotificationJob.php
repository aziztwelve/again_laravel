<?php

namespace App\Services\Notifications\Jobs;

use App\Models\CartCommunication;
use App\Services\Notifications\NotificationConversationMirrorService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $channel,
        protected string $recipientId,
        protected string $message,
        protected array $data = [],
    ) {
        //
    }

    public function handle(
        NotificationService $notificationService,
        NotificationConversationMirrorService $mirrorService
    ): void {
        try {
            $success = $notificationService->sendViaChannel(
                $this->channel,
                $this->recipientId,
                $this->message,
                $this->data
            );

            if (! $success) {
                Log::warning("SendNotificationJob: Failed to send via {$this->channel}", [
                    'recipient_id' => $this->recipientId,
                ]);
                $this->updateCartCommunication('failed');
            } else {
                $mirrorService->mirror($this->data, $this->message);
                $this->updateCartCommunication('sent');
            }

        } catch (\Exception $e) {
            Log::error('SendNotificationJob: Exception', [
                'channel' => $this->channel,
                'error' => $e->getMessage(),
            ]);

            // Повторить попытку если не удалось
            $this->release(60); // через 1 минуту
        }
    }

    public function failed(\Exception $exception): void
    {
        $this->updateCartCommunication('failed');
        Log::error('SendNotificationJob: Job failed permanently', [
            'channel' => $this->channel,
            'error' => $exception->getMessage(),
        ]);
    }

    private function updateCartCommunication(string $status): void
    {
        $id = $this->data['cart_communication_id'] ?? null;
        if (! $id) {
            return;
        }

        CartCommunication::whereKey($id)->update([
            'status' => $status,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
