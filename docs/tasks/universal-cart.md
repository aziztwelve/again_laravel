# Задача: Универсальная серверная корзина (гость + клиент)

**Статус:** План (backend + витрина). Развивает фичу «Брошенная корзина»
(`docs/tasks/abandoned-cart.md`) — после внедрения этой архитектуры цепочка
напоминаний работает и для гостей.
**Дата:** 2026-06-26
**Раздел:** Корзина / Чекаут (витрина `nuxt-shop` + backend `lara_admin`)

> Все три развилки закрыты (см. «Принятые решения»). Документ — план реализации,
> код ещё не написан.

---

### Принятые решения
- **(а) Статус корзины:** вводим явный `ACTIVE`. `enum('status',
  ['active','abandoned','ordered'])`, default `'active'`. Существующие
  `status IS NULL` бэкфиллим в `'active'`.
- **(б) Единственная личность:** правило «ровно одна личность» закрываем **на
  уровне приложения** в `CartResolver`. DB-level CHECK на MySQL 8.0 **невозможен**:
  колонка `client_id` участвует в FK `fk_user_id` с `ON DELETE SET NULL`, а MySQL
  запрещает использовать такие колонки в CHECK (ошибка 3823, проверено на Фазе 1).
  Отдельно решить поведение при удалении клиента (FK обнулит `client_id`, оставив
  корзину без личности) — назначать `guest_token` или удалять корзину.
- **(в) Согласие гостя на рассылку:** явный **opt-in** на витрине →
  `cart.marketing_consent` + `cart.consent_at`. Цепочка для гостей шлётся
  только при `marketing_consent = true`. Переиспользуем существующие
  `clients.subscribed_to_newsletter` (для клиентов) и `/api/public/unsubscribe`
  (для отписки).

---

## Цель

Сделать корзину **серверной сущностью первого класса для каждого посетителя** —
и авторизованного клиента, и анонимного гостя — через единую реализацию.

Сейчас гостевая корзина живёт только в `localStorage` витрины, а на сервер
попадает лишь после авторизации (`CartController::sync` требует
`auth('sanctum')->user()` типа `Client`). Из-за этого невозможно:
- слать гостям письма/Telegram/WhatsApp о брошенной корзине;
- восстанавливать корзину после очистки браузера и между устройствами;
- генерировать ссылки восстановления для гостей;
- собирать полную аналитику по брошенным корзинам (гости выпадают);
- корректно сливать гостевую активность при входе/регистрации.

**Принцип:** БД — единственный источник истины. `localStorage` — только
кратковременный UI-кэш, никогда не единственное хранилище корзины.

---

## Текущее состояние (точки опоры)

| Что | Где | Комментарий |
|-----|-----|-------------|
| Таблица `cart` | `2025_06_29_094627_create_cart_table.php` (+ доп. миграции) | `client_id` (nullable, FK→clients, `set null`), `status` enum nullable, `recovery_token`, `abandoned_at`, `total*`, `created_at`, `updated_at`, `ordered_at` |
| Модель `Cart` | `app/Models/Cart.php` | `timestamps=false`, `$guarded=['id']`, связи `client/items/order/communications` |
| Логика корзины | `Api\CartController` (НЕ админский, public-роуты `/cart-items/*`) | `sync()`, `add_item_to_cart`, `remove_single_item_from_cart`, `cancel_cart`, `cart_items` — содержат ветвление guest/auth |
| Цепочка напоминаний | `App\Services\Cart\AbandonedCartService` | `markAbandonedCarts()` требует `client_id`; `resolveChannel()` берёт контакты из `client->profile` |
| Привязка заказа | `App\Services\Order\OrderCreationService::linkCartToOrder` | ищет корзину только по `client_id` |
| Аналитика | `Api\Admin\CartAnalyticsController` | считает по `status`; конверсия `ordered/(ordered+abandoned)` |
| Восстановление | `Api\Public\Cart\CartRestoreController` + `recovery_token` | роут `GET /api/public/cart/restore/{token}` |
| Аутентификация | `config/sanctum.php` (`guard=web`, stateful), `config/cors.php` (`supports_credentials=true`) | **cookie-сессионная** SPA-аутентификация — куки уже ходят кросс-доменно |
| Согласия | `clients.subscribed_to_newsletter`, `clients.personal_data_consent`; `EmailNotificationChannel` (`/api/public/unsubscribe`) | переиспользуем для гостей |

### Почему cookie-подход реалистичен
Sanctum настроен в **stateful**-режиме (`guard => ['web']`), CORS —
`supports_credentials => true`, домены витрины в whitelist. Клиент уже
идентифицируется через cookie сессии, а не Bearer-токен. Значит HttpOnly
`guest_token`-cookie — это тот же механизм, а не новая инфраструктура.

---

## Новая архитектура

Каждый посетитель владеет серверной корзиной. Корзина принадлежит **либо**
клиенту, **либо** гостю:

```
                  POST /api/cart/items
                          │
                          ▼
                   CartResolver::resolve()
                          │
        ┌─────────────────┴──────────────────┐
        ▼                                     ▼
 auth('sanctum')->user()              cookie guest_token?
   = Client                                   │
        │                          ┌──────────┴───────────┐
        ▼                          ▼                      ▼
 cart по client_id           есть кука               куки нет
 (status=active)             cart по guest_token      создать cart +
 нет → создать                нет → создать +          UUID guest_token +
                              кука                      Set-Cookie (HttpOnly)
        └─────────────────────────┬──────────────────────┘
                                   ▼
                       update last_activity_at
                          return Cart
```

Контроллеры **не содержат** ветвления guest/auth — всё в `CartResolver`.

---

## Изменения схемы (миграции)

### 1. Расширить `cart`
| Поле | Тип | Описание |
|------|-----|----------|
| `guest_token` | `char(36)` UUID, nullable, **UNIQUE** | Личность гостя (значение в HttpOnly-cookie) |
| `email` | `string` nullable | Email гостя (с чекаута/формы) |
| `phone` | `string` nullable | Телефон гостя |
| `marketing_consent` | `boolean` default `false` | Явное согласие гостя на рассылку (opt-in) |
| `consent_at` | `timestamp` nullable | Момент согласия |
| `last_activity_at` | `timestamp` nullable | Последняя активность (детект брошенных) |
| `device_hash` | `string` nullable | (опц.) отпечаток устройства для merge/антифрод |
| `user_agent` | `string` nullable | (опц.) аналитика/фильтр ботов |
| `ip_address` | `string(45)` nullable | (опц.) аналитика |

### 2. Статус `ACTIVE` (решение «а»)
```sql
-- 1) расширить enum
ALTER TABLE cart MODIFY status ENUM('active','abandoned','ordered') NULL;
-- 2) бэкфилл
UPDATE cart SET status = 'active' WHERE status IS NULL;
-- 3) сделать NOT NULL DEFAULT
ALTER TABLE cart MODIFY status ENUM('active','abandoned','ordered') NOT NULL DEFAULT 'active';
```

### 3. Единственная личность (решение «б»)
**DB-level CHECK невозможен** в MySQL 8.0: `client_id` участвует в FK
`fk_user_id` c `ON DELETE SET NULL`, и MySQL запрещает такие колонки в
CHECK-constraint (ошибка 3823 — проверено на Фазе 1). Инвариант «client_id XOR
guest_token» обеспечивается в `CartResolver`:
- корзина всегда создаётся либо с `client_id`, либо с `guest_token`;
- при логине/мердже `guest_token` обнуляется (корзина становится клиентской);
- орфанные корзины (`client_id IS NULL`) на Фазе 1 бэкфиллены `guest_token=UUID()`.

Открытый нюанс: FK `ON DELETE SET NULL` при удалении клиента оставит корзину без
личности (оба поля NULL). Решить в Фазе 2 — назначать `guest_token` или удалять
такие корзины (например, через каскад/обсервер).

---

## `CartResolver` — сервис разрешения корзины

`App\Services\Cart\CartResolver`. Контроллеры вызывают только его.

```php
interface CartResolver
{
    /**
     * Вернуть актуальную ACTIVE-корзину текущего посетителя.
     * Создаёт корзину при первом добавлении товара. Бампит last_activity_at.
     * При необходимости ставит HttpOnly cookie guest_token (через очередь
     * cookie в Response).
     */
    public function resolve(Request $request): Cart;
}
```

Обязанности:
- определить авторизованного клиента (`auth('sanctum')->user()` типа `Client`);
- иначе — прочитать cookie `guest_token`;
- загрузить существующую `ACTIVE`-корзину или создать новую;
- сгенерировать `guest_token` (UUID) и поставить cookie, если её не было;
- обновить `last_activity_at` (и опц. `user_agent`/`ip_address`/`device_hash`);
- вернуть модель `Cart`.

Cookie: `guest_token`, **HttpOnly**, `SameSite=None; Secure` (прод — https),
срок жизни конфигурируемый (`config/cart.php` → `guest_cookie_days`, default
**365**). На `http://localhost` для дева — fallback (`SameSite=Lax`/без Secure)
через конфиг.

> **Важно (анти-взрыв таблицы):** корзина создаётся **только при первом
> добавлении товара** (`POST /cart/items`), а не на `GET /cart`. Плюс
> GC-команда чистки пустых/протухших гостевых корзин и фильтр ботов по
> `user_agent`.

---

## Жизненный цикл

```
   ACTIVE
     │  нет активности дольше таймаута (last_activity_at)
     ▼
   ABANDONED ──(клиент вернулся: добавил товар / открыл recovery)──► ACTIVE
     │  оформлен заказ
     ▼
   ORDERED
```

- Возврат `ABANDONED → ACTIVE` происходит при добавлении товара или открытии
  ссылки восстановления.
- `ORDERED` — терминальное состояние; останавливает цепочку.

---

## Гостевой чекаут

- Гость вводит email → пишем `cart.email`.
- Гость вводит phone → пишем `cart.phone`.
- Рядом — чекбокс **opt-in** «Получать напоминания о корзине и акции» (по
  умолчанию **выкл**). При установке: `marketing_consent=true`, `consent_at=now()`.
- Корзина становится eligible для цепочки напоминаний **только при согласии**.
- Создание аккаунта не требуется.

---

## Регистрация гостя (миграция корзины)

При регистрации/первом логине гостя:
1. найти корзину по `guest_token` (из cookie);
2. `client_id = <id клиента>`, `guest_token = NULL` (удовлетворяет CHECK);
3. **не создавать** вторую корзину;
4. сохранить позиции, `recovery_token`, историю `cart_communications`, таймстемпы.

---

## Стратегия слияния при логине (merge)

Если у клиента **уже есть** ACTIVE-корзина И есть гостевая по cookie:
- слить позиции (суммировать `quantity` по ключу
  `product_id + product_variant_id + color_id`);
- избегать дублей позиций;
- сохранить наиболее свежие таймстемпы / `recovery_token`;
- пересчитать `total*`;
- удалить гостевую корзину после слияния (или перенести `cart_communications`
  на основную — решить при ревью).

Точка интеграции: после успешного логина/регистрации
(`AuthenticatedSessionController`, `RegisteredUserController`) дёргать
`CartMerger::merge($clientCart, $guestCartToken)`.

---

## Брошенные корзины (обобщение существующей фичи)

Меняем `AbandonedCartService` так, чтобы гости и клиенты обрабатывались
**одинаково** (см. также `docs/tasks/abandoned-cart.md`):

- `markAbandonedCarts()`: критерий — `status='active'`, `last_activity_at`
  старше таймаута, **есть кому слать**:
  ```php
  ->where('status', 'active')
  ->where('last_activity_at', '<=', $threshold)
  ->whereHas('items')
  ->where(function ($q) {
      // клиент: согласие проверяем у клиента (subscribed_to_newsletter);
      // гость: только при явном opt-in
      $q->whereNotNull('client_id')
        ->orWhere('marketing_consent', true);
  })
  ```
- `resolveChannel(Cart $cart)`: контакты берём из самой корзины как fallback —
  `client?->profile?->telegram… ?: cart.phone/cart.email`. Сервис уведомлений
  **не должен знать**, авторизован ли владелец.
- Цепочка, идемпотентность (`cart_communications` UNIQUE `cart_id+step`), окно
  10–21 МСК — без изменений.

### Ручная отправка и «версии» для гостей
Фичи из `abandoned-cart.md` (шаги F, G) обобщаются на гостей этой архитектурой:
- **Ручная отправка** `POST /carts/{cart}/remind` (`AbandonedCartService::sendManual`,
  `type='manual'`) — канал/контакт берутся из самой корзины (`resolveChannel(Cart)`:
  `client?->profile…` иначе `cart.phone/cart.email`), с проверкой `marketing_consent`
  для гостей.
- **Счётчик версий** `versions_count` — агрегат по идентичности: для гостя
  группируем по `guest_token`, для клиента — по `client_id` (после merge/миграции
  «версии» гостя естественно объединяются с клиентскими).

---

## Восстановление

- Ссылка: `/cart/recovery/{token}` (новый формат; старый `/cart/restore/{token}`
  оставляем как алиас для обратной совместимости).
- Найти корзину по `recovery_token`.
- `status='active'`, бампнуть `last_activity_at`.
- Вернуть текущий состав (с актуальными ценами через `CartPriceController`-пайплайн).

---

## API (единые эндпоинты)

Заменяем прямой lookup на `CartResolver`. Работают **идентично** для гостя и
клиента, без ветвления в контроллере:

| Метод | Назначение |
|-------|-----------|
| `GET /api/cart` | вернуть корзину (не создаёт, если пусто) |
| `POST /api/cart/items` | добавить позицию (здесь создаётся корзина + cookie) |
| `PATCH /api/cart/items` | изменить количество |
| `DELETE /api/cart/items/{id}` | удалить позицию |

Старые роуты `/api/cart-items/*` оставляем алиасами на тот же контроллер на
переходный период (обратная совместимость витрины).

---

## Аналитика

`CartAnalyticsController` дополнить разрезом по типу владельца:
- abandoned rate, recovery rate, conversion rate;
- **guest conversion** vs **registered conversion** (по `client_id IS NULL`);
- средняя стоимость корзины (по брошенным — как в `abandoned-cart.md`, решение #6);
- эффективность коммуникаций (по `cart_communications`).

---

## Обратная совместимость

- Существующие корзины клиентов работают без миграции данных (`client_id`-путь
  не ломается; `status IS NULL` бэкфиллится в `'active'`).
- Гостевые корзины из `localStorage` синхронизируются на сервер при первой
  загрузке обновлённой витрины (`POST /api/cart/items` пачкой), далее
  `localStorage` — только UI-кэш.

---

## План реализации (backend)

1. Миграция: новые поля `cart`, enum `ACTIVE` + бэкфилл, CHECK-constraint.
2. `config/cart.php`: `guest_cookie_days`, `cookie_same_site`, `cookie_secure`,
   `bot_user_agents`.
3. `CartResolver` + `CartMerger` сервисы.
4. Рефактор cart-контроллера на `CartResolver` (убрать guest/auth-ветвление);
   новые роуты `/api/cart/*` + алиасы старых.
5. Хук merge/миграции корзины в логин/регистрацию.
6. Обобщить `AbandonedCartService` (гости + согласие) и `resolveChannel`.
7. Обобщить `OrderCreationService::linkCartToOrder` (искать по `client_id` ИЛИ
   `guest_token`/cookie на чекауте).
8. Поддержать согласие: поля + opt-in + расширить `/unsubscribe` на гостей.
9. Восстановление `/cart/recovery/{token}` (+ алиас) → `ACTIVE`.
10. GC-команда чистки пустых/протухших гостевых корзин + фильтр ботов.
11. Аналитика: guest/registered conversion.
12. Тесты (ниже).
13. (из `abandoned-cart.md`) Ручная отправка `POST /carts/{cart}/remind` и счётчик
    `versions_count` — должны корректно работать по `guest_token` (контакт из
    корзины, группировка версий по идентичности гостя).

## План (витрина `nuxt-shop`)
- При первой загрузке: однократный sync `localStorage → POST /api/cart/items`.
- Перейти на cookie-identity (`withCredentials`), `localStorage` → UI-кэш.
- Opt-in чекбокс на чекауте, передача `consent`.
- Страница `/cart/recovery/{token}` (подстановка позиций).

---

## Тесты

- `CartResolver`: гость без куки → создаётся корзина + ставится cookie;
  гость с кукой → та же корзина; клиент → корзина по `client_id`.
- Гарантия «одна личность» (CHECK + сервис).
- Merge при логине: суммирование количеств, дедуп, удаление гостевой.
- Миграция корзины при регистрации (сохранены items/recovery/communications).
- Брошенные: гость с `marketing_consent=true` попадает в цепочку; без согласия —
  нет; клиент — по `subscribed_to_newsletter`.
- `resolveChannel` для гостя (email/phone из корзины).
- Восстановление `/cart/recovery/{token}` → `ACTIVE` + 404 на неверный токен.
- Привязка заказа гостя по `guest_token`.
- GC пустых гостевых корзин.
- Ручная отправка `POST /carts/{cart}/remind` для гостя: контакт из корзины,
  `type='manual'`, троттлинг; отказ без согласия гостя.
- `versions_count` для гостя считается по `guest_token`; после merge версии
  гостя и клиента объединяются.

---

## Безопасность / граничные случаи

- **Взрыв таблицы от ботов:** корзина только при первом `POST /cart/items`;
  GC-команда; фильтр `user_agent`.
- **Кросс-доменная cookie:** прод — `SameSite=None; Secure` (https ок); дев —
  fallback через конфиг.
- **CSRF:** изменяющие cart-роуты под stateful-сессией — проверить CSRF/throttle.
- **PII гостя** (`email/phone/ip/user_agent`): хранение и рассылка — строго при
  `marketing_consent`; обязательная unsubscribe-ссылка; уважать `personal_data_consent`.
- **Анти-спам:** не слать без согласия/контакта; идемпотентность шагов; окно
  10–21 МСК.
- **Гонки merge:** транзакция при слиянии; идемпотентность.
- **Часовой пояс** при таймауте и окне отправки (МСК).

---

## Открытые вопросы

1. **`cart_communications` при merge гостевой → клиентской** — переносить историю
   или удалять вместе с гостевой корзиной? (предложение: переносить).
2. **device_hash / антифрод** — нужен ли в MVP или фаза 2?
3. **Срок жизни гостевой cookie** — 365 дней ок? (решение по умолчанию — да).
4. **Гостевой захват email до чекаута** (поп-ап) — отдельная задача витрины?
5. **Юридическое** — финальная формулировка opt-in согласия (152-ФЗ / закон о
   рекламе) — согласовать с заказчиком.

---

## Связанные документы
- `docs/tasks/abandoned-cart.md` — триггерная цепочка и аналитика (эта задача
  снимает ограничение «только авторизованным»).
- `docs/tasks/guest-checkout.md` — гостевой чекаут.
- `docs/tasks/utm-tracking.md` — паттерн аналитического раздела во фронте.

---

## Прогресс реализации (обновлено 2026-06-26)

### ✅ Фаза 1 — Схема и статус (готово, протестировано)
Миграция `2026_06_26_000001_universalize_cart_table.php` (применена к dev `laravel`
и `testing`):
- Колонки `cart`: `guest_token` (UNIQUE), `email`, `phone`, `marketing_consent`,
  `consent_at`, `last_activity_at`, `device_hash`, `user_agent`, `ip_address`.
- Статус → `enum('active','abandoned','ordered')`, бэкфилл `NULL→'active'`,
  `NOT NULL DEFAULT 'active'`. Бэкфилл `last_activity_at` и `guest_token` орфанам.
- **CHECK-constraint НЕ добавлен** (MySQL ошибка 3823: `client_id` в FK с
  `ON DELETE SET NULL`). Инвариант — на уровне `CartResolver`.
- `Cart` casts; `whereNull('status')`→`where('status','active')` в CartController
  (4×) / AbandonedCartService / OrderCreationService. Тест обновлён.
- Тесты: 15/15 (AbandonedCart 6 + UTM 9).

### ✅ Фаза 2 — CartResolver + CartMerger + guest cookie (готово, протестировано)
- `config/cart.php` (cookie name/days/domain/secure/same_site + bot_user_agents).
- `App\Services\Cart\CartResolver` — `resolveOrCreate` / `resolveActive` /
  `currentClient` / `readGuestToken` / `queueGuestCookie` / `touch`.
- `App\Services\Cart\CartMerger::attachGuestCartToClient` — миграция или
  мердж+дедуп+удаление гостевой.
- `bootstrap/app.php` — `guest_token` в encryptCookies except + добавлен
  `AddQueuedCookiesToResponse` в api-группу (иначе `Cookie::queue` не работает,
  т.к. `statefulApi()` отключён).
- `CartController` рефакторён на `CartResolver` (убрано guest/auth-ветвление),
  `sync(Cart,array,bool)`.
- Роуты `/api/cart/*` (GET `/`, POST `/items`, POST `/items/bulk`, PATCH
  `/items`, PATCH `/contact`, DELETE `/items`, DELETE `/cancel`); старые
  `/cart-items/*` — алиасы.
- Merge-хук в `AuthenticatedSessionController::check_verification` (вход клиента)
  + `Cookie::forget(guest_token)`.
- Тесты: `UniversalCartTest` 7/7; общий прогон 22/22.

### ✅ Фаза 3 — Обобщение брошенных на гостей (готово, протестировано)
Реализовано:
- `AbandonedCartService::markAbandonedCarts` — детект по
  `COALESCE(last_activity_at, updated_at, created_at)`, убран фильтр `client_id`
  (гости тоже помечаются — для аналитики).
- `processChain` — убран фильтр `client_id`, вызывает `resolveChannel($cart)`.
- `resolveChannel(Cart $cart)` — контакты из профиля клиента ИЛИ из корзины
  (`cart.email`/`cart.phone`); для гостя отправка только при `marketing_consent`.
- `OrderCreationService::linkGuestCartToOrder` (по `guest_token`) +
  ветка в `createOrder`; `PublicCheckoutController` прокидывает `guest_token`
  из cookie в orderData гостя.
- `CartRestoreController::show` — оживление `abandoned→active` + `last_activity_at`.
- Роут `GET /api/public/cart/recovery/{token}` (канонический) + `/restore`
  (алиас).
- `CartController::update_contact` + роут `PATCH /api/cart/contact` (захват
  email/phone/consent → `marketing_consent`/`consent_at`).

Тесты: 30/30 (113 assertions) — `AbandonedCartTest` 14 (гость: пометка, канал по
email, отказ без согласия, отправка шага с согласием, failed без согласия,
recovery→active, update_contact, гостевая привязка заказа по `guest_token`) +
`UniversalCartTest` 7 + UTM 9.

### ✅ Фаза 4 — Ручная отправка (F) + счётчик версий (G) (готово; backend протестирован)
Backend:
- Миграция `2026_06_27_000001_make_cart_communications_step_nullable` — `step`
  nullable (ручные отправки пишутся со `step=NULL`, триггерные шаги 1/2 сохраняют
  UNIQUE).
- `config/abandoned_cart.php` → `manual_throttle_minutes` (10).
- `AbandonedCartService::sendManual(Cart, ?channel)` — проверки
  `not_eligible`/`no_consent`/`throttled`/`no_contact`, `type='manual'`,
  dispatch job; `recipientForChannel`.
- `CartController::remind` → `POST /api/carts/{cart}/remind` (422 с понятными
  сообщениями); `carts()` — `versions_count` коррелированным подзапросом (по
  `client_id`/`guest_token`).
- Тесты: 26/26 (sendManual sends/throttled/guest-no-consent, remind endpoint,
  versions_count в списке).

Frontend (`again_dashboard`):
- `AbandonedCartTable` — кнопка-самолётик (POST remind, disabled для `ordered`),
  «N версий» под ID, тип `manual → «Вручную»`.
- `useAbandonedCartFunctions.sendReminder`; `Index` — `onRemind` + `sendingId`.
- ⚠️ Production-сборка фронта не прогнана из-за нехватки памяти на хосте
  (запущен dev-сервер `serve` + `fork-ts-checker`, свободно ~1.9 ГБ → build с
  лимитом 1.5 ГБ уходит в таймаут). Изменения горячо компилируются запущенным
  `serve`. Полную сборку гонять при свободной памяти: `NODE_OPTIONS=--max-old-space-size=1536 npx vue-cli-service build`.

### ✅ Фаза 5 — Аналитика guest/registered + GC-команда (готово, протестировано)
- `CartAnalyticsController` — блок `segments` (`guest` / `registered`):
  `abandoned`, `ordered`, `total`, `rate` (%), `lost_revenue`, `revenue`
  (разрез по `client_id IS NULL`).
- `config/cart.php` → `gc.empty_guest_ttl_hours` (48), `gc.guest_retention_days` (90).
- `GcGuestCartsCommand` (`cart:gc-guest-carts`, флаг `--dry-run`): удаляет пустые
  гостевые корзины старше TTL и протухшие `active`/`abandoned` гостевые старше
  retention; явно удаляет `cart_items` (FK = ON DELETE SET NULL, не cascade);
  **не трогает** клиентские и `ordered`. Расписание — `dailyAt('04:30')`.
- Тесты: 40/40 (GC удаляет пустые/протухшие, сохраняет клиентские/ordered;
  segments аналитики). Dry-run на dev — ок.

### Backend (Фазы 1–5) — ЗАВЕРШЁН.

### ✅ Витрина `again_front` (Nuxt) — готово, собирается (`nuxt build`, EXIT=0)
- `composables/useApi.ts` — `credentials: 'include'` (HttpOnly cookie `guest_token`
  ходит на бэк; CORS `supports_credentials=true` уже включён).
- `composables/useServerCart.ts` — `mirrorCart()` (POST `/cart/items/bulk` —
  зеркалит локальную корзину на сервер), `saveContact()` (PATCH `/cart/contact`),
  `fetchRecovery()` (GET `/public/cart/recovery/{token}`).
- `plugins/cart-sync.client.ts` — первичная синхронизация localStorage→сервер
  (`onNuxtReady`) + зеркалирование изменений корзины (`watchDebounced`).
- `pages/cart/recovery/[token].vue` — восстановление корзины по ссылке из письма:
  тянет состав, подставляет в localStorage, ведёт на `/cart`.
- `components/Checkout/User.vue` — opt-in чекбокс согласия на рассылку (гость):
  при валидном email сохраняет `email`+`consent` в серверной корзине
  (`saveContact`, debounce) → корзина становится eligible для напоминаний.

Примечание: локальная корзина (`useLocalStorage('cart')`) осталась источником
истины для UI и зеркалится на сервер; полный переход «сервер — единственный
источник» можно сделать позже отдельной итерацией (UX-рефактор cart store).

### (Опц.) Осталось:
- **Фронт `again_dashboard`:** вывод разреза guest/registered из
  `analytics.segments` (данные уже есть на бэке).
### ⬜ Витрина `nuxt-shop` — sync localStorage→API, cookie-identity, opt-in,
   страница `/cart/recovery/{token}` — не начато

### Замечания по окружению
- Тесты гонять через `vendor/bin/phpunit`, не `artisan test` (консольный бутстрап
  инстанцирует команды → `ConversationService`→`VKAdapter`→падает на пустом
  `vk_settings`). В `testing` БД добавлена тех-строка `vk_settings`.
- Прод-`ALTER TABLE cart` (Фаза 1) — делать в окно при большом объёме.
