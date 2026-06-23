<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Laravel\Reverb\Loggers\Log;

class OrderFilterService
{
    /**
     * Применить фильтры к запросу заказов
     */
    public function applyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('status')) {
            $query = $this->filterByStatus($query, $request->status);
        }

        if ($request->filled('payment_status')) {
            $query = $this->filterByPaymentStatus($query, $request->payment_status);
        }

        if ($request->filled('date_from')) {
            $query = $this->filterByDateFrom($query, $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query = $this->filterByDateTo($query, $request->date_to);
        }

        if ($request->filled('client_id')) {
            $query = $this->filterByClientId($query, $request->client_id);
        }

        if ($request->filled('promo_code')) {
            $query = $this->filterByPromoCode($query, $request->promo_code);
        }

        if ($request->filled('city')) {
            $query = $this->filterByCity($query, $request->city);
        }

        if ($request->filled('country_code')) {
            $query = $this->filterByCountry($query, $request->country_code);
        }

        if ($request->filled('phone')) {
            $query = $this->filterByPhone($query, $request->phone);
        }

        if ($request->filled('min_amount')) {
            $query = $this->filterByMinAmount($query, $request->min_amount);
        }

        if ($request->filled('max_amount')) {
            $query = $this->filterByMaxAmount($query, $request->max_amount);
        }

        if ($request->filled('search')) {
            $query = $this->search($query, $request->search);
        }

        // Поиск по email клиента (точный like)
        if ($request->filled('email')) {
            $query = $this->filterByEmail($query, $request->email);
        }

        // Поиск по номеру заказа (order_number или id)
        if ($request->filled('order_number')) {
            $query = $this->filterByOrderNumber($query, $request->order_number);
        }

        // Узкий поиск по ФИО получателя — только по полям order_addresses.recipient_*.
        // В отличие от общего `search`, не «цепляет» имя/фамилию клиента из его профиля.
        if ($request->filled('recipient_search')) {
            $query = $this->searchByRecipient($query, $request->recipient_search);
        }

        if ($request->filled('delivery_method_id')) {
            $query = $query->where('delivery_method_id', $request->delivery_method_id);
        }

        // Фильтр по менеджеру (assigned_user_id).
        // Спец-значение 'null' (строкой) — заказы без назначенного менеджера.
        // Передаётся, например, кнопкой «Без менеджера» в фильтре столбца.
        if ($request->filled('assigned_user_id')) {
            $assignedUserId = $request->assigned_user_id;
            if ($assignedUserId === 'null' || $assignedUserId === '0') {
                $query = $query->whereNull('assigned_user_id');
            } else {
                $query = $query->where('assigned_user_id', (int) $assignedUserId);
            }
        }

        return $query;
    }

    /**
     * Фильтр по статусу заказа
     */
    private function filterByStatus(Builder $query, string $status): Builder
    {
        try {
            $statusEnum = OrderStatus::from($status);
            return $query->where('status', $statusEnum);
        } catch (\ValueError $e) {
            // Невалидный статус - возвращаем query без фильтра
            return $query;
        }
    }

    /**
     * Фильтр по статусу оплаты
     */
    private function filterByPaymentStatus(Builder $query, string $paymentStatus): Builder
    {
        try {

            $paymentStatusEnum = PaymentStatus::from($paymentStatus);

            return $query->where('payment_status', $paymentStatusEnum);
        } catch (\ValueError $e) {
            // Невалидный статус - возвращаем query без фильтра
            return $query;
        }

    }

    /**
     * Фильтр по дате создания (от)
     */
    private function filterByDateFrom(Builder $query, string $dateFrom): Builder
    {
        try {
            $date = \Carbon\Carbon::parse($dateFrom);
            return $query->whereDate('created_at', '>=', $date);
        } catch (\Exception $e) {
            return $query;
        }
    }

    /**
     * Фильтр по дате создания (до)
     */
    private function filterByDateTo(Builder $query, string $dateTo): Builder
    {
        try {
            $date = \Carbon\Carbon::parse($dateTo);
            return $query->whereDate('created_at', '<=', $date);
        } catch (\Exception $e) {
            return $query;
        }
    }

    /**
     * Фильтр по ID клиента
     */
    private function filterByClientId(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Фильтр по промокоду
     */
    private function filterByPromoCode(Builder $query, string $promoCode): Builder
    {
        return $query->whereHas('promoCode', function ($q) use ($promoCode) {
            $q->where('code', 'like', '%' . $promoCode . '%');
        });
    }

    /**
     * Фильтр по городу
     */
    private function filterByCity(Builder $query, string $city): Builder
    {
        return $query->where('city_name', 'like', '%' . $city . '%');
    }

    /**
     * Фильтр по стране
     */
    private function filterByCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Фильтр по телефону (безопасная версия с проверкой связей)
     */
    private function filterByPhone(Builder $query, string $phone): Builder
    {
        $needles = $this->phoneNeedles($phone);
        if (empty($needles)) {
            return $query;
        }

        return $query->where(function ($q) use ($needles) {
            // Поиск в client.profile (нормализуем колонку на лету)
            $q->whereHas('client', function ($clientQuery) use ($needles) {
                $clientQuery->whereHas('profile', function ($profileQuery) use ($needles) {
                    $profileQuery->where(function ($inner) use ($needles) {
                        $this->applyPhoneLike($inner, 'phone', $needles);
                    });
                });
            });

            // Или поиск напрямую в orders, если телефон там тоже хранится
            if (\Schema::hasColumn('orders', 'phone')) {
                $q->orWhere(function ($inner) use ($needles) {
                    $this->applyPhoneLike($inner, 'orders.phone', $needles);
                });
            }
        });
    }

    /**
     * Нормализация телефона: оставляем только цифры.
     */
    private function normalizePhone(?string $raw): string
    {
        return preg_replace('/\D+/', '', (string) $raw) ?? '';
    }

    /**
     * Возвращает список LIKE-needle'ов для поиска по телефону.
     * Для 11-значных RU-номеров отдаёт сразу два варианта (с "7…" и "8…"),
     * чтобы "+7 912…", "8 912…" и "7912…" считались эквивалентными независимо
     * от того, как телефон записан в БД.
     */
    private function phoneNeedles(string $rawSearch): array
    {
        $digits = $this->normalizePhone($rawSearch);
        if ($digits === '') {
            return [];
        }

        $needles = ['%' . $digits . '%'];

        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $alt = ($digits[0] === '8' ? '7' : '8') . substr($digits, 1);
            $needles[] = '%' . $alt . '%';
        }

        return $needles;
    }

    /**
     * Подцепить к Builder'у OR-условия "REGEXP_REPLACE(col, '[^0-9]', '') LIKE ?"
     * для каждого варианта needle.
     */
    private function applyPhoneLike(Builder $query, string $column, array $needles): void
    {
        if (empty($needles)) {
            return;
        }

        $expr = "REGEXP_REPLACE(COALESCE({$column}, ''), '[^0-9]', '')";

        foreach ($needles as $needle) {
            $query->orWhereRaw("{$expr} LIKE ?", [$needle]);
        }
    }

    /**
     * Фильтр по минимальной сумме заказа
     */
    private function filterByMinAmount(Builder $query, float $minAmount): Builder
    {
        return $query->where('total_amount', '>=', $minAmount);
    }

    /**
     * Фильтр по максимальной сумме заказа
     */
    private function filterByMaxAmount(Builder $query, float $maxAmount): Builder
    {
        return $query->where('total_amount', '<=', $maxAmount);
    }

    /**
     * Поиск по тексту (для user_profiles, а не client_profiles)
     */
    private function search(Builder $query, string $search): Builder
    {
        // Все варианты needle'ов для поиска по телефону (включая 7↔8 для RU)
        $phoneNeedles = $this->phoneNeedles($search);

        return $query->where(function ($q) use ($search, $phoneNeedles) {
            // Поиск по ID заказа
            if (is_numeric($search)) {
                $q->orWhere('orders.id', $search);
            }

            // Поиск по данным клиента: email, имя, телефон, адрес из user_profiles
            $q->orWhereHas('client', function ($clientQuery) use ($search, $phoneNeedles) {
                $clientQuery->where(function ($cq) use ($search, $phoneNeedles) {
                    // Email клиента
                    $cq->where('email', 'like', '%' . $search . '%');

                    // Данные профиля
                    $cq->orWhereHas('profile', function ($profileQuery) use ($search, $phoneNeedles) {
                        $profileQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ['%' . $search . '%']);

                        // Телефон: нормализуем обе стороны (REGEXP_REPLACE убирает все нецифры)
                        $this->applyPhoneLike($profileQuery, 'phone', $phoneNeedles);

                        // Общий адрес клиента
                        $profileQuery->orWhere('address', 'like', '%' . $search . '%');
                    });
                });
            });

            // Поиск по адресу доставки заказа (order_addresses)
            $q->orWhereHas('address', function ($addrQuery) use ($search, $phoneNeedles) {
                $addrQuery->where('country', 'like', '%' . $search . '%')
                    ->orWhere('region', 'like', '%' . $search . '%')
                    ->orWhere('city', 'like', '%' . $search . '%')
                    ->orWhere('address', 'like', '%' . $search . '%')
                    ->orWhere('postal_code', 'like', '%' . $search . '%')
                    ->orWhere('recipient_first_name', 'like', '%' . $search . '%')
                    ->orWhere('recipient_last_name', 'like', '%' . $search . '%');

                // Телефон получателя: нормализуем обе стороны
                $this->applyPhoneLike($addrQuery, 'recipient_phone', $phoneNeedles);
            });

            // Поиск по другим полям в orders
            if (\Schema::hasColumn('orders', 'notes')) {
                $q->orWhere('orders.notes', 'like', '%' . $search . '%');
            }
        });
    }

    /**
     * Фильтр по email клиента (ищет по clients.email и orders.email для гостей)
     */
    private function filterByEmail(Builder $query, string $email): Builder
    {
        $needle = '%' . trim($email) . '%';

        return $query->where(function ($q) use ($needle) {
            // Email авторизованного клиента
            $q->whereHas('client', fn ($cq) => $cq->where('email', 'like', $needle));

            // Email гостевого заказа (хранится прямо в orders.email)
            $q->orWhere('orders.email', 'like', $needle);
        });
    }

    /**
     * Фильтр по номеру заказа (order_number) или ID.
     * Ищет по точному совпадению order_number и по числовому ID.
     */
    private function filterByOrderNumber(Builder $query, string $value): Builder
    {
        $value = trim($value);

        return $query->where(function ($q) use ($value) {
            // Поиск по order_number (like, чтобы работал частичный ввод)
            $q->where('order_number', 'like', '%' . $value . '%');

            // Числовой ID
            if (is_numeric($value)) {
                $q->orWhere('orders.id', (int) $value);
            }
        });
    }

    /**
     * Поиск строго по ФИО получателя (order_addresses.recipient_*).
     * Используется фильтром столбца «ФИО получателя» — не должен «зацеплять»
     * имя/фамилию клиента из user_profiles, чтобы при вводе «смагин» не
     * возвращались заказы клиентов с другой фамилией получателя.
     */
    private function searchByRecipient(Builder $query, string $search): Builder
    {
        $needle = '%' . $search . '%';

        return $query->whereHas('address', function ($addrQuery) use ($needle, $search) {
            $addrQuery->where(function ($q) use ($needle, $search) {
                $q->where('recipient_first_name', 'like', $needle)
                    ->orWhere('recipient_last_name', 'like', $needle)
                    ->orWhere('recipient_middle_name', 'like', $needle)
                    ->orWhereRaw(
                        "CONCAT_WS(' ', recipient_last_name, recipient_first_name, recipient_middle_name) like ?",
                        [$needle]
                    )
                    ->orWhereRaw(
                        "CONCAT_WS(' ', recipient_first_name, recipient_last_name) like ?",
                        [$needle]
                    );
            });
        });
    }

    /**
     * Применить сортировку
     */
    public function applySorting(Builder $query, Request $request): Builder
    {
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        // Разрешенные поля для сортировки
        $allowedSortFields = [
            'id',
            'created_at',
            'total_amount',
            'status',
            'payment_status',
            'city_name',
            'country_code',
        ];

        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }

        if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
            $sortOrder = 'desc';
        }

        return $query->orderBy($sortBy, $sortOrder);
    }

    /**
     * Получить параметры фильтрации для отображения
     */
    public function getActiveFilters(Request $request): array
    {
        $filters = [];

        if ($request->filled('status')) {
            $filters['status'] = [
                'label' => 'Статус',
                'value' => $request->status,
            ];
        }

        if ($request->filled('payment_status')) {
            $filters['payment_status'] = [
                'label' => 'Статус оплаты',
                'value' => $request->payment_status,
            ];
        }

        if ($request->filled('date_from')) {
            $filters['date_from'] = [
                'label' => 'Дата от',
                'value' => $request->date_from,
            ];
        }

        if ($request->filled('date_to')) {
            $filters['date_to'] = [
                'label' => 'Дата до',
                'value' => $request->date_to,
            ];
        }

        if ($request->filled('client_id')) {
            $filters['client_id'] = [
                'label' => 'ID клиента',
                'value' => $request->client_id,
            ];
        }

        if ($request->filled('promo_code')) {
            $filters['promo_code'] = [
                'label' => 'Промокод',
                'value' => $request->promo_code,
            ];
        }

        if ($request->filled('city')) {
            $filters['city'] = [
                'label' => 'Город',
                'value' => $request->city,
            ];
        }

        if ($request->filled('country_code')) {
            $filters['country_code'] = [
                'label' => 'Страна',
                'value' => $request->country_code,
            ];
        }

        if ($request->filled('min_amount')) {
            $filters['min_amount'] = [
                'label' => 'Мин. сумма',
                'value' => $request->min_amount,
            ];
        }

        if ($request->filled('max_amount')) {
            $filters['max_amount'] = [
                'label' => 'Макс. сумма',
                'value' => $request->max_amount,
            ];
        }

        if ($request->filled('search')) {
            $filters['search'] = [
                'label' => 'Поиск',
                'value' => $request->search,
            ];
        }

        return $filters;
    }

    /**
     * Валидация параметров фильтрации
     */
    public function validateFilterParams(Request $request): array
    {
        return $request->validate([
            'status' => 'nullable|string',
            'payment_status' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'client_id' => 'nullable|integer|exists:clients,id',
            'promo_code' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|size:2',
            'phone' => 'nullable|string|max:20',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
            'search' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
            'order_number' => 'nullable|string|max:100',
            'recipient_search' => 'nullable|string|max:255',
            'delivery_method_id' => 'nullable|integer|exists:delivery_methods,id',
            // Спец-значение 'null' допускаем как маркер «без менеджера», поэтому
            // не используем integer-валидатор. Дальнейшая проверка — в applyFilters.
            'assigned_user_id' => 'nullable|string',
            'sort_by' => 'nullable|string|in:id,created_at,total_amount,status,payment_status,city_name,country_code',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);
    }
}
