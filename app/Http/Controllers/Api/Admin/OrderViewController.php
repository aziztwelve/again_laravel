<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CdekOrder;
use App\Models\Order;
use App\Jobs\CreateCdekOrderJob;
use App\Services\Delivery\CdekDeliveryService;
use App\Services\Order\OrderAuthorizationService;
use App\Services\Order\OrderCreationService;
use App\Services\Order\OrderCustomFieldsService;
use App\Services\Order\OrderDiscountService;
use App\Services\Delivery\YandexDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderViewController extends Controller
{
    public function __construct(
        protected OrderAuthorizationService $orderAuthorizationService,
        protected OrderCreationService $orderCreationService,
        protected OrderCustomFieldsService $orderCustomFieldsService,
        protected OrderDiscountService $orderDiscountService,
    ) {}

    /**
     * Агрегированные данные заказа для страницы просмотра.
     * Возвращает order + все связанные сущности и виджеты (history, payments, neighbors, ...).
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        if (! $this->orderAuthorizationService->canView($user, $order)) {
            return $this->errorResponse('Доступ запрещён', 403);
        }

        $order->load([
            'items.product.images',
            'items.variant.images',
            'items.variant.optionValues.option',
            'items.color',
            'client.profile',
            'promoCode',
            'appliedDiscount',
            'appliedDiscounts',
            'promotion',
            'giftCard',
            'address',
            'deliveryMethod',
            'deliveryTarget',
            'deliveryZone',
            'deliveryDate',
            'lead',
            'history.user.roles',
            'payments',
            'tasks.status',
            'tasks.priority',
            'tasks.assignee.profile',
            'assignedUser.profile',
            'assignedUser.roles',
            'yandexOrder.statusEvents',
            'cdekOrder',
        ]);

        $summary = $this->orderCreationService->getOrderSummary($order);

        // Auto-скидки (от привязки Product↔Discount), сгруппированные по discount_id.
        // Пишем прямо в order, чтобы фронт получил их вместе с applied_discounts (ручные).
        $autoDiscounts = $this->orderDiscountService->getAutoDiscountsSummary($order);
        $order->setAttribute('auto_discounts', $autoDiscounts);

        // Дедупликация: если ручная скидка ID совпадает с auto-скидкой,
        // не показываем её отдельно (она уже учтена как auto и не стекается
        // повторно — см. OrderDiscountService::applyManualDiscountsStacked).
        // Чтобы UI не рисовал две одинаковые строки и не показывал бесполезную
        // кнопку «Снять» для уже не работающей записи.
        $autoIds = array_flip(array_map(fn ($d) => (int) $d['id'], $autoDiscounts));
        if (! empty($autoIds) && $order->relationLoaded('appliedDiscounts')) {
            $filtered = $order->appliedDiscounts->filter(
                fn ($d) => ! isset($autoIds[(int) $d->id])
            )->values();
            $order->setRelation('appliedDiscounts', $filtered);
        }

        $client = $order->client;
        $clientStats = null;
        if ($client) {
            $clientStats = [
                'orders_count' => $client->orders()->count(),
                'orders_total' => (float) $client->orders()->sum('total_amount'),
            ];
        }

        // prev/next по id (можно поменять на created_at, если нужно)
        $prevId = Order::where('id', '<', $order->id)
            ->orderByDesc('id')
            ->value('id');
        $nextId = Order::where('id', '>', $order->id)
            ->orderBy('id')
            ->value('id');

        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $viewOrderUrl = $order->view_token && $frontendUrl
            ? $frontendUrl.'/orders/'.$order->view_token
            : null;

        return $this->successResponse('Просмотр заказа', [
            'order' => $order,
            'view_order_url' => $viewOrderUrl,
            'summary' => $summary,
            'client_stats' => $clientStats,
            'history' => $this->formatHistory($order),
            'payments' => $order->payments,
            'tasks' => $this->formatTasks($order),
            'custom_fields' => $this->orderCustomFieldsService->forOrder($order),
            'viewed_products' => [],  // TODO: трекинг просмотров клиента
            'source' => [
                'utm_source' => $order->utm_source,
                'utm_medium' => $order->utm_medium,
                'utm_campaign' => $order->utm_campaign,
                'utm_content' => $order->utm_content,
                'utm_term' => $order->utm_term,
                'ip_address' => $order->ip_address,
                'user_agent' => $order->user_agent,
            ],
            'neighbors' => [
                'prev_id' => $prevId,
                'next_id' => $nextId,
            ],
            'similar_clients' => $this->getSimilarClients($client),
        ]);
    }

    /** Ручное создание/повторное получение статуса заявки Яндекс.Доставки. */
    public function createYandexDelivery(Request $request, Order $order, YandexDeliveryService $service): JsonResponse
    {
        if (! $this->orderAuthorizationService->canView($request->user(), $order)) {
            return $this->errorResponse('Доступ запрещён', 403);
        }
        if (! str_starts_with((string) $order->deliveryMethod?->code, 'yandex_')) {
            return $this->errorResponse('Для заказа не выбрана Яндекс.Доставка.', 422);
        }

        $yandexOrder = $order->yandexOrder()->firstOrCreate([], [
            'request_id' => (string) Str::uuid(),
            'delivery_type' => $order->delivery_data['delivery_type'] ?? 'courier',
            'tariff_code' => $order->delivery_data['tariff_code'] ?? null,
            'offer_id' => $order->delivery_data['offer_id'] ?? null,
            'pvz_id' => $order->delivery_data['pvz']['id'] ?? null,
            'price' => $order->delivery_data['price'] ?? null,
            'status' => 'CREATED',
            'internal_status' => 'created',
        ]);
        if ($yandexOrder->claim_id) {
            $service->sync($yandexOrder);
            return $this->successResponse('Статус заявки обновлён.', ['yandex_order' => $yandexOrder->fresh()]);
        }

        $result = $service->createOrder($order, $yandexOrder);
        if (! $result['successful']) {
            return $this->errorResponse('Не удалось создать заявку Яндекс.Доставки.', 422, $result['data']);
        }
        return $this->successResponse('Заявка Яндекс.Доставки создана.', ['yandex_order' => $yandexOrder->fresh()]);
    }

    public function cancelYandexDelivery(Request $request, Order $order, YandexDeliveryService $service): JsonResponse
    {
        if (! $this->orderAuthorizationService->canView($request->user(), $order)) {
            return $this->errorResponse('Доступ запрещён', 403);
        }
        $yandexOrder = $order->yandexOrder;
        if (! $yandexOrder?->claim_id) return $this->errorResponse('Заявка Яндекс.Доставки ещё не создана.', 422);

        // Отмена в Яндексе обрабатывается асинхронно. Пока она находится в
        // обработке, повторный POST не должен инициировать ещё одну реальную
        // отмену заявки.
        if ($yandexOrder->cancel_state === 'requested') {
            $service->sync($yandexOrder);
            return $this->successResponse('Отмена уже отправлена в Яндекс.Доставку. Статус заявки обновлён.', [
                'yandex_order' => $yandexOrder->fresh(),
            ]);
        }

        if (in_array($yandexOrder->internal_status, ['courier_assigned', 'picked_up'], true) && ! $request->boolean('force')) {
            return $this->errorResponse('Курьер уже назначен или забрал заказ. Отмена может быть платной — подтвердите действие менеджера.', 409, ['requires_manager_confirmation' => true]);
        }

        $result = $service->cancelRequest($yandexOrder->claim_id, $order->id);
        if (! $result['successful']) return $this->errorResponse('Яндекс не подтвердил отмену.', 422, $result['data']);
        $yandexOrder->update(['cancel_state' => 'requested']);
        $service->sync($yandexOrder->fresh());
        return $this->successResponse('Отмена заявки отправлена в Яндекс.Доставку.');
    }

    public function createCdekDelivery(Request $request, Order $order): JsonResponse
    {
        if (! $this->orderAuthorizationService->canView($request->user(), $order)) {
            return $this->errorResponse('Доступ запрещён', 403);
        }
        if (! str_starts_with((string) $order->deliveryMethod?->code, 'cdek_')) {
            return $this->errorResponse('Для заказа не выбрана доставка СДЭК.', 422);
        }
        if (! $order->isPaid()) {
            return $this->errorResponse('Заявка СДЭК создаётся только после успешной оплаты.', 422);
        }

        $cdekOrder = CdekOrder::firstOrCreate(['order_id' => $order->id], [
            'external_order_number' => 'order-'.$order->id,
            'delivery_type' => $order->delivery_data['delivery_type'] ?? 'courier',
            'delivery_mode' => $order->delivery_data['delivery_mode'] ?? null,
            'tariff_code' => $order->delivery_data['tariff_code'] ?? 0,
            'price' => $order->delivery_data['price'] ?? null,
            'currency' => $order->delivery_data['currency'] ?? 'RUB',
            'pvz_code' => $order->delivery_data['pvz']['code'] ?? null,
            'creation_state' => 'NEW',
        ]);
        if ($cdekOrder->cdek_uuid) {
            app(CdekDeliveryService::class)->sync($cdekOrder);
            return $this->successResponse('Статус заявки СДЭК обновлён.', ['cdek_order' => $cdekOrder->fresh()]);
        }
        if ($cdekOrder->creation_state === 'INVALID') {
            return $this->errorResponse('Заявка СДЭК отклонена. Исправьте сохранённую ошибку перед повторной отправкой.', 422, ['cdek_order' => $cdekOrder]);
        }

        CreateCdekOrderJob::dispatch($order->id);
        return $this->successResponse('Заявка СДЭК поставлена в очередь на создание.', ['cdek_order' => $cdekOrder->fresh()]);
    }

    public function cancelCdekDelivery(Request $request, Order $order, CdekDeliveryService $service): JsonResponse
    {
        if (! $this->orderAuthorizationService->canView($request->user(), $order)) {
            return $this->errorResponse('Доступ запрещён', 403);
        }
        $cdekOrder = $order->cdekOrder;
        if (! $cdekOrder?->cdek_uuid) return $this->errorResponse('Заявка СДЭК ещё не создана.', 422);

        try {
            $result = $service->cancel($cdekOrder);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        }
        if (! $result['successful']) return $this->errorResponse('СДЭК не подтвердил отмену заявки.', 422, $result['data'] ?? []);

        $service->sync($cdekOrder->fresh());
        return $this->successResponse('Запрос на отмену заявки СДЭК отправлен.');
    }

    /**
     * Ищет клиентов с совпадающими данными: телефон, email или ФИО.
     * Используется для блока «Похожие клиенты» на странице заказа.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getSimilarClients(?Client $client): array
    {
        if (! $client) {
            return [];
        }

        $profile = $client->profile;
        $email = $client->email ? mb_strtolower(trim($client->email)) : null;
        $phone = $profile?->phone;
        $firstName = $profile?->first_name ? mb_strtolower(trim($profile->first_name)) : null;
        $lastName = $profile?->last_name ? mb_strtolower(trim($profile->last_name)) : null;
        $middleName = $profile?->middle_name ? mb_strtolower(trim($profile->middle_name)) : null;

        $normalizedPhone = $phone ? preg_replace('/\D+/', '', $phone) : null;
        if ($normalizedPhone === '') {
            $normalizedPhone = null;
        }

        if (! $email && ! $normalizedPhone && (! $firstName || ! $lastName)) {
            return [];
        }

        $candidates = Client::query()
            ->with('profile')
            ->where('id', '!=', $client->id)
            ->where(function ($q) use ($email, $normalizedPhone, $firstName, $lastName) {
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($normalizedPhone) {
                    $q->orWhereHas('profile', function ($pq) use ($normalizedPhone) {
                        $pq->whereRaw(
                            "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?",
                            [$normalizedPhone],
                        );
                    });
                }
                if ($firstName && $lastName) {
                    $q->orWhereHas('profile', function ($pq) use ($firstName, $lastName) {
                        $pq->whereRaw('LOWER(TRIM(first_name)) = ?', [$firstName])
                            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$lastName]);
                    });
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $candidates->map(function (Client $c) use ($email, $normalizedPhone, $firstName, $lastName, $middleName) {
            $matchedBy = [];

            $cEmail = $c->email ? mb_strtolower(trim($c->email)) : null;
            if ($email && $cEmail === $email) {
                $matchedBy[] = 'email';
            }

            $cPhone = $c->profile?->phone;
            $cNormalizedPhone = $cPhone ? preg_replace('/\D+/', '', $cPhone) : null;
            if ($normalizedPhone && $cNormalizedPhone === $normalizedPhone) {
                $matchedBy[] = 'phone';
            }

            if ($firstName && $lastName && $c->profile) {
                $sameFirst = mb_strtolower(trim((string) $c->profile->first_name)) === $firstName;
                $sameLast = mb_strtolower(trim((string) $c->profile->last_name)) === $lastName;
                if ($sameFirst && $sameLast) {
                    if ($middleName) {
                        $cMiddle = mb_strtolower(trim((string) $c->profile->middle_name));
                        if ($cMiddle === $middleName) {
                            $matchedBy[] = 'name';
                        }
                    } else {
                        $matchedBy[] = 'name';
                    }
                }
            }

            $fullName = trim(implode(' ', array_filter([
                $c->profile?->last_name,
                $c->profile?->first_name,
                $c->profile?->middle_name,
            ])));

            return [
                'id' => $c->id,
                'email' => $c->email,
                'phone' => $cPhone,
                'full_name' => $fullName,
                'matched_by' => $matchedBy,
            ];
        })
            ->filter(fn ($row) => ! empty($row['matched_by']))
            ->values()
            ->all();
    }

    /**
     * Возвращает историю заказа в формате для фронтенда:
     *   id, action, description, created_at, user: { id, name, role }
     * Роль — name первой роли пользователя (или null, если ролей нет).
     */
    private function formatHistory(Order $order): array
    {
        return $order->history()
            ->with('user.roles')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function ($entry) {
                $user = $entry->user;
                $role = $user?->roles->first();

                return [
                    'id' => $entry->id,
                    'action' => $entry->action,
                    'description' => $entry->description ?? $entry->comment,
                    'created_at' => $entry->created_at,
                    'user' => $user ? [
                        'id' => $user->id,
                        'name' => $user->get_full_name() ?: ($user->email ?? null),
                        'role' => $role?->name,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Формирует компактный массив задач заказа для правого блока на странице просмотра.
     */
    private function formatTasks(Order $order): array
    {
        return $order->tasks
            ->sortByDesc('id')
            ->map(function ($task) {
                $assignee = $task->assignee;
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'due_date' => $task->due_date,
                    'completed_at' => $task->completed_at,
                    'is_overdue' => $task->isOverdue(),
                    'status' => $task->status,
                    'priority' => $task->priority,
                    'assignee' => $assignee ? [
                        'id' => $assignee->id,
                        'name' => data_get($assignee, 'profile.full_name') ?: $assignee->email,
                        'email' => $assignee->email,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function successResponse(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            ...$data,
        ], $status);
    }

    private function errorResponse(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            ...$extra,
        ], $status);
    }
}
