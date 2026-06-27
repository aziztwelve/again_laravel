<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Models\ProductRestockSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestockSubscriptionController extends Controller
{
    /**
     * Список заявок «Сообщить о поступлении» с фильтрами и пагинацией.
     * GET /api/admin/restock-subscriptions
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductRestockSubscription::query()
            ->with(['product:id,name,slug,stock_quantity', 'variant:id,name', 'client.profile']);

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('phone', 'like', "%$s%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $list = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $list->items(),
            'meta' => PaginationHelper::format($list),
        ]);
    }

    /**
     * Счётчик ожидающих заявок (всего или по товару).
     * GET /api/admin/restock-subscriptions/count
     */
    public function count(Request $request): JsonResponse
    {
        $query = ProductRestockSubscription::query()->pending();

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        return response()->json([
            'count' => $query->count(),
        ]);
    }

    public function destroy(ProductRestockSubscription $restock_subscription): JsonResponse
    {
        $restock_subscription->delete();

        return response()->json(null, 204);
    }
}
