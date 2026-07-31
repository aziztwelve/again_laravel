<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Review\Review;
use App\Models\Task;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function counter(Request $request)
    {
        $lastOrders = $request->query('last_updated_orders');
        $lastTasks = $request->query('last_updated_tasks');
        $lastReviews = $request->query('last_updated_reviews');
        $lastRequests = $request->query('last_updated_requests');
        $lastConversations = $request->query('last_updated_conversations');

        // Если ни одного параметра не передано — возвращаем нули (как раньше).
        if (!$lastOrders && !$lastTasks && !$lastReviews && !$lastRequests && !$lastConversations) {
            return response()->json([
                'status' => true,
                'data' => [
                    'orders' => 0,
                    'tasks' => 0,
                    'reviews' => 0,
                    'requests' => 0,
                    'conversations' => 0,
                    'orders_new_since' => 0,
                ],
                'total' => 0,
                'hasUpdates' => false,
            ]);
        }

        // Текущее общее количество заказов со статусом 'new'
        $ordersNew = Order::where('status', 'new')->count();

        // Считаем только те, что переданы (для остальных — 0)
        $orders = $lastOrders ? Order::where('created_at', '>', $lastOrders)->count() : 0;
        $tasks = $lastTasks ? Task::where('created_at', '>', $lastTasks)->count() : 0;
        $reviews = $lastReviews ? Review::where('created_at', '>', $lastReviews)->count() : 0;
        $requests = $lastRequests ? ContactRequest::where('created_at', '>', $lastRequests)->count() : 0;

        // Счётчик чатов должен отражать реальные непрочитанные диалоги, а не
        // факт входа в раздел. Счётчик конкретного диалога сбрасывается только
        // в ConversationController::show после открытия этого диалога.
        $conversations = Conversation::where('unread_messages_count', '>', 0)->count();

        $total = $orders + $tasks + $reviews + $requests + $conversations;

        return response()->json([
            'status' => true,
            'data' => [
                'orders' => $orders,
                'tasks' => $tasks,
                'reviews' => $reviews,
                'requests' => $requests,
                'conversations' => $conversations,
                'orders_new_since' => $ordersNew,
            ],
            'total' => $total,
            'hasUpdates' => $total > 0,
        ]);
    }
}
