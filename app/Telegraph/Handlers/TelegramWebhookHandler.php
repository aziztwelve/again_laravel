<?php

namespace App\Telegraph\Handlers;

use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Messaging\ChatBindingService;
use App\Services\Messaging\ConversationService;
use App\Services\Telegram\TelegramService;
use App\Traits\ClientControllerTrait;
use DefStudio\Telegraph\Enums\ChatActions;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Stringable;
use Illuminate\Support\Facades\Log;
use App\Models\Message;

class TelegramWebhookHandler extends WebhookHandler
{

    use ClientControllerTrait;


    private TelegramService $telegramService;

    private ChatBindingService $chatBindingService;

    public function __construct(TelegramService $telegramService, ChatBindingService $chatBindingService)
    {
        $this->telegramService = $telegramService;
        $this->chatBindingService = $chatBindingService;
    }


    /**
     * Обработка /start [<TOKEN>].
     *
     * Если пришёл deeplink-токен (start=<TOKEN>), привязываем переписку.
     * Бот не ведёт сценарий авторизации и не отвечает шаблонными сообщениями:
     * команда, как и любой другой входящий текст, сохраняется в диалоге.
     */
    public function start(?string $parameter = null)
    {
        $telegramId = (string) $this->getUserId();
        if ($parameter) {
            $this->chatBindingService->resolveBinding($parameter, 'telegram', $telegramId);
        }

        $this->forwardIncomingMessage((string) ($this->message?->text() ?? '/start'));
    }


    private function user_profile($await_email = false): UserProfile|null
    {
        $telegramId = $this->getUserId();
        $chat = $this->getChat();

        $client_profile = UserProfile::where('telegram_user_id', $telegramId)->first();

        if (!$client_profile) {
            // save state and wait email
            if ($await_email) {
                $chat->message("Привет! Пожалуйста, отправьте свой email, чтобы мы могли найти ваш аккаунт.")->send();
                cache()->put("awaiting_email_$telegramId", true, now()->addMinutes(10));
            }
            return null;
        }
        return $client_profile;
    }

    public function handleUnknownCommand(Stringable $text): void
    {
        $this->forwardIncomingMessage((string) $text);
    }

    public function handleChatMessage(Stringable $text): void
    {
        $this->forwardIncomingMessage((string) $text);
    }

    /** Сохранить входящее сообщение без автоматического ответа бота. */
    private function forwardIncomingMessage(string $content): void
    {
        $telegramId = $this->getUserId();
        $client_profile = UserProfile::where('telegram_user_id', $telegramId)->first();
        cache()->forget("awaiting_email_$telegramId");

        $requestData = $this->request->input('message', []);
        $botToken = $this->bot->token;

        $this->telegramService->findOrCreateConversationAndSendMessage(
            $telegramId,
            $client_profile,
            $content,
            $requestData,
            $botToken,
            $this->chatBindingService->resolveBoundOrderId('telegram', (string) $telegramId)
        );

    }

    public function orders()
    {
        $this->forwardIncomingMessage((string) ($this->message?->text() ?? '/orders'));
    }

    public function send_order_data(
        Client                         $client,
        \DefStudio\Telegraph\Telegraph $chat
    )
    {
        $find_pending_orders_ids = Order
            ::whereIn('status', [OrderStatus::PROCESSING, OrderStatus::NEW])
            ->whereNull("deleted_at")
            // once you found by clients, it's enought
            // because second time you request with ids
            ->where('client_id', $client->id)
            ->pluck('id')->toArray();

        if (count($find_pending_orders_ids) <= 0) {
            $this->reply("На данный момент нет ожидающих заказов.");
            return;
        }

        $find_pending_orders = Order
            ::whereIn('id', $find_pending_orders_ids)
            ->with(['payments', 'items'])
            ->get();

        foreach ($find_pending_orders as $order) {
            $message = "*Спасибо за ваш заказ!*🎉\n";
            $message .= "Вы оформили заказ №{$order->id} от {$order->created_at->format('d.m.Y в H:i')} на сумму {$order->total_amount}.\n\n";

            $message .= "Состав заказа:\n";
            foreach ($order->items as $item) {
                if ($item->productVariant) {
                    $message .= "- {$item->productVariant->name} x {$item->quantity}\n";
                } else {
                    $message .= "- {$item->product->name} x {$item->quantity}\n";
                }
            }

            $message .= "\n";

            $message .= "Мы уже начали обработку. Ожидайте, пожалуйста, подтверждение.\n";
            $message .= "С уважением, команда *Again*!\n\n";

            $chat->message($message)->send();

            foreach ($order->payments as $payment) {
                $payment_message = "*Спасибо за ваш платёж!*🎉\n";
                $payment_message .= "Мы успешно получили ваш платёж №{$payment->id} от {$payment->created_at->format('d.m.Y в H:i')} на сумму {$payment->amount}.\n";
                $payment_message .= "Если у вас есть вопросы, пожалуйста, свяжитесь с нашей поддержкой.\n";
                $payment_message .= "С уважением, команда *Again*!\n\n";
                $chat->message($payment_message)->send();
            }
        }
    }

    public function help()
    {
        $this->forwardIncomingMessage((string) ($this->message?->text() ?? '/help'));
    }


    private function reset()
    {
        $telegramId = $this->getUserId();
        cache()->forget("awaiting_email_$telegramId");
    }

    public function cancel()
    {
        $this->forwardIncomingMessage((string) ($this->message?->text() ?? '/cancel'));
    }

    protected function getChat(): \DefStudio\Telegraph\Telegraph
    {
        if ($this->message?->chat()?->id()) {
            return Telegraph::bot($this->bot)->chat($this->message->chat()->id());
        }

        if ($this->callbackQuery?->message()?->chat()?->id()) {
            return Telegraph::bot($this->bot)->chat($this->callbackQuery->message()?->chat()->id());
        }

        throw new \RuntimeException("Не удалось определить chat ID для ответа.");
    }

    protected function getUserId(): int
    {
        if ($this->message?->from()?->id()) {
            return $this->message->from()->id();
        }

        if ($this->callbackQuery?->from()?->id()) {
            return $this->callbackQuery->from()->id();
        }

        throw new \RuntimeException("Не удалось определить user ID.");
    }

}
