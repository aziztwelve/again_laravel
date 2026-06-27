# Задача: Брошенная корзина (триггерная цепочка + аналитика)

**Статус:** Backend (шаги A–E) + фронт `again_dashboard` реализованы. Цепочка и
аналитика работают для **авторизованных клиентов**. Поддержка **гостей**
вынесена в отдельную задачу — см. `docs/tasks/universal-cart.md` (универсальная
серверная корзина).
**Дата:** 2026-06-24 (обновлено 2026-06-26)
**Раздел админки:** Аналитика → Брошенные корзины

> ⚠️ **Архитектурное развитие.** Изначально цепочка слала только авторизованным
> клиентам (решение #5). Задача `universal-cart.md` делает корзину серверной
> сущностью для каждого посетителя (гость по HttpOnly-cookie `guest_token`,
> клиент по `client_id`) и снимает это ограничение: после её внедрения брошенные
> корзины гостей с согласием на рассылку тоже попадают в цепочку. Связанные
> изменения, затрагивающие эту фичу, помечены ниже блоком **[universal-cart]**.

> Все открытые вопросы закрыты. Детали реализации — в разделе «Реализация (backend)».

---

## Описание

Добавить сценарий стимуляции оформления заказа из корзины. Если пользователь
положил товар в корзину, но не оформил заказ, через **24 часа** запускается
**цепочка сообщений** с напоминанием «товар уже в корзине, осталось оплатить» +
ссылка на оформление. Параллельно — раздел **аналитики по брошенным корзинам**
(средняя стоимость, упущенный доход, динамика, конверсия в заказ, разрез по
каналам коммуникации).

**Сценарий пользователя:** сайт → Корзина → (24 ч бездействия) → письмо/сообщение
с напоминанием и ссылкой на оформление.

**План-результат:** пользователь положил товар в корзину → через 24 ч получает
цепочку сообщений о том, что товар в корзине и осталось оплатить + аналитика по
корзинам в админке.

**Факт-результат (сейчас):** функционала триггерной цепочки нет. Есть базовая
инфраструктура корзин и черновая аналитика (см. «Что уже есть в коде»), но
автоматического определения брошенных корзин, отправки напоминаний и UI-раздела —
нет.

---

## Что уже есть в коде (точки опоры)

| Что | Где | Комментарий |
|-----|-----|-------------|
| Таблица `cart` | миграция `2025_06_29_094627_create_cart_table.php` | `id`, `client_id` (nullable, FK→`clients`), `status` enum `['abandoned','ordered']` nullable, `created_at`, `updated_at`, `ordered_at` |
| Доп. поля корзины | `2025_07_05_200200_add_total_to_cart.php`, `2025_07_06_090837_add_updated_at_field_to_cart.php` | `total`, `total_original`, `total_discount`, `updated_at` |
| Таблица `cart_items` | `2025_06_29_095111_create_cart_items_table.php` (+ `2025_07_05_195914_add_price_to_cart_items.php`) | `cart_id`, `product_id`, `product_variant_id`, `color_id`, `quantity`, `price`, `price_original`, `total`, `total_original`, `total_discount` |
| Модели | `app/Models/Cart.php`, `app/Models/CartItem.php` | `Cart::$table = 'cart'`, `timestamps=false`; связи `client`, `items`, `product`, `productVariant`, `color`; аксессор `CartItem::getTotalPriceAttribute()` |
| Список корзин (админка) | `app/Http/Controllers/Api/Admin/CartController@carts` | Фильтр `status` (`abandoned|ordered`), `date_from`/`date_to`, пагинация |
| Черновая аналитика | `app/Http/Controllers/Api/Admin/CartAnalyticsController@cartAnalytics` | Уже считает `abandoned/ordered` счётчики, `lost_revenue`, `average_order_value`, `top_products` |
| Синхронизация корзины + пометка `abandoned` | `CartController@sync`, `@cancel_cart` | «Активная» корзина = `status IS NULL`. `cancel_cart` ставит `status='abandoned'` вручную |
| Очередь уведомлений | `app/Services/Notifications/NotificationService.php`, `Jobs/SendNotificationJob.php` | Каналы: `email`, `telegram`, `whatsapp`, `vk`, `web_chat`. Шлём через `SendNotificationJob::dispatch($channel, $recipientId, $message, $data)` |
| Пример scheduled-команды + рассылки | `app/Console/Commands/BirthdayDiscountCommand.php`, `routes/console.php` | Эталон стиля: `Schedule::command('birthday:process')->dailyAt('10:00')`; внутри — `SendNotificationJob` |
| Публичный пересчёт цен корзины | `app/Http/Controllers/Api/Public/Cart/CartPriceController.php` | Используется витриной перед чекаутом |
| Email-инфраструктура | `app/Notifications/MailNotification.php`, `app/Services/Email/EmailService.php`, `Services/Messaging/Adapters/EmailAdapter.php` | Отправка писем клиенту |

### Семантика статуса корзины (важно)
- `status IS NULL` — активная (открытая) корзина.
- `status = 'abandoned'` — брошенная.
- `status = 'ordered'` — оформлен заказ.

> **[universal-cart]** В рамках `universal-cart.md` вводится явный статус
> `'active'` вместо `NULL` (`enum('active','abandoned','ordered')`, default
> `'active'`, существующие `NULL` бэкфиллятся). Все запросы `whereNull('status')`
> заменяются на `where('status','active')`. Семантика `abandoned`/`ordered` не
> меняется.

---

## Главные пробелы (что нужно построить)

1. **Нет автоопределения брошенных корзин.** Сейчас `abandoned` ставится только
   вручную в `cancel_cart`. Нужен scheduled-процесс: «активная корзина не
   обновлялась 24 ч → пометить `abandoned` и запустить цепочку».
2. **Нет триггерной цепочки сообщений** (шаги 24ч / 48ч / 72ч — см. #2 в вопросах).
3. **Корзина никогда не помечается `ordered` автоматически.** При оформлении
   заказа (`PublicCheckoutController`, `Admin/OrderController`) статус корзины не
   обновляется → конверсия и «заказанные» в аналитике сейчас не наполняются.
   **Нужно связать заказ с корзиной** (см. модель данных).
4. **Нет журнала коммуникаций** (когда, по какому каналу, какой шаг цепочки
   отправлен) — нужен для колонок «Канал» / «Коммуникация» / «Тип коммуникации»
   из скринов и для защиты от повторной отправки.
5. **Нет ссылки на оформление** для брошенной корзины (восстановление корзины по
   токену из письма).
6. **UI-раздела «Брошенные корзины»** в `again_dashboard` нет.

---

## Эталон UI (со скринов InSales)

Скрины: `/home/aziz/Pictures/photo_2026-06-02+15.39.34.jpeg`,
`/home/aziz/Pictures/photo_2026-06-02+15.07.06.jpeg`.

Дашборд «Брошенные корзины»:
- Переключатель периода: `7 дней` / `30 дней` / произвольный диапазон.
- Карточки-метрики:
  - **Средняя стоимость корзины** (пример: 6 246 ₽).
  - **Упущенный доход** (сумма брошенных, пример: 387 230 ₽).
  - **Незаказанные брошенные корзины** (кол-во, пример: 62 шт.).
- График **«Динамика корзин»**: серии «Брошенные корзины» (62 шт.) и
  «Заказы» (24 шт. на 142 020 ₽) по дням периода.
- Круговая **«Конверсия в заказ»** (заказы / все корзины).
- Таблица корзин с колонками: **ID корзины** (+ подпись «N версий» — число
  корзин этого покупателя, см. «Счётчик версий корзины»), **Покупатель** (имя ·
  телефон · email), **Последнее обновление**, **Статус корзины**
  (`Брошенная`/`Заказанная`), **Позиций** (кол-во шт.), **Сумма**, **Канал**
  (иконка: email/telegram/…), **Коммуникация** (дата отправки напоминания),
  **Тип коммуникации** (`По триггеру` / `Вручную`), **действие в строке** —
  иконка-самолётик «отправить напоминание вручную» (см. «Ручная отправка
  напоминания»). Поиск, Фильтры, «Список товаров».

---

## Определения метрик

| Метрика | Определение |
|---------|-------------|
| **Брошенная корзина** | Корзина со `status='abandoned'`, не превратившаяся в заказ |
| **Незаказанные брошенные** | `count(status='abandoned')` за период |
| **Упущенный доход** | `SUM(cart.total)` по брошенным за период |
| **Средняя стоимость корзины** | `AVG(cart.total)` (по какому множеству — см. #6) |
| **Заказы (из корзин)** | `count(status='ordered')` за период |
| **Оборот заказов** | `SUM(cart.total)` по `ordered` |
| **Конверсия в заказ** | `ordered / (ordered + abandoned) * 100%` |

---

## Модель данных (предлагаемая)

### 1. Связать заказ с корзиной + пометка `ordered` ✅ решено

**Решение:** добавляем `orders.cart_id` (FK→`cart`, nullable, `onDelete('set null')`).

Обоснование (направление FK):
- **Конвенция «событие → источник».** Заказ создаётся из корзины, заказ —
  более позднее/дочернее событие и хранит ссылку на свой источник (как
  `order.basket_id` в типовых e-commerce схемах).
- **Долговечность.** `orders` не удаляются; `cart` — расходная сущность (пустые
  корзины удаляются в `CartController`). Ссылку держим на долговечной записи.
- **Обратная атрибуция «бесплатно».** Со стороны `orders` считаем «сколько
  заказов/на какую сумму пришло из восстановленных корзин» — окупаемость рассылки.
- **Конверсия не зависит от направления FK** — она считается по
  `cart.status='ordered'` + `ordered_at`; FK нужен только для drill-down и точной
  суммы заказа.

Eloquent:
```php
// Order.php
public function cart(): BelongsTo { return $this->belongsTo(Cart::class); }
// Cart.php
public function order(): HasOne { return $this->hasOne(Order::class); } // по cart_id
```

При оформлении заказа в `PublicCheckoutController::store` и
`Admin/OrderController::store` находим активную/брошенную корзину клиента, ставим
`cart.status='ordered'`, `cart.ordered_at=now()` и `orders.cart_id = $cart->id`.
Это наполняет «Заказы»/«Конверсию» и останавливает цепочку. Гостевые и
несинхронизированные корзины оставляют `cart_id=null` — это нормально.

### 2. Токен восстановления корзины
`cart.recovery_token` (string, unique, nullable) — генерится при пометке
`abandoned`. Ссылка в письме: `{SHOP_URL}/cart/restore/{recovery_token}` →
витрина восстанавливает позиции и ведёт на чекаут. Подтвердить формат ссылки с
фронтом витрины (`nuxt-shop`).

### 3. Журнал коммуникаций — таблица `cart_communications`
| Поле | Тип | Описание |
|------|-----|----------|
| `id` | bigint PK | |
| `cart_id` | FK→`cart` | |
| `channel` | string | `email` / `telegram` / `whatsapp` / `vk` (из `NotificationService`) |
| `step` | tinyint | Номер шага цепочки (1 = 24ч, 2 = 48ч, …) |
| `type` | string | `trigger` («По триггеру») — на будущее можно `manual` |
| `status` | string | `queued` / `sent` / `failed` |
| `sent_at` | timestamp nullable | Для колонки «Коммуникация» |
| `timestamps` | | |

Зачем: колонки «Канал/Коммуникация/Тип» в таблице + идемпотентность (не слать
один шаг дважды).

### 4. (Опц.) Поля на `cart` для цепочки
`abandoned_at` (timestamp) — фиксируем момент перехода в `abandoned` (отдельно от
`updated_at`, который меняется при правках позиций). Удобно для расчёта «когда
слать следующий шаг».

---

## Backend: план реализации

### Шаг A. Определение брошенных корзин (scheduled)
Команда `php artisan cart:process-abandoned` (по аналогии с
`BirthdayDiscountCommand`):
1. Найти активные корзины (`status IS NULL`), у которых `updated_at <= now()-24h`
   и есть `client_id` (есть кому слать) и `items` не пусто.
2. Пометить `status='abandoned'`, `abandoned_at=now()`, сгенерить
   `recovery_token`.
3. Поставить в очередь первый шаг цепочки (`SendNotificationJob`) и записать
   строку в `cart_communications`.

Регистрация в `routes/console.php`:
```php
Schedule::command('cart:process-abandoned')->hourly()->withoutOverlapping();
```
Частота — `hourly` (решение #3). Отправка только в окне **10:00–21:00** (TZ
магазина, МСК): ночные срабатывания откладывают шаг на 10:00. Детект `abandoned`
может идти и ночью; ограничение по окну применяется к самой отправке сообщения.

### Шаг B. Цепочка сообщений
Сервис `App\Services\Cart\AbandonedCartService`. Шаги (решение #2): **2 касания**
— шаг 1 через 24 ч, шаг 2 через 72 ч (offset от `abandoned_at`). Для каждого шага:
- проверить, что корзина всё ещё `abandoned` (не `ordered`) и не пуста;
- проверить, что шаг ещё не отправлялся (`cart_communications` по `cart_id+step`);
- проверить окно отправки 10:00–21:00 МСК (иначе отложить);
- выбрать **один приоритетный канал** (решение #4): `telegram → email →
  whatsapp → vk` — первый доступный контакт клиента;
- `SendNotificationJob::dispatch($channel, $recipientId, $message, $data)`;
- записать `cart_communications` (channel, step, type=`trigger`, status, sent_at).

Выбор канала — переиспользовать `NotificationService` (уже поддерживает
email/telegram/whatsapp/vk/web_chat). Промокод на шаге 2 — фаза 2 (не MVP).

### Шаг C. Пометка `ordered`
В точках создания заказа (`PublicCheckoutController::store`,
`Admin\OrderController::store`) найти активную/брошенную корзину клиента и
проставить `cart.status='ordered'`, `cart.ordered_at=now()`,
`orders.cart_id=$cart->id` (решение #1). Останавливает цепочку и наполняет
конверсию.

### Шаг D. Восстановление корзины (публично)
`GET /api/public/cart/restore/{recovery_token}` → вернуть позиции корзины (с
актуальными ценами через существующий `CartPriceController`-пайплайн) для
подстановки на витрине + редирект/ссылка на чекаут. Публичный, `throttle`.

### Шаг E. Аналитика (расширить существующее)
Привести `CartAnalyticsController@cartAnalytics` к виду со скринов:
- карточки: `average_cart_value` (**среднее по брошенным**, `AVG(total) WHERE
  status='abandoned'` — решение #6; сейчас в коде считается по `ordered`,
  поправить), `lost_revenue`, `abandoned_count`;
- `chart` динамики по дням (`abandoned` vs `ordered`) — стиль
  `OrderStatsController` (period from/to, granularity);
- `conversion` (для круговой);
- в списке (`CartController@carts`) добавить агрегаты: кол-во позиций
  (`items_count`/`items_qty`), последний `cart_communications` (канал, дата, тип).

### Шаг F. Ручная отправка напоминания (из админки)

Кнопка-самолётик в строке таблицы и/или на drill-down карточки корзины.
Позволяет менеджеру отправить напоминание вне триггерной цепочки.

- **Эндпоинт:** `POST /carts/{cart}/remind` (admin, авторизация админа).
- **Логика:** переиспользуем `AbandonedCartService` — выделить публичный метод
  `sendManual(Cart $cart, ?string $channel = null)`:
  - проверить, что корзина не `ordered` и не пуста;
  - выбрать канал (явный из запроса или приоритетный через `resolveChannel`);
  - `SendNotificationJob::dispatch(...)` с тем же `buildMessage`;
  - записать `cart_communications` с **`type='manual'`** (в отличие от
    `trigger`), `step=0` (или `NULL`) — чтобы не конфликтовать с UNIQUE
    `cart_id+step` триггерных шагов и не мешать идемпотентности цепочки.
- **Идемпотентность:** ручная отправка **не** ограничена UNIQUE-шагами (менеджер
  может слать повторно); но защищаем троттлингом (например, не чаще 1 раза в N
  минут на корзину) и проверкой наличия контакта/согласия.
- **Ответ:** `{ success, communication: {channel, type:'manual', sent_at} }` —
  фронт обновляет колонки «Канал/Коммуникация/Тип» в строке.
- **Колонка «Тип коммуникации»:** `trigger → «По триггеру»`,
  `manual → «Вручную»`.

> **[universal-cart]** Ручная отправка обязана работать и для гостей: канал/контакт
> берётся из самой корзины (`resolveChannel(Cart $cart)` — email/phone из `cart`),
> с теми же проверками согласия (`marketing_consent`).

### Шаг G. Счётчик версий корзины («N версий»)

На скрине под ID корзины выводится «N версий» — сколько корзин у этого
покупателя. Реализуем **без новой схемы**, как агрегат по идентичности.

- **Определение:** `versions_count` = число строк `cart` с той же идентичностью
  покупателя:
  - для клиента — `COUNT(*) WHERE client_id = :client_id`;
  - **[universal-cart]** для гостя — `COUNT(*) WHERE guest_token = :guest_token`.
  - Со временем у одного покупателя накапливается несколько корзин
    (`active`/`abandoned`/`ordered`), т.к. после заказа создаётся новая активная
    корзина — это и есть «версии».
- **Где считаем:** в `CartController@carts` отдаём поле `versions_count` для
  каждой строки (подзапрос-агрегат, чтобы не делать N+1).
- **UI:** подпись под ID корзины «N версий» (как на скрине). Опционально —
  drill-down: список всех корзин покупателя по этой идентичности.
- **Альтернатива (фаза 2):** отдельная таблица `cart_sessions` / снапшоты
  изменений корзины — если потребуется история правок состава, а не просто
  счётчик. В MVP не нужна.

---

## Шаблон письма (1-й шаг, 24 ч)

Тема: **В вашей корзине остались товары**

```
В вашей корзине остались товары.
Завершить оформление заказа можно прямо сейчас на сайте: {ссылка на восстановление корзины}

Состав заказа:
- Менструальные трусы Light AGAIN (бесшовные) (XS / Чёрный). 1 990 ₽ x 1 шт
- Comfort AGAIN (бесшовные) (XS / Чёрный). 2 090 ₽ x 1 шт
- Save AGAIN (XS / Чёрный). 2 490 ₽ x 1 шт

[ОФОРМИТЬ ЗАКАЗ]  → {ссылка}

Сумма: 6 570 ₽
```

Состав строки позиции: `{название} ({вариант / цвет}). {цена} ₽ x {кол-во} шт`.
Данные берутся из `cart->items` (`product`/`productVariant`, `color`, `price`,
`quantity`), сумма — `cart->total`. Шаблон шага 2 (72 ч) — отдельным копирайтом.

---

## Frontend (again_dashboard)

Страница «Брошенные корзины» (раздел Продвижение):
- Фильтр периода (`7 дней` / `30 дней` / диапазон).
- Карточки-метрики, график динамики, круговая «Конверсия в заказ».
- Таблица корзин с колонками со скрина (статус-бейдж, иконка канала, дата
  коммуникации, «По триггеру»/«Вручную», подпись «N версий» под ID),
  поиск/фильтры, drill-down в состав корзины.
- Кнопка ручной отправки напоминания (иконка-самолётик в строке) → `POST
  /carts/{cart}/remind` (шаг F).
- Точки опоры: существующие аналитические страницы (`src/components/analytics/*`),
  паттерн UTM-раздела (`docs/tasks/utm-tracking.md`).

---

## Этапы реализации (черновой план)

1. Миграции: `cart.order_id`/`orders.cart_id`, `cart.recovery_token`,
   `cart.abandoned_at`, таблица `cart_communications` (+ модель).
2. Пометка `ordered` при создании заказа (шаг C) — наполнить конверсию.
3. Команда `cart:process-abandoned` + регистрация в расписании (шаг A).
4. `AbandonedCartService` + шаблоны писем + цепочка (шаг B).
5. Публичное восстановление корзины (шаг D) + интеграция с витриной `nuxt-shop`.
6. Расширить `CartAnalyticsController`/`CartController` под метрики и колонки (шаг E).
7. Фронт `again_dashboard`: страница, фильтры, графики, таблица.
8. Тесты: детектор 24ч, идемпотентность шагов, остановка цепочки при заказе,
   расчёт метрик/конверсии, восстановление по токену.
9. Ручная отправка (шаг F): `POST /carts/{cart}/remind` +
   `AbandonedCartService::sendManual()` (`type='manual'`, троттлинг) + кнопка во
   фронте; тест на ручную отправку и троттлинг.
10. Счётчик версий (шаг G): `versions_count` в `CartController@carts` + подпись
    во фронте; тест на агрегат по идентичности.

---

## Безопасность / граничные случаи

- **Гостевые корзины** (`client_id IS NULL`) — в MVP слать некуда; в аналитику
  входят, в цепочку — нет. **[universal-cart]** После внедрения универсальной
  корзины гостю доступна серверная корзина и рассылка при согласии
  (`marketing_consent`) — см. `universal-cart.md`.
- **Идемпотентность**: один шаг цепочки — максимум одна отправка
  (уникальность по `cart_id + step` в `cart_communications`).
- **Остановка цепочки** при `status='ordered'` или опустошении корзины.
- **Анти-спам**: не слать, если клиент отписан / нет контакта; ограничить
  частоту; уважать рабочие часы (#3).
- **Токен восстановления** — `unique`, непредсказуемый (hex), без PII.
- **Часовой пояс** при расчёте «24 ч» и при `dailyAt`/`hourly`.
- Деление на ноль в конверсии (нет корзин за период).

---

## Риски / замечания по существующему коду

1. **Баг имени таблицы в аналитике.** `CartAnalyticsController` джойнит таблицу
   `carts` (`->join('carts', 'cart_items.cart_id', ...)`), а реальная таблица —
   `cart` (см. `Cart::$table='cart'` и FK `cart_items.cart_id → cart`). Есть две
   разные миграции: `2024_10_03_065433_create_carts_table.php` и
   `2025_06_29_094627_create_cart_table.php`. Перед расширением аналитики
   проверить, какая таблица реально используется, и починить джойн (иначе
   `total_items_qty`/`top_products` считаются не из той таблицы или падают).
2. **`Api\CartController` (не админский)** использует поле `products` (JSON) и
   `client->cart`, которых нет в текущей схеме `cart`/`cart_items` — похоже на
   легаси-телеграм-флоу. Не опираться на него; уточнить, живой ли роут.
3. **`average_order_value`** в текущей аналитике считается по `ordered`, а на
   скрине «Средняя стоимость корзины» — вероятно по всем/брошенным. Согласовать
   множество усреднения (#6).
4. **Конверсия сейчас пустая**, т.к. корзины не помечаются `ordered` (шаг C —
   обязателен, иначе метрики бессмысленны).

---

## Открытые вопросы

1. ✅ **Связь заказ↔корзина — решено:** `orders.cart_id` (FK→`cart`, nullable).
   Обоснование — см. «Модель данных» § 1.
2. ✅ **Цепочка — решено:** 2 касания.
   - Шаг 1 — через **24 ч** (текущий шаблон письма из ТЗ).
   - Шаг 2 — через **72 ч** (повтор + мягкий стимул, текст уточнить копирайтом).
   - **Промокод** на последнем шаге — **в фазе 2** (не в MVP), хотя технически
     возможен через `PromoCodeService`.
3. ✅ **Расписание/окно — решено:** планировщик `hourly`,
   `withoutOverlapping()`. Отправка только в окне **10:00–21:00** по единому TZ
   магазина (МСК); ночные срабатывания откладываются на 10:00. TZ клиента не
   используем (его обычно нет).
4. ✅ **Приоритет каналов — решено:** один приоритетный канал на шаг, порядок
   `telegram → email → whatsapp → vk` (берём первый доступный контакт).
   Реально использованный канал фиксируется в `cart_communications.channel`
   (колонка «Канал» в таблице).
5. ✅ **Гости — решено (MVP):** в MVP шлём только авторизованным клиентам (есть
   `client_id` и контакт). Гостевые корзины входят в аналитику, но не в цепочку.
   **[universal-cart] Пересмотрено:** ограничение снимается задачей
   `universal-cart.md`. Гость получает серверную корзину (HttpOnly-cookie
   `guest_token`), а захваченный на чекауте email/phone + **явный opt-in**
   (`cart.marketing_consent`) делает гостевую корзину eligible для цепочки.
   `markAbandonedCarts()`/`resolveChannel()` обобщаются на гостей; сервис
   уведомлений не различает гостя и клиента.
6. ✅ **«Средняя стоимость корзины» — решено:** среднее по **брошенным**
   (`AVG(total) WHERE status='abandoned'`) за период — согласуется с разделом и
   соседними метриками на скрине. (В текущем коде считается по `ordered` — поправить.)
7. ✅ **Восстановление корзины — решено:** ссылка ведёт на витрину
   `{SHOP_URL}/cart/restore/{token}`. Фронт `nuxt-shop` дёргает
   `GET /api/public/cart/restore/{token}`, подставляет позиции в `localStorage`
   (с пересчётом цен через `CartPriceController`-пайплайн) и ведёт на `/checkout`.
   Требуется доработка витрины.
8. ✅ **Окно жизни брошенной корзины — решено:** цепочка сама себя ограничивает
   (после последнего шага не шлём). Отдельную архивацию в MVP не делаем; период
   аналитики фильтрует старое.


---

## Реализация (backend) — 2026-06-24

Реализованы шаги A–D и сопутствующий багфикс/инфраструктура. Шаг E (аналитика
под скрины) и фронт `again_dashboard` — следующая итерация.

### Изменения схемы (миграции)
- `2026_06_24_000001_add_cart_id_to_orders_table` — `orders.cart_id`
  (FK→`cart`, nullable, `nullOnDelete`). Решение #1.
- `2026_06_24_000002_add_recovery_fields_to_cart_table` — `cart.recovery_token`
  (unique, nullable), `cart.abandoned_at` (timestamp nullable).
- `2026_06_24_000003_create_cart_communications_table` — журнал коммуникаций
  (`cart_id`, `channel`, `step`, `type`, `status`, `sent_at`, UNIQUE[cart_id,step]).

### Модели
- `App\Models\CartCommunication` — новая (casts step:int, sent_at:datetime).
- `Cart` — связи `order()` (hasOne), `communications()` (hasMany); добавлены
  `$casts` (datetime для created_at/updated_at/ordered_at/abandoned_at, decimal
  для total*).
- `Order` — связь `cart()` (belongsTo), `cart_id` в `$fillable`.

### Конфиг
- `config/abandoned_cart.php` — `enabled`, `abandon_after_hours` (24), `steps`
  (шаг1=0ч, шаг2=48ч от `abandoned_at`), `channel_priority`
  (`telegram→email→whatsapp→vk`), `send_window` (10–21), `recovery_url`
  (env `CART_RECOVERY_URL`, по умолчанию `FRONTEND_URL` + `/cart/restore`).

### Сервис и команда
- `App\Services\Cart\AbandonedCartService`:
  - `markAbandonedCarts()` — активные корзины с клиентом и непустым составом,
    у которых `COALESCE(updated_at, created_at) <= now()-24h`, → `abandoned` +
    `abandoned_at` + `recovery_token`.
  - `processChain()` — для каждой брошенной корзины и каждого due-шага: проверка
    окна отправки, идемпотентность через `CartCommunication::firstOrCreate`
    (UNIQUE cart_id+step), выбор канала, `SendNotificationJob::dispatch`, лог.
  - `resolveChannel()` — первый доступный контакт по приоритету.
  - `buildMessage()` — plain-текст (рендерится и в email через nl2br, и в
    мессенджерах): интро + ссылка восстановления + состав + сумма.
- `App\Console\Commands\ProcessAbandonedCartsCommand` (`cart:process-abandoned`).
- Расписание в `routes/console.php`: `hourly()->withoutOverlapping()->runInBackground()`.

### Пометка `ordered` (шаг C)
- `OrderCreationService::createOrder` → `linkCartToOrder($order, $clientId)`:
  находит активную (приоритет) или брошенную корзину клиента, ставит
  `ordered` + `ordered_at` + `orders.cart_id`. Покрывает оба контроллера
  оформления автоматически. Гостям (нет client_id) — пропуск.

### Восстановление корзины (шаг D)
- `App\Http\Controllers\Api\Public\Cart\CartRestoreController@show`.
- Роут `GET /api/public/cart/restore/{token}` (public, `throttle:30,1`).
  Возвращает доступные позиции с актуальными ценами (`applyDiscountToProduct`).

### Багфикс / инфраструктура
- `CartAnalyticsController` — джойн `carts` → `cart` (легаси-таблица vs реальная).
- `CartController@sync` и `@remove_single_item_from_cart` — теперь бампят
  `cart.updated_at = now()` при пересчёте итогов (Cart не управляет timestamps
  автоматически), чтобы детект «бездействия» был точным.

### Тесты
- `tests/Feature/Cart/AbandonedCartTest.php` (6 тестов, зелёные): пометка
  брошенных, отправка шага 1 + идемпотентность, приоритет каналов, отказ при
  отсутствии контакта, восстановление по токену (+404). UTM-сьют (9) не сломан.

### Осталось (следующая итерация)
- ✅ Шаг E: метрики/график/конверсия под скрины + колонки списка (канал, дата
  коммуникации, кол-во позиций) — реализованы в `CartAnalyticsController` и
  `CartController@carts`.
- ✅ Фронт `again_dashboard`: страница «Брошенные корзины» — реализована
  (`src/components/analytics/abandoned-carts/*`, маршрут `/analytics/abandoned-carts`).
- Витрина `nuxt-shop`: страница `/cart/restore/{token}` (подстановка позиций).
- ✅ **Шаг 2 (фаза 2):** копирайт письма шага 2 (72 ч) + промокод-стимул.
  `AbandonedCartService::maybeIssuePromo()` выдаёт корзине одноразовый
  `PromoCode` (код в `cart.recovery_promo_code`, идемпотентно) и вставляет его в
  текст; конфиг `abandoned_cart.promo` (`enabled`, `step`, `discount_type`,
  `discount_amount`, `ttl_days`, `code_prefix`), по умолчанию выключен
  (`ABANDONED_CART_PROMO_ENABLED`). Миграция `cart.recovery_promo_code`.
- **Шаг F — Ручная отправка напоминания:** эндпоинт `POST /carts/{cart}/remind`
  + `AbandonedCartService::sendManual()` (`type='manual'`, троттлинг) + кнопка-
  самолётик в строке таблицы `again_dashboard`.
- **Шаг G — Счётчик версий корзины:** агрегат `versions_count` в
  `CartController@carts` (по `client_id`/`guest_token`) + подпись «N версий» под
  ID корзины во фронте.
- **[universal-cart]** Поддержка гостей в цепочке (серверная корзина по
  `guest_token`, согласие `marketing_consent`, обобщение `markAbandonedCarts`/
  `resolveChannel`/`linkCartToOrder`) — отдельная задача `universal-cart.md`.

### Замечания / риски
- **Admin-заказ за клиента** тоже пометит активную корзину этого клиента
  `ordered` (linkCartToOrder в общем сервисе). Для MVP приемлемо; при ревью
  решить, не ограничить ли пометку публичным чекаутом.
- **Реальная отправка письма.** `mail.default=smtp`, host `smtp.mail.ru` —
  не песочница. При тестировании команды на «живых» данных уйдут реальные
  письма. Прогон `cart:process-abandoned` делать осознанно (или
  `ABANDONED_CART_ENABLED=false` / `QUEUE` без воркера).
