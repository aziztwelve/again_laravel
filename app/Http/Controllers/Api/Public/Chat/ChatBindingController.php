<?php

namespace App\Http\Controllers\Api\Public\Chat;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Messaging\ChatBindingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Выдача deeplink-ссылок мессенджеров с токеном привязки для виджета чата витрины.
 * См. docs/tasks/messenger-deeplink-binding.md
 */
class ChatBindingController extends Controller
{
    public function __construct(
        protected ChatBindingService $chatBindingService
    ) {}

    /**
     * GET /api/public/chat/messenger-links
     *
     * Параметры (все опциональны):
     *  - external_id: id веб-чата витрины (localStorage), для склейки с web_chat-диалогом;
     *  - order_token: view_token заказа, чтобы привязать переписку к конкретному заказу.
     *
     * Клиент определяется по Bearer-токену (sanctum), если он передан.
     */
    public function messengerLinks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_id' => 'nullable|string|max:255',
            'order_token' => 'nullable|string|max:64',
        ]);

        $client = Auth::guard('sanctum')->user();
        $clientId = $client?->id;

        $orderId = null;
        if (! empty($data['order_token'])) {
            $orderId = Order::query()
                ->where('view_token', $data['order_token'])
                ->value('id');
        }

        $token = $this->chatBindingService->createToken(
            $clientId,
            $orderId,
            $data['external_id'] ?? null
        );

        return response()->json([
            'token' => $token->token,
            'links' => $this->chatBindingService->buildLinks($token->token),
        ]);
    }
}
