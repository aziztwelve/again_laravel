# Задача: авто-создание клиента при гостевом заказе + маркеры ЛК в «Все клиенты»

**Статус:** Реализовано
**Дата:** 2026-07-12

## Описание

Раньше гостевой чекаут (см. [`guest-checkout.md`](./guest-checkout.md)) сознательно **не создавал** запись в `clients`: клиентская база оставалась «чистой», только зарегистрированные пользователи. По новому требованию бизнеса гость всё равно должен попадать в раздел **«Клиенты → Все клиенты»**, чтобы менеджеры видели всю аудиторию магазина.

Дополнительно в списке клиентов нужно визуально различать:

- **✅ с ЛК** — клиент имеет личный кабинет (хотя бы раз входил по OTP);
- **👤 без ЛК** — клиент создан автоматически (гостевой заказ или импорт) и ещё не входил.

Признак ЛК актуализируется **автоматически**: как только клиент впервые проходит OTP-вход, у него проставляется `verified_at`, и при следующей загрузке списка маркер меняется на ✅.

> Эта задача сознательно **меняет** прежнее решение из `guest-checkout.md` («Запись в таблице `clients` не создаётся»). Остальные аспекты гостевого чекаута сохранены.

---

## Бизнес-логика и решения

1. **Гость всё равно создаётся в `clients`.** При оформлении гостевого заказа находим существующего или создаём нового клиента.
2. **Заказ остаётся ГОСТЕВЫМ.** `orders.client_id = NULL` — не меняется. Клиент создаётся как побочный эффект для наполнения базы; связь клиент↔заказ только по совпадению контактов (email/phone), без FK. Бейдж «Гостевой заказ» в админке сохраняется.
3. **Дедупликация: email → затем phone.** Сначала ищем по `clients.email`, затем по нормализованному `user_profiles.phone`. Логика зеркалит `ClientImportService::findExistingClient`, чтобы не плодить дубли и переиспользовать уже импортированных клиентов.
4. **Если у гостя нет ни email, ни телефона** — клиента не создаём (создавать не из чего). На практике телефон получателя обязателен в чекауте, так что клиент создаётся почти всегда.
5. **Созданный клиент — «без ЛК».** Без `password`, без `verified_at`. Признак «есть ЛК» = `verified_at IS NOT NULL`.
6. **Определение «есть ЛК» = `verified_at`.** ЛК считается активным, когда клиент завершил OTP-вход хотя бы раз (`AuthenticatedSessionController::check_verification` проставляет `verified_at = now()`). Это единственный надёжный сигнал реального доступа в кабинет: пароль в текущей схеме не задаётся (route регистрации по паролю отключён).
7. **Устойчивость к ошибкам.** Создание клиента — вспомогательная операция. `GuestClientService` никогда не бросает исключение наружу: при любой ошибке пишет в лог и возвращает `null`, чтобы **не сорвать оформление заказа**.

---

## Изменения в БД

**Миграций нет.** Все нужные колонки уже существуют:

- `clients.email`, `clients.verified_at`, `clients.bonus_balance` — из `2025_05_31_095443_remove_user_id_and_email_to_clients.php`.
- `user_profiles.client_id` — из `2025_05_31_100236_add_client_id_to_user_profiles.php`. Профиль клиента связывается через `Client::profile()` (`hasOne(UserProfile::class)` по `user_profiles.client_id`).

---

## Изменённые файлы

### Backend (`lara_admin`)

- **Создан** `app/Services/Client/GuestClientService.php`
  Метод `findOrCreateFromOrderData(array $orderData): ?Client`:
  - извлекает `email` (`user.email`), `phone` (`recipient.phone` → fallback `user.phone`), ФИО, адрес;
  - если нет ни email, ни phone → `null`;
  - `findExistingClient()` — поиск по `clients.email`, затем по нормализованному `user_profiles.phone` (тот же digits-LIKE, что в `ClientController::index`);
  - `createGuestClient()` — создаёт `Client` (`email`, `bonus_balance = 0`, без `password`/`verified_at`) + профиль через `$client->profile()->create([...])`;
  - весь метод обёрнут в try/catch с логированием.

- `app/Http/Controllers/Api/Public/Order/PublicCheckoutController.php`
  - В конструктор добавлена зависимость `GuestClientService $guestClientService`.
  - В ветке гостя (`$orderClient === null`), внутри транзакции, после установки `guest_token`, вызывается `$this->guestClientService->findOrCreateFromOrderData($validated)`. **`orders.client_id` остаётся NULL.**

- `app/Http/Controllers/Api/Admin/ClientController.php`
  - В маппинг строки списка (`index`) добавлено поле `'has_account' => $client?->verified_at !== null`. Поле `verified_at` уже выбиралось.

### Frontend админки (`again_dashboard`)

- `src/models/client/Client.ts`
  - Добавлено поле `verified_at: string | null`, парсинг в `fromJSON` (`verified_at: json.verified_at ?? null`), проброс в `clone()`.
  - Добавлен геттер `get hasAccount(): boolean` (`verified_at !== null`).

- `src/components/clients/Table.vue`
  - Новая колонка **«ЛК»** после «Почта»: `✅` при `hasAccount`, иначе `👤`. Подсказка через `title` («Есть личный кабинет» / «Без личного кабинета»). Колонка «Активен» (по `deleted_at`) оставлена без изменений — это другой смысл.

---

## Поток данных (гостевой заказ)

```
1. Гость оформляет /checkout → POST /api/public/orders (без Bearer).
2. PublicCheckoutController::store:
   - Auth::guard('sanctum')->user() → null → $orderClient = null
   - unset($validated['client_id'])
   - $validated['guest_token'] = cookie
   - GuestClientService::findOrCreateFromOrderData($validated):
       email  = user.email (может быть null)
       phone  = recipient.phone ?? user.phone
       если email == null && phone == null → return null (клиента не создаём)
       иначе:
         найти по clients.email → иначе по user_profiles.phone
         если не найден → Client::create({email, bonus_balance:0})  // без ЛК
                          + profile()->create({ФИО, phone, адрес})
   - OrderCreationService::createOrder($validated, null):
       orders INSERT { client_id: NULL, email, view_token, ... }  // заказ ГОСТЕВОЙ
3. Позже клиент входит по email (OTP):
   - AuthenticatedSessionController::login → находит того же клиента по email
   - check_verification → verified_at = now()  // теперь «есть ЛК»
4. В админке «Все клиенты» строка клиента показывает ✅ вместо 👤.
```

---

## Автотесты

`tests/Feature/Client/GuestClientServiceTest.php` (использует `DatabaseTransactions`, запускать в окружении с MySQL — Docker/CI):

```bash
php artisan test --filter=GuestClientServiceTest
```

Покрытие: создание клиента+профиля с email; дедуп по email; дедуп по телефону при отсутствии email; `null` без контактов; создание отдельных клиентов для разных контактов; отражение `verified_at` в признаке ЛК.

## Smoke-тесты

| Сценарий | Ожидание |
|----------|----------|
| Гость с email оформляет заказ | В `clients` появился клиент с этим email, `verified_at = NULL`; заказ `client_id = NULL`; в админке — 👤 |
| Гость без email, только телефон | Клиент создан, профиль с телефоном; в админке — 👤 |
| Повторный гостевой заказ с тем же email | Дубль НЕ создаётся (найден по email) |
| Повторный заказ с тем же телефоном, другой email | Дубль НЕ создаётся (найден по phone в user_profiles) |
| Гость без email и без телефона (гипотетически) | Клиент НЕ создаётся, заказ создаётся штатно |
| Клиент входит по OTP после гостевого заказа | `verified_at` проставлен; в списке маркер меняется на ✅ |
| Авторизованный клиент оформляет заказ | Поведение не меняется: `client_id` заполнен, `GuestClientService` не вызывается |
| Ошибка при создании клиента | Заказ всё равно создаётся; ошибка в логах (`Guest client auto-create failed`) |

### Пример curl (гость с email)

```bash
BASE_URL="https://sub.againdev.ru"
curl -sk -X POST "$BASE_URL/api/public/orders" \
  -H 'Content-Type: application/json' \
  -d '{
    "user":{"first_name":"Тест","last_name":"Гость","phone":"+79991234567","email":"guest-lk@example.com"},
    "delivery_address":{"country":"Россия","city":"Москва","address":"ул. Тестовая, 1"},
    "items":[{"product_id":PRODUCT_ID,"quantity":1,"price":PRODUCT_PRICE}]
  }' | python3 -m json.tool
```

Проверка в БД:

```sql
SELECT c.id, c.email, c.verified_at, up.first_name, up.last_name, up.phone
FROM clients c
LEFT JOIN user_profiles up ON up.client_id = c.id
WHERE c.email = 'guest-lk@example.com';
-- Ожидание: клиент есть, verified_at IS NULL (👤 без ЛК)

SELECT id, client_id, email FROM orders WHERE email = 'guest-lk@example.com';
-- Ожидание: client_id IS NULL (заказ остаётся гостевым)
```

---

## Возможные риски и нюансы

- **Гость вводит email/телефон уже зарегистрированного клиента.** Мы найдём существующего клиента и НЕ создадим дубль. Но заказ всё равно останется гостевым (`client_id = NULL`) — владелец кабинета не увидит его в своей истории (это сознательное ограничение из `guest-checkout.md`).
- **Разные написания телефона.** Поиск идёт по «голым» цифрам (LIKE `%digits%`), как в `ClientController::index`. Возможны редкие ложные совпадения по частичному вхождению номера — приемлемо для дедупликации гостей.
- **`clients.email` не уникален** (unique-индекс снят миграцией `2025_07_16_040441`). Дубли по email мы предотвращаем на уровне сервиса, но исторические дубли из импорта возможны — берём первого найденного.
- **Рост клиентской базы.** Теперь каждый уникальный гость создаёт клиента. Это ожидаемо и есть цель задачи.
