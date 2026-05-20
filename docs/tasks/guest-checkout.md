# Задача: Гостевой checkout (оформление заказа без регистрации)

**Статус:** Реализовано
**Дата:** 2026-05-16

## Описание

До этой задачи витрина (`nuxt-shop`) принимала заказ только от авторизованного клиента. Если пользователь не залогинен — `pages/checkout/index.vue` показывал заглушку «Для оформления заказа требуется авторизоваться», а единственный endpoint создания заказа (`POST /api/orders`) был под `auth:sanctum` и отвечал `401` без токена.

Теперь добавлен публичный путь оформления:

- Гость заполняет тот же чекаут (имя, фамилия, телефон, адрес, опционально email).
- Заказ создаётся с `orders.client_id = NULL`.
- Запись в таблице `clients` **не создаётся** — клиентская база остаётся «чистой», только зарегистрированные пользователи.
- Просмотр заказа — по публичной ссылке `/orders/{view_token}` (32-символьный hex).
- Для авторизованного клиента ничего не меняется: новый endpoint работает и с токеном, заказ привязывается к клиенту.

---

## Бизнес-логика и решения

1. **Не создаём `Client` для гостя.** `orders.client_id = NULL`. Контактные данные хранятся в самом заказе:
   - `orders.email` — новая колонка.
   - `order_addresses.recipient_first_name / recipient_last_name / recipient_phone` — уже существовали.
2. **Email опционален.** Если введён — отправим уведомление с ссылкой на `/orders/{view_token}`.
3. **Что доступно гостю:**
   - Публичные промокоды (`applies_to_all_clients = true`) — **да**.
   - Акции (`promotions`) — **да**, они привязаны к корзине, не к клиенту.
   - Подарочные сертификаты (покупка + применение по коду) — **да**.
   - Персональные промокоды (привязанные к `client_id`, birthday-скидки) — **нет**, технически невозможно без аккаунта. Ошибка `PROMO_REQUIRES_AUTH`.
   - Бонусные баллы — **нет**, негде хранить баланс.
4. **Админам публичный endpoint запрещён.** Для админов остаётся `POST /api/orders` (там они выбирают `client_id` и проходят свои проверки прав).
5. **Защита от спама.** Rate-limit `throttle:30,1` (30 запросов/мин с одного IP), IP/User-Agent пишутся в `orders`.

---

## Изменения в БД

### Новые миграции

| Миграция | Что делает |
|----------|-----------|
| `2026_05_16_092626_add_email_to_orders_table.php` | Добавляет `orders.email` (`nullable string` + index). |
| `2026_05_16_092627_make_client_id_nullable_in_promo_code_usages.php` | Делает `promo_code_usages.client_id` nullable, чтобы записывать использование промокода в гостевом заказе. FK на `clients` пересоздаётся. |

### Колонки `orders` (релевантное)

| Колонка | Тип | Назначение |
|---------|-----|-----------|
| `client_id` | `bigint NULL` | NULL для гостевых заказов. Уже было nullable до этой задачи. |
| `email` | `varchar NULL` | **Новая.** Email покупателя для гостевых заказов. Для авторизованных клиентов остаётся NULL — email берётся из `clients.email`. |
| `ip_address`, `user_agent` | `varchar NULL` | Заполняются для гостевых заказов как сигнал анти-фрода. |

`orders` физически **не содержит** колонок `first_name / last_name / phone` (это давно было так). Имя и телефон покупателя хранятся в `order_addresses.recipient_*`. Service-код передаёт эти ключи в `Order::create()` на случай добавления колонок в будущем — Laravel отфильтрует их через `$fillable`.

---

## Маршруты

| Метод | URL | Контроллер | Middleware | Назначение |
|-------|-----|-----------|------------|-----------|
| `POST` | `/api/public/orders` | `Api\Public\Order\PublicCheckoutController@store` | `throttle:30,1` | **Новый.** Публичное оформление заказа (гость или авторизованный клиент). |
| `GET` | `/api/public/orders/{viewToken}` | `Api\Public\Order\PublicOrderController@show` | — | Существовал. Публичный просмотр заказа по 32-символьному `view_token`. Расширен: `recipient.email` теперь берётся из `order->email` для гостевых заказов. |
| `POST` | `/api/orders` | `Api\Admin\OrderController@store` | `auth:sanctum` | Не изменён. Используется админкой (`vue-admin`) для создания заказа от имени клиента. |

Авторизация на публичном endpoint резолвится через `Auth::guard('sanctum')->user()` **внутри контроллера** — middleware `auth:sanctum` не используется, иначе запрос без токена сразу получает 401. Поэтому:

- Нет `Authorization` или невалидный токен → `$authUser = null` → гостевой заказ.
- Валидный токен клиента → заказ привязан к этому клиенту.
- Валидный токен админа → возвращаем 403 с подсказкой использовать `/api/orders`.

---

## Изменённые файлы (backend)

### Контроллеры

- **Создан** `app/Http/Controllers/Api/Public/Order/PublicCheckoutController.php`
  Полный pipeline создания заказа (валидация промокода → валидация подарочной карты → валидация позиций → создание заказа → позиции → промокод → акция → подарочная карта → создание сертификата → уведомления). Принимает `CreateOrderRequest`. Идемпотентен по `client_id`: если в payload пришёл `client_id` — он игнорируется для гостя (анти-подделка).
- `app/Http/Controllers/Api/Public/Order/PublicOrderController.php`
  В `formatOrder()`: `recipient.email` → `$order->client?->email ?? $order->email`.

### Сервисы

- `app/Services/Order/OrderCreationService.php`
  - Сигнатура: `createOrder(array $orderData, ?int $clientId = null): Order` — `clientId` стал nullable.
  - Сохраняет `email`, `ip_address`, `user_agent` в `orders`.
  - `getOrderSummary()` использует nullsafe (`$order->client?->email ?? $order->email`), добавлено поле `is_guest`.
- `app/Services/PromoCode/PromoCodeValidationService.php`
  - Все три метода (`validate`, `checkClientEligibility`, `checkUsageLimits`, `logPromoCodeUsage`) принимают `?Client $client = null`.
  - Для гостя:
    - Публичный промокод (`applies_to_all_clients = true`) → допустим.
    - Персональный промокод → ошибка `PROMO_REQUIRES_AUTH` с человеко-читаемым сообщением.
    - Проверка «вы уже использовали этот промокод» пропускается (для гостя её невозможно выполнить — нет идентификатора прошлых использований). Защита: глобальный `max_uses` + rate-limit endpoint.
- `app/Services/GiftCard/GiftCardService.php`
  - Добавлен метод `scheduleDelivery(GiftCard $giftCard, array $data)` — извлечён из приватного метода `OrderController::scheduleGiftCardDelivery`, чтобы `PublicCheckoutController` мог его использовать.
  - `createFromOrder()` и `resolveRecipientData()` — все обращения к `$order->client->*` переписаны на nullsafe (`?->`) + fallback на `$order->email / phone / address->recipient_*`.
- `app/Services/Export/OrderExportService.php`
  - CSV-экспорт заказов — nullsafe + fallback на `order_addresses.recipient_*` и `order.email`, чтобы гостевые заказы корректно выгружались.

### Запросы

- `app/Http/Requests/Order/CreateOrderRequest.php`
  - Правила: добавлено `'user.email' => 'nullable|email|max:255'`.
  - `prepareForValidation()` — теперь корректно работает без `auth()->user()` (раньше тоже работал, но комментарий уточнён).
  - Существующая проверка «`client_id` обязателен для админа» сохраняется (она внутри `withValidator` смотрит на тип `$user`).

### Модели

- `app/Models/Order.php`
  - В `$fillable` добавлено `'email'`.

---

## Изменения в витрине (nuxt-shop)

- `composables/useApi.ts` — больше не отправляет заголовок `Authorization: Bearer null` для гостей. Header добавляется только при наличии `authStore.token`.
- `pages/checkout/index.vue`:
  - Убран блок «требуется авторизация» (`v-if="!userStore.isAuthenticated"`).
  - `form.user.email` (новое поле) пробрасывается в `CheckoutUser`.
  - POST идёт на `/public/orders` (а не `/orders`).
  - После успеха редирект на `/orders/{view_token}` (публичная страница), фолбэк на старый `/success?id=...`.
  - `gift_card_data` — для гостя fallback берёт email/phone из формы, а не из `userStore.user`.
- `components/Checkout/User.vue`:
  - Если **не залогинен**: показывается приглашение войти + опциональное поле email.
  - Если **залогинен**: текущий блок «вы вошли как ...».
  - Добавлена `defineModel<string>('email')`.
- `components/Checkout/Recipient.vue` — изменений не требовалось, форма уже корректно собирала имя/фамилия/телефон для обоих режимов.
- `pages/orders/[token].vue` — уже использовал публичный endpoint `/public/orders/{token}`, работает без авторизации.

---

## Изменения в админке (vue-admin)

- `src/components/orders/view/partials/OrderHeader.vue` — амбер-бейдж **«Гостевой заказ»** при `order.client_id === null`.
- `src/components/orders/view/partials/side/SideClient.vue` — для гостевого заказа показываются контактные данные из самого заказа: ФИО из `order.address.recipient_*`, телефон из `order.address.recipient_phone || order.phone`, email из `order.email`. Кнопка «Выбрать» (привязать существующего клиента вручную) остаётся.
- `src/components/orders/view/OrderView.vue` — пробрасывает `:order="order"` в `<SideClient>`.
- `src/components/orders/list/OrderListTable.vue` — изменений не потребовалось, колонка «ФИО получателя» уже сначала смотрит на `address.recipient_*`, потом на `client.profile`.

---

## Поток данных для гостевого заказа

```
1. Гость собирает корзину в localStorage (stores/cart.js) — работало и раньше.
2. Гость открывает /checkout, заполняет:
   - Имя, фамилия, телефон (CheckoutRecipient → form.user.*)
   - Email опционально (CheckoutUser → form.user.email)
   - Адрес доставки, способ доставки, способ оплаты
3. POST /api/public/orders
   - useApi не шлёт Bearer (токена нет).
   - throttle:30,1 пропускает.
   - PublicCheckoutController:
     - Auth::guard('sanctum')->user() → null
     - $orderClient = null
     - unset($validated['client_id'])
     - $validated['ip_address'] = request->ip()
     - PromoCodeValidationService::validate($code, null) — публичный код OK, персональный → 422 PROMO_REQUIRES_AUTH
     - OrderValidationService::validateOrderItems — обычный пайплайн, не зависит от клиента
     - OrderCreationService::createOrder($validated, null):
         orders INSERT { client_id: NULL, email: ..., view_token: ..., ip_address: ..., ... }
         order_addresses INSERT { recipient_first_name, recipient_last_name, recipient_phone, ... }
     - createOrderItems, applyPromoCode (promo_code_usages с client_id=NULL), applyPromotion, applyGiftCard, createGiftCard
     - sendNotifications($order, null):
         если есть order->email → SendNotificationJob('email', ...)
4. Ответ: { success: true, order: {..., view_token: 'abc...'} }
5. Витрина: navigateTo('/orders/abc...') → PublicOrderController отдаёт детали.
```

---

## Smoke-тесты (curl)

| Сценарий | Ожидание | Результат |
|----------|----------|-----------|
| `POST /api/public/orders` минимальный payload без email | 200, `client_id: null`, `email: null`, `view_token` есть | OK |
| То же + `user.email` | 200, `email` сохранён в orders | OK |
| То же + `promo_code: "Скидка11"` (публичный 10%) | 200, скидка применена | OK, total 1044 ₽ из 1160 ₽ |
| То же + `promo_code: "BIRTHDAY"` (персональный) | 422, code=`PROMO_REQUIRES_AUTH` | OK с сообщением «доступен только зарегистрированным» |
| Пустой payload `{}` | 422, errors по всем required-полям | OK |
| `POST /api/public/orders` + `Authorization: Bearer <client_token>` | 200, `client_id` = ID клиента | OK |
| `POST /api/orders` (админский) без токена | 401/403 | OK (регресс не затронут) |
| `GET /api/public/orders/{view_token}` для гостевого заказа | 200, `recipient.email` равен `order->email` | OK |

---

## Что НЕ сделано (сознательно, по обсуждённой стратегии)

- **Не создаём `Client` для гостя** даже автоматически по email/phone. Если в будущем понадобится «забрать гостевой заказ в свой кабинет после регистрации» — это отдельная фича (поиск гостевых заказов по совпадению `email`/`phone` и привязка к новому `client_id` через UI).
- **Не предлагаем регистрацию на success-странице** — отложено.
- **Бонусные баллы для гостя не начисляются** — некуда хранить.
- **Персональные промокоды и birthday-скидки** недоступны гостю — естественное ограничение.
- `OrderController::scheduleGiftCardDelivery` (приватный метод в админском контроллере) **оставлен как есть** для обратной совместимости. Та же логика вынесена в `GiftCardService::scheduleDelivery()` и используется в `PublicCheckoutController`. При следующем рефакторинге OrderController можно тоже переключить на сервис.

---

## Возможные риски и нюансы

- **Гость вводит email уже зарегистрированного клиента.** Коллизии нет (мы не пишем в `clients.email`), но владелец того аккаунта не увидит заказ в своей истории. Это сознательное ограничение.
- **Двойная отправка**: один и тот же email/phone у гостя и зарегистрированного клиента — нормально, заказы трекаются раздельно.
- **`promo_code_usages.client_id` теперь nullable.** Любая старая аналитика, которая делала `JOIN clients ON promo_code_usages.client_id = clients.id` без `LEFT JOIN`, будет терять гостевые использования. Если нужно — можно построить отдельный отчёт по гостевым кодам.
- **Rate-limit `30/min` на IP.** Если магазин сидит за CDN/прокси без проброса реального IP — все заказы выглядят с одного адреса и лимит можно случайно упереть. Проверить `TrustProxies` middleware (вне рамок этой задачи).

---

## Файлы, которые могут потребовать внимания при дальнейших правках

Эти места исторически содержали `$order->client->*` без nullsafe — пройдитесь по ним при следующих фичах, чтобы не словить fatal на гостевых заказах:

- `app/Services/TelegramNotificationService.php::buildOrderMessage()` — использует `$order->client->first_name` напрямую. На горячем пути сейчас не вызывается, но если включат — упадёт на гостевом заказе. Требует nullsafe.
- Аналогично пройтись по новым контроллерам/сервисам — везде использовать `?->` и fallback на `order->email / phone / address->recipient_*`.

---

## Playbook для ручного тестирования

### Подготовка

```bash
# 1. Базовая конфигурация
BASE_URL="https://sub.againdev.ru"   # или твой APP_URL из .env
cd /var/www/html/laravel

# 2. Убедиться, что миграции применены
php artisan migrate:status | grep -E "add_email_to_orders|nullable_in_promo_code_usages"
# Ожидание: обе строки со статусом [Ran]

# 3. Найти товары для тестов (active + цена > 0)
php artisan tinker --execute="
echo \App\Models\Product::query()
  ->where('stock_quantity','>',0)
  ->where('price','>',0)
  ->where('has_variants',false)
  ->limit(5)->get(['id','name','price','stock_quantity'])
  ->toJson(JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
"

# 4. Найти публичный промокод (applies_to_all_clients = true)
php artisan tinker --execute="
echo \App\Models\PromoCode::where('is_active',true)
  ->where('applies_to_all_clients',true)
  ->select(['code','discount_type','discount_amount'])
  ->limit(3)->get()->toJson(JSON_UNESCAPED_UNICODE);
"

# 5. Найти персональный промокод (для негативного теста гостя)
php artisan tinker --execute="
echo \App\Models\PromoCode::where('is_active',true)
  ->where('applies_to_all_clients',false)
  ->select(['code'])
  ->limit(3)->get()->toJson(JSON_UNESCAPED_UNICODE);
"

# 6. Получить токен зарегистрированного клиента (для тестов с авторизацией)
php artisan tinker --execute="
\$client = \App\Models\Client::with('profile')->first();
\$token = \$client->createToken('manual-test')->plainTextToken;
echo 'CLIENT_ID='.\$client->id.PHP_EOL;
echo 'CLIENT_EMAIL='.\$client->email.PHP_EOL;
echo 'CLIENT_TOKEN='.\$token.PHP_EOL;
"
```

### Сценарии backend (curl)

> Замени `PRODUCT_ID`, `PRODUCT_PRICE`, `CLIENT_TOKEN` на реальные значения из подготовки. Цена должна точно совпадать с актуальной (с учётом текущих скидок), иначе бэк вернёт `PRICE_MISMATCH` — это правильно, валидация цен работает как и раньше.

#### S1. Гость без email (минимум)

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -d '{
    "user":{"first_name":"Тест","last_name":"Гость","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Тестовая, 1"},
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `order.client_id: null`, `order.email: null`, есть `view_token`.

#### S2. Гость с email (отправка чека)

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -d '{
    "user":{"first_name":"Тест","last_name":"Гость","phone":"+79991234567","email":"guest@example.com"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Тестовая, 2"},
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `order.email: "guest@example.com"`. В очереди должен появиться `SendNotificationJob` с этим email (если очереди запущены).

#### S3. Гость с публичным промокодом

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -d '{
    "user":{"first_name":"Промо","last_name":"Гость","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Промо, 1"},
    "promo_code":"PUBLIC_PROMO_CODE",
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `discount_amount > 0`, `promo_code_id` заполнен. В БД должна появиться запись в `promo_code_usages` с `client_id = NULL`.

#### S4. Гость с персональным промокодом → отказ

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -d '{
    "user":{"first_name":"X","last_name":"Y","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. X, 1"},
    "promo_code":"PERSONAL_CODE",
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: false`, `code: "PROMO_REQUIRES_AUTH"`, сообщение «Этот промокод доступен только зарегистрированным клиентам…».

#### S5. Невалидный payload → 422 со списком ошибок

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -d '{"user":{},"items":[]}' | python3 -m json.tool
```
**Ожидание:** `success: false`, в `errors` есть `delivery_address.*`, `recipient.*`, `user.first_name`, `items`.

#### S6. Авторизованный клиент через тот же endpoint (регресс)

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{
    "user":{"first_name":"Тест","last_name":"Клиент","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Клиент, 1"},
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `order.client_id` равен `CLIENT_ID` из подготовки.

#### S7. Админский endpoint без токена должен оставаться закрытым

```bash
curl -sk -X POST "$BASE_URL/api/orders" \
  -H 'Content-Type: application/json' \
  -d '{}' | python3 -m json.tool
```
**Ожидание:** `success: false`, 401 либо 500/“Route [login] not defined” — главное, что заказ **не создаётся**.

#### S8. Публичный просмотр гостевого заказа по view_token

```bash
# Подставь view_token из ответа S1/S2
curl -sk "$BASE_URL/api/public/orders/{VIEW_TOKEN}" | python3 -m json.tool
```
**Ожидание:** `success: true`. Если заказ из S2 → `recipient.email` равен email гостя; если из S1 → `recipient.email: null`. ФИО и телефон взяты из `address.recipient_*`.

#### S9. Rate-limit (опционально)

```bash
for i in $(seq 1 35); do
  curl -sk -X POST "$BASE_URL/api/public/orders" \
    -H 'Content-Type: application/json' -d '{}' \
    -o /dev/null -w "%{http_code}\n"
done | sort | uniq -c
```
**Ожидание:** примерно 30× статус `422` (валидация падает на пустом payload, но запрос пропущен), потом `429 Too Many Requests`. Если все 35 раз `422` — проверь, что middleware `throttle:30,1` навешен.

---

### Сценарии backend для авторизованного клиента (curl)

> Цель — прогнать те же ключевые сценарии, что и для гостя, но от лица зарегистрированного клиента. Все запросы идут на **тот же** публичный endpoint `POST /api/public/orders`, отличие — добавлен заголовок `Authorization: Bearer $CLIENT_TOKEN` из подготовки. Также проверяем старый админский endpoint `POST /api/orders`, который использует витрина авторизованных клиентов исторически (его поведение должно сохраниться).

#### C1. Клиент без promo (базовый кейс) через публичный endpoint

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{
    "user":{"first_name":"Тест","last_name":"Клиент","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Клиент, 1"},
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `order.client_id` равен `CLIENT_ID` из подготовки. `order.email` — `null` (для клиента email берётся из связанной модели `clients`).

#### C2. Клиент + публичный промокод

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{
    "user":{"first_name":"Тест","last_name":"Клиент","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Клиент, 2"},
    "promo_code":"PUBLIC_PROMO_CODE",
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `discount_amount > 0`. В `promo_code_usages` — запись с `client_id = $CLIENT_ID` (НЕ null, в отличие от S3).

#### C3. Клиент + персональный промокод, выданный ему

Сначала надо привязать персональный промокод к клиенту (если ещё не привязан):

```bash
php artisan tinker --execute="
\$promo = \App\Models\PromoCode::where('code','PERSONAL_CODE')->first();
\$promo->clients()->syncWithoutDetaching([CLIENT_ID]);
echo 'attached'.PHP_EOL;
"
```

```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{
    "user":{"first_name":"Тест","last_name":"Клиент","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Клиент, 3"},
    "promo_code":"PERSONAL_CODE",
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, скидка применилась. Это зеркало S4: тот же код для гостя → отказ, для клиента-получателя → ок.

#### C4. Клиент + персональный промокод, НЕ выданный ему

Если у тебя есть код, привязанный к другому клиенту:
```bash
php artisan tinker --execute="
echo \App\Models\PromoCode::where('applies_to_all_clients',false)
  ->whereHas('clients', fn(\$q) => \$q->where('client_id','!=', CLIENT_ID))
  ->whereDoesntHave('clients', fn(\$q) => \$q->where('client_id', CLIENT_ID))
  ->select(['code'])->limit(1)->get()->toJson(JSON_UNESCAPED_UNICODE);
"
```
```bash
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{...промокод другого клиента...}'  | python3 -m json.tool
```
**Ожидание:** `success: false`, `code: "PROMO_NOT_FOR_CLIENT"`, сообщение «Этот промокод недоступен для вашего аккаунта». Это **не то же**, что у гостя (`PROMO_REQUIRES_AUTH`): здесь система знает клиента и видит, что код для другого.

#### C5. Клиент пытается использовать промокод повторно

Прогнать C2 дважды подряд тем же `CLIENT_TOKEN` с тем же `promo_code`:

```bash
# Первый раз — success
# Второй раз с тем же кодом:
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{
    "user":{"first_name":"Т","last_name":"К","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Дубль, 1"},
    "promo_code":"PUBLIC_PROMO_CODE",
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: false`, `code: "PROMO_ALREADY_USED"`. У гостя такой проверки нет (мы её сознательно отключили — некуда привязать «уже использовал»).

#### C6. Клиент через старый админский endpoint (регресс прежней витрины)

Если старая версия витрины ещё в кэше у пользователя, она будет слать `POST /api/orders`. Проверь, что это работает:

```bash
curl -sk -X POST "$BASE_URL/api/orders" \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $CLIENT_TOKEN" \
  -d '{
    "user":{"first_name":"Тест","last_name":"Клиент","phone":"+79991234567"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Старый, 1"},
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```
**Ожидание:** `success: true`, `client_id = $CLIENT_ID`. Старый flow не сломан.

#### C7. Заказ клиента в его истории

```bash
curl -sk "$BASE_URL/api/orders/user" \
  -H "Authorization: Bearer $CLIENT_TOKEN" | python3 -m json.tool | head -30
```
**Ожидание:** все заказы, созданные в C1–C6, видны в `orders`. У гостя такого endpoint'а нет (это правильно — заказы гостя доступны только по `view_token`).

---

### Параллельная таблица: гость ↔ клиент

| Сценарий | Гость | Клиент | Где они должны различаться |
|----------|-------|--------|---------------------------|
| Базовый заказ | S1, S2 | C1 | У клиента `client_id` заполнен; `order.email` остаётся null (берётся из `clients.email`) |
| Публичный промокод | S3 | C2 | Скидка работает в обоих случаях; `promo_code_usages.client_id` — null vs $CLIENT_ID |
| Персональный промокод, выданный получателю | (невозможно) | C3 | Гость → `PROMO_REQUIRES_AUTH`; клиент-владелец → success |
| Персональный промокод, чужой | S4 → `PROMO_REQUIRES_AUTH` | C4 → `PROMO_NOT_FOR_CLIENT` | Разные ошибки, оба отказ — это правильно |
| Повторное использование одноразового промокода | (не проверяется для гостя) | C5 → `PROMO_ALREADY_USED` | Для гостя — сознательно нет проверки |
| Регресс старого endpoint `/api/orders` | (не применимо — гость туда не ходит) | C6 | Авторизованный клиент по старой витрине |
| Личный кабинет «Мои заказы» | (нет) | C7 / `/profile/history` в UI | Доступно только клиенту |

---

### Сценарии витрины (UI nuxt-shop)

#### U1. Гость заходит на чекаут

1. Открыть в **инкогнито** (или разлогиниться): `http://localhost:3000/checkout` (или твой dev-URL nuxt-shop).
2. Корзина должна показаться, как и раньше (она в `localStorage`).
3. Блок `CheckoutUser` должен отображать **«Оформляете как гость…»** с ссылкой на `/login` и опциональным полем email.
4. Заполнить блок «Получатель» (имя, фамилия, телефон), адрес, способ оплаты.
5. Нажать «Подтвердить заказ».
6. **Ожидание:** редирект на `/orders/{view_token}` — публичная страница заказа, где видны все позиции и контакты.

#### U2. Гость с email

То же, что U1, плюс заполнить email в блоке гостя.
**Ожидание:** на странице успеха `recipient.email` равен введённому email. Если включены `SendNotificationJob` + воркер очередей — должно прийти письмо.

#### U3. Авторизованный клиент

1. Войти в личный кабинет.
2. Открыть `/checkout`.
3. **Ожидание:** блок `CheckoutUser` показывает «Вы авторизовались как …» (старое поведение).
4. Оформить заказ.
5. **Ожидание:** редирект на `/orders/{view_token}`. Заказ привязан к клиенту (это видно в админке + в `/profile/history`).

#### U4. Devtools-проверка отсутствия «Bearer null»

1. Гость в инкогнито, чекаут, открыть DevTools → Network.
2. Submit заказа.
3. Найти запрос `POST /api/public/orders`.
4. **Ожидание:** заголовка `Authorization` нет вообще (или он пустой). Не должно быть строки `Bearer null`.

---

### Сценарии админки (vue-admin)

#### A1. Список заказов содержит гостевой

1. Зайти в админку, открыть «Заказы».
2. Найти любой из созданных в S1–S6 заказов (по `order_number`).
3. **Ожидание:** в колонке «ФИО получателя» — имя+фамилия из чекаута (взято из `order_addresses.recipient_*`, не из `client.profile`).

#### A2. Просмотр гостевого заказа

1. Открыть заказ из S1/S2/S3 (тот, что без `client_id`).
2. В шапке (`OrderHeader`) рядом с номером заказа должен быть **амбер-бейдж «Гостевой заказ»**.
3. В правой колонке блок «Клиент» (`SideClient`):
   - Подпись **«Гостевой заказ»** (амбер).
   - ФИО / Телефон / Email — заполнены из заказа.
   - Кнопка **«Выбрать»** активна (можно вручную привязать существующего клиента).

#### A3. Привязка существующего клиента к гостевому заказу

1. На странице гостевого заказа нажать «Выбрать» в блоке клиента.
2. Выбрать любого клиента из списка.
3. **Ожидание:** `order.client_id` обновляется, бейдж «Гостевой заказ» исчезает, блок «Клиент» переключается на отображение профиля выбранного клиента.

#### A4. Просмотр заказа авторизованного клиента (регресс)

1. Открыть заказ из S6 (с привязанным `client_id`).
2. **Ожидание:** бейджа «Гостевой заказ» нет. Блок «Клиент» показывает данные клиента из `client.profile` со ссылкой на его карточку.

#### A5. Admin → создание заказа (регресс)

1. В админке создать новый заказ через стандартный UI (`/orders/create`).
2. Выбрать клиента, заполнить позиции, сохранить.
3. **Ожидание:** работает как раньше (использует `POST /api/orders`, не публичный endpoint).

---

### Что проверить в БД руками (опционально)

```sql
-- Гостевые заказы за сегодня
SELECT id, order_number, client_id, email, phone, total_amount, created_at
FROM orders
WHERE client_id IS NULL AND created_at >= CURDATE();

-- Сопутствующие order_addresses
SELECT o.id, o.order_number, oa.recipient_first_name, oa.recipient_last_name,
       oa.recipient_phone, oa.country, oa.city, oa.address
FROM orders o
JOIN order_addresses oa ON oa.order_id = o.id
WHERE o.client_id IS NULL AND o.created_at >= CURDATE();

-- Использования промокодов гостями
SELECT pcu.id, pcu.promo_code_id, pcu.client_id, pcu.order_id, pcu.discount_amount, pc.code
FROM promo_code_usages pcu
JOIN promo_codes pc ON pc.id = pcu.promo_code_id
WHERE pcu.client_id IS NULL
ORDER BY pcu.id DESC LIMIT 20;
```

---

### Очистка тестовых заказов после прогона

> Только если ты тестируешь не на проде. На проде — никогда не дёргай.

```bash
php artisan tinker --execute="
\$ids = \App\Models\Order::query()
    ->whereNull('client_id')
    ->where(function(\$q){
        \$q->where('email','like','%@example.com')
          ->orWhere('email','like','guest%')
          ->orWhere('email','like','promo+%');
    })
    ->pluck('id');
echo 'Будут удалены заказы: '.\$ids->implode(', ').PHP_EOL;
\App\Models\OrderItem::whereIn('order_id', \$ids)->delete();
\App\Models\OrderAddress::whereIn('order_id', \$ids)->delete();
\App\Models\OrderHistory::whereIn('order_id', \$ids)->delete();
\App\Models\Order::whereIn('id', \$ids)->forceDelete();
echo 'Готово: '.\$ids->count().' заказов'.PHP_EOL;
"

# Чистка тестовых токенов клиента (если создавал createToken('manual-test'))
php artisan tinker --execute="
\DB::table('personal_access_tokens')->where('name','manual-test')->delete();
"
```

---

### Чек-лист «всё работает»

Прогони по всем пунктам в следующей сессии и отметь галочками. Backend-сценарии сгруппированы парой «гость ↔ клиент», чтобы тестировать их рядом.

**Гость (backend):**
- [ ] **S1** Гость без email → создан, `client_id=null`
- [ ] **S2** Гость с email → `email` сохранён в orders
- [ ] **S3** Гость + публичный промо → скидка применилась, в `promo_code_usages` запись с `client_id=null`
- [ ] **S4** Гость + персональный промо → `PROMO_REQUIRES_AUTH`
- [ ] **S5** Пустой payload → 422 с осмысленными ошибками
- [ ] **S8** Публичный просмотр по `view_token` отдаёт email гостя
- [ ] **S9** (опц.) Rate-limit срабатывает на 31-м запросе

**Клиент (backend):**
- [ ] **C1** Клиент через `/api/public/orders` без promo → `client_id` заполнен
- [ ] **C2** Клиент + публичный промо → скидка, в `promo_code_usages` запись с `client_id=$CLIENT_ID`
- [ ] **C3** Клиент + персональный промо, выданный ему → success
- [ ] **C4** Клиент + чужой персональный промо → `PROMO_NOT_FOR_CLIENT`
- [ ] **C5** Клиент повторно использует одноразовый промо → `PROMO_ALREADY_USED`
- [ ] **C6** Клиент через старый `/api/orders` → работает (регресс)
- [ ] **C7** `/api/orders/user` возвращает все заказы клиента из C1–C6
- [ ] **S7** Админский `POST /api/orders` без токена → не пропускает

**Витрина (UI nuxt-shop):**
- [ ] **U1** Гость в UI оформляет заказ, редирект на `/orders/{view_token}`
- [ ] **U2** UI-форма email-поле для гостя работает
- [ ] **U3** Авторизованный клиент в UI — старое поведение сохранено
- [ ] **U4** В DevTools нет `Authorization: Bearer null` для гостя
- [ ] **U5** В DevTools для клиента есть `Authorization: Bearer <реальный токен>`
- [ ] **U6** Клиент видит созданный заказ в `/profile/history`

**Админка (vue-admin):**
- [ ] **A1** Список заказов в админке показывает ФИО получателя для гостя
- [ ] **A2** Бейдж «Гостевой заказ» виден, контакты из заказа отображаются
- [ ] **A3** Можно вручную привязать клиента к гостевому заказу
- [ ] **A4** Заказ с клиентом — без бейджа (регресс)
- [ ] **A5** Создание заказа в админке через `/orders/create` работает (регресс)
- [ ] **A6** В админке у заказа клиента в `SideClient` есть кнопка перехода в карточку клиента (`/clients/{id}`)
- [ ] **A7** В админке `client_stats` (кол-во заказов, оборот) показывается для клиента и НЕ показывается для гостя
