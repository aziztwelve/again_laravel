<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DeliveryMethod;
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

        if ($request->filled('delivery_method_id')) {
            $query = $this->filterByDeliveryMethod($query, $request->delivery_method_id);
        }

        if ($request->filled('delivery_service')) {
            $query = $this->filterByDeliveryService($query, $request->delivery_service);
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

        // Точный фильтр по конкретному промокоду (по orders.promo_code_id).
        // Используется ссылками вида /orders/list?promo_code_id=N — например,
        // из статистики промокода («Использован»), чтобы показать именно
        // заказы, где применён этот купон (LIKE по коду мог бы зацепить
        // другие коды с похожим названием).
        if ($request->filled('promo_code_id')) {
            $query = $query->where('orders.promo_code_id', (int) $request->promo_code_id);
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
    private function filterByStatus(Builder $query, string|array $status): Builder
    {
        $statuses = collect((array) $status)
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(function (string $value) {
                try {
                    return OrderStatus::from($value)->value;
                } catch (\ValueError) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();

        // Невалидные значения не должны превращать список заказов в пустой.
        return $statuses === [] ? $query : $query->whereIn('status', $statuses);
    }

    /**
     * Фильтр по статусу оплаты
     */
    private function filterByPaymentStatus(Builder $query, string|array $paymentStatus): Builder
    {
        // Одиночное значение (строка из верхней панели) и мультивыбор (массив
        // из заголовка столбца «Оплата») обрабатываются одинаково.
        $statuses = collect((array) $paymentStatus)
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(function (string $value) {
                try {
                    return PaymentStatus::from($value)->value;
                } catch (\ValueError) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();

        // Невалидные значения не должны превращать список заказов в пустой.
        return $statuses === [] ? $query : $query->whereIn('payment_status', $statuses);
    }

    /**
     * Фильтр по способу доставки (одиночный id или массив id).
     */
    private function filterByDeliveryMethod(Builder $query, int|string|array $deliveryMethodId): Builder
    {
        $ids = collect((array) $deliveryMethodId)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        return $ids === [] ? $query : $query->whereIn('delivery_method_id', $ids);
    }

    /**
     * Фильтр по службе доставки (ключи из config/delivery_services.php).
     * Служба раскрывается в коды способов доставки, а затем в их id.
     */
    private function filterByDeliveryService(Builder $query, string|array $services): Builder
    {
        $known = config('delivery_services', []);

        $keys = collect((array) $services)
            ->filter(fn ($value) => is_string($value) && array_key_exists($value, $known))
            ->values()
            ->all();

        if ($keys === []) {
            return $query;
        }

        $codes = collect($keys)
            ->flatMap(fn (string $key) => $known[$key]['codes'])
            ->unique()
            ->values()
            ->all();

        $ids = DeliveryMethod::query()->whereIn('code', $codes)->pluck('id')->all();

        return $query->whereIn('delivery_method_id', $ids);
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
        $search = trim($search);
        $tokens = $this->searchTokens($search);

        return $query->where(function ($q) use ($search, $tokens) {
            // Номер заказа: точное совпадение, чтобы 40249 (или №40249 из письма
            // и CloudPayments) находил заказ прямо в верхней строке. Частичный
            // поиск по номеру остаётся в фильтре столбца «Номер заказа» (order_number),
            // иначе короткие числа засоряли бы выдачу.
            $q->orWhere('order_number', $this->normalizeOrderNumber($search));

            // Поиск по ID заказа — только для заказов БЕЗ order_number
            // (в списке они показывают id как номер, см. filterByOrderNumber).
            if (is_numeric($search)) {
                $q->orWhere(function ($idQuery) use ($search) {
                    $idQuery->whereNull('order_number')
                        ->where('orders.id', (int) $search);
                });
            }

            // Телефон целиком («+7 (999) 123-45-67» содержит пробелы и не должен
            // разваливаться на слова). Только если в запросе нет букв.
            if (! preg_match('/\p{L}/u', $search)) {
                $phoneNeedles = $this->phoneNeedles($search);
                if ($phoneNeedles !== [] && strlen($this->normalizePhone($search)) >= 4) {
                    // where(fn) группирует OR-условия, иначе они ломают связь подзапроса с заказом
                    $q->orWhereHas('client', fn ($cq) => $cq->whereHas('profile', function ($pq) use ($phoneNeedles) {
                        $pq->where(fn ($w) => $this->applyPhoneLike($w, 'phone', $phoneNeedles));
                    }));
                    $q->orWhereHas('address', function ($aq) use ($phoneNeedles) {
                        $aq->where(fn ($w) => $this->applyPhoneLike($w, 'recipient_phone', $phoneNeedles));
                    });
                }
            }

            // Текст: «Калинина Елена», «Казань Калинина», «Иванов ул Ленина» —
            // КАЖДОЕ слово запроса должно найтись в данных заказа (где угодно:
            // получатель, клиент, адрес, email), порядок слов не важен.
            if ($tokens !== []) {
                $q->orWhere(function ($all) use ($tokens) {
                    foreach ($tokens as $token) {
                        $all->where(fn ($t) => $this->applyTokenMatch($t, $token));
                    }
                });
            }
        });
    }

    /**
     * Слова поискового запроса (разделители — пробелы, запятые, точки с запятой).
     *
     * @return list<string>
     */
    private function searchTokens(string $search): array
    {
        $tokens = preg_split('/[\s,;]+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($tokens));
    }

    /**
     * Условия «слово встречается хотя бы в одном поле заказа».
     */
    private function applyTokenMatch(Builder $q, string $token): void
    {
        $like = '%' . addcslashes($token, '%_\\') . '%';
        $phoneNeedles = strlen($this->normalizePhone($token)) >= 4 ? $this->phoneNeedles($token) : [];

        // Данные клиента: email и профиль
        $q->orWhereHas('client', function ($clientQuery) use ($like, $phoneNeedles) {
            $clientQuery->where(function ($cq) use ($like, $phoneNeedles) {
                $cq->where('email', 'like', $like);

                $cq->orWhereHas('profile', function ($profileQuery) use ($like, $phoneNeedles) {
                    $profileQuery->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('address', 'like', $like);

                    $this->applyPhoneLike($profileQuery, 'phone', $phoneNeedles);
                });
            });
        });

        // Получатель и адрес доставки заказа (в address хранится и адрес ПВЗ)
        $q->orWhereHas('address', function ($addrQuery) use ($like, $phoneNeedles) {
            $addrQuery->where('country', 'like', $like)
                ->orWhere('region', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhere('postal_code', 'like', $like)
                ->orWhere('recipient_first_name', 'like', $like)
                ->orWhere('recipient_last_name', 'like', $like)
                ->orWhere('recipient_middle_name', 'like', $like);

            $this->applyPhoneLike($addrQuery, 'recipient_phone', $phoneNeedles);
        });

        // Email гостевого заказа лежит прямо в orders.email
        $q->orWhere('orders.email', 'like', $like);

        if (\Schema::hasColumn('orders', 'notes')) {
            $q->orWhere('orders.notes', 'like', $like);
        }
    }

    /**
     * Порядок «сначала заказы, где запрос — это ФИО получателя/клиента»: при поиске
     * «Калинина» заказы получательницы Калининой выше заказов, где это просто улица.
     */
    private function applySearchRelevance(Builder $query, string $search): Builder
    {
        $tokens = $this->searchTokens($search);
        if ($tokens === []) {
            return $query;
        }

        $conds = [];
        $bindings = [];
        foreach ($tokens as $token) {
            $prefix = addcslashes($token, '%_\\') . '%';
            $conds[] = '(oa.recipient_last_name LIKE ? OR oa.recipient_first_name LIKE ?)';
            array_push($bindings, $prefix, $prefix);
        }

        // Все слова запроса должны быть в имени получателя: «Калинина Елена»
        $sql = 'CASE WHEN EXISTS (SELECT 1 FROM order_addresses oa WHERE oa.order_id = orders.id AND '
            . implode(' AND ', $conds) . ') THEN 0 ELSE 1 END';

        return $query->orderByRaw($sql, $bindings);
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
     * Номер заказа так, как он хранится: без пробелов и знака «№» / «#» в начале.
     * Номер часто копируют из письма или CloudPayments («Оплата заказа №40249»).
     */
    private function normalizeOrderNumber(string $value): string
    {
        return trim(preg_replace('/^[\s№#]+/u', '', trim($value)));
    }

    /**
     * Фильтр по номеру заказа (order_number) или ID.
     * Ищет по точному совпадению order_number и по числовому ID.
     */
    private function filterByOrderNumber(Builder $query, string $value): Builder
    {
        $value = $this->normalizeOrderNumber($value);

        return $query->where(function ($q) use ($value) {
            // Поиск по order_number (like, чтобы работал частичный ввод)
            $q->where('order_number', 'like', '%' . $value . '%');

            // Числовой ID — только для заказов БЕЗ order_number: в списке
            // заказов такие строки показывают id вместо номера. Для заказов
            // с номером id не матчим: иначе поиск «12989» возвращал ещё и
            // заказ с id=12989, но совершенно другим видимым номером.
            if (is_numeric($value)) {
                $q->orWhere(function ($idQuery) use ($value) {
                    $idQuery->whereNull('order_number')
                        ->where('orders.id', (int) $value);
                });
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
            'order_number',
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

        // При текстовом поиске (без явной сортировки, кроме «новые сверху») сначала
        // идут заказы, где запрос совпал с ФИО получателя.
        if ($request->filled('search') && $sortBy === 'created_at') {
            $query = $this->applySearchRelevance($query, (string) $request->search);
        }

        if ($sortBy === 'order_number') {
            // Номер — строка (бывают TEST-*/MS-TEST-*), поэтому для числовых
            // номеров сортируем как число, иначе №9 окажется после №10000.
            $dir = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

            return $query
                ->orderByRaw("CAST(order_number AS UNSIGNED) {$dir}")
                ->orderBy('order_number', $dir)
                ->orderBy('id', $dir);
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

        if ($request->filled('promo_code_id')) {
            $filters['promo_code_id'] = [
                'label' => 'Промокод',
                'value' => $request->promo_code_id,
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
            // Одиночный статус приходит строкой, мультивыбор из админки —
            // массивом (`status[]`). Допустимы оба формата.
            'status' => 'nullable',
            'status.*' => 'string',
            // Оплата и доставка: одиночное значение или массив (мультивыбор в столбце).
            'payment_status' => 'nullable',
            'payment_status.*' => 'string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'client_id' => 'nullable|integer|exists:clients,id',
            'promo_code' => 'nullable|string|max:50',
            'promo_code_id' => 'nullable|integer|exists:promo_codes,id',
            'city' => 'nullable|string|max:255',
            'country_code' => 'nullable|string|size:2',
            'phone' => 'nullable|string|max:20',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
            'search' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:255',
            'order_number' => 'nullable|string|max:100',
            'recipient_search' => 'nullable|string|max:255',
            'delivery_method_id' => 'nullable',
            'delivery_method_id.*' => 'integer|exists:delivery_methods,id',
            'delivery_service' => 'nullable',
            'delivery_service.*' => 'string',
            // Спец-значение 'null' допускаем как маркер «без менеджера», поэтому
            // не используем integer-валидатор. Дальнейшая проверка — в applyFilters.
            'assigned_user_id' => 'nullable|string',
            'sort_by' => 'nullable|string|in:id,order_number,created_at,total_amount,status,payment_status,city_name,country_code',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);
    }
}
