<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Models\ProductRestockSubscription;
use App\Models\Color;
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

        // Основной экран «Скоро в продаже» — очередь ожидающих заявок.
        // Уведомлённые не теряются: их можно открыть явным фильтром статуса.
        if (! $request->has('status')) {
            $query->pending();
        } elseif ($request->filled('status')) {
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
            'data' => $this->attachColors($list->items()),
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

    public function show(ProductRestockSubscription $restock_subscription): JsonResponse
    {
        return response()->json([
            'data' => $this->cardData($restock_subscription),
        ]);
    }

    public function update(Request $request, ProductRestockSubscription $restock_subscription): JsonResponse
    {
        $data = $request->validate([
            'manager_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $comment = isset($data['manager_comment']) ? trim($data['manager_comment']) : null;
        if ($restock_subscription->manager_comment !== $comment) {
            $restock_subscription->update(['manager_comment' => $comment ?: null]);
            $restock_subscription->histories()->create([
                'user_id' => $request->user()?->id,
                'action' => 'manager_comment_updated',
                'description' => $comment ? 'Менеджер обновил комментарий' : 'Менеджер удалил комментарий',
            ]);
        }

        return response()->json([
            'message' => 'Комментарий менеджера сохранён',
            'data' => $this->cardData($restock_subscription->fresh()),
        ]);
    }

    public function destroy(ProductRestockSubscription $restock_subscription): JsonResponse
    {
        $restock_subscription->delete();

        return response()->json(null, 204);
    }

    private function cardData(ProductRestockSubscription $subscription): array
    {
        $subscription->load([
            'product:id,name,slug,stock_quantity',
            'variant:id,name',
            'client.profile',
            'histories.user.profile',
        ]);
        $this->attachColors([$subscription]);

        return [
            ...$subscription->toArray(),
            'history' => $subscription->histories
                ->sortByDesc('id')
                ->values()
                ->map(fn ($entry) => [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'description' => $entry->description,
                    'created_at' => $entry->created_at,
                    'user' => $entry->user ? [
                        'id' => $entry->user->id,
                        'name' => $entry->user->get_full_name() ?: $entry->user->email,
                    ] : null,
                ]),
        ];
    }

    /** Добавляет названия цветов к заявкам, не создавая отдельную pivot-таблицу. */
    private function attachColors(iterable $subscriptions): array
    {
        $subscriptions = is_array($subscriptions) ? $subscriptions : iterator_to_array($subscriptions);
        $ids = collect($subscriptions)
            ->flatMap(fn ($subscription) => $subscription->color_ids ?? [])
            ->unique()
            ->values();
        $colors = Color::query()->whereIn('id', $ids)->get(['id', 'name', 'code'])->keyBy('id');

        foreach ($subscriptions as $subscription) {
            $subscription->setAttribute('colors', collect($subscription->color_ids ?? [])
                ->map(fn ($id) => $colors->get($id))
                ->filter()
                ->values());
        }

        return $subscriptions;
    }
}
