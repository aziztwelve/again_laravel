# Задача: Многоуровневые (накопительные) подарки в акциях

**Статус:** Backend реализован (задачи 1–8). Тесты — ЗЕЛЁНЫЕ (6/6). Фронты
(витрина + дашборд) — РЕАЛИЗОВАНЫ. (обновлено 2026-06-26)
**Дата:** 2026-06-24
**Связано с:** [`promotions.md`](./promotions.md), [`PROMOTION_TESTING.md`](./PROMOTION_TESTING.md)

---

## Прогресс реализации (обновлено 2026-06-24)

**Решения по вопросам:** Q1 — флаг `is_stackable`, default `false`; Q2 — подарок
бесплатный, в порог не входит; Q3 — дубли подарков разрешены; Q4 — акция с
исчерпанным `max_uses` выпадает, остальные применяются.

**Сделано (backend `lara_admin`):**
- [x] Миграции: `promotions.is_stackable` (bool, default false, +index);
  `promotion_usages.gift_product_variant_id` (nullable FK, nullOnDelete).
  Применены на БД `testing`.
- [x] `Promotion`: `is_stackable` в fillable/casts + метод `isStackable()`.
- [x] `PromotionUsage`: `gift_product_variant_id` в fillable + связь `giftVariant()`.
- [x] `PromotionService`: `resolveApplicablePromotions()` (есть стекируемые → все
  стекируемые; нет → top-1 по priority), `findResolvedPromotions()`,
  `applyPromotionsToOrder(order, selections[])`, сохранение
  `gift_product_variant_id` в usages, обобщённый `cancelPromotionFromOrder()`
  (откат times_used/usages/подарков по нескольким акциям), `getPromotionStats()`
  переведён на usages (distinct order_id).
- [x] `OrderCreationService::applyPromotionsToOrder()` — массив акций
  (одиночный `applyPromotionToOrder` сохранён для back-compat).
- [x] `CreateOrderRequest`: `normalizePromotions()` сворачивает одиночный
  `promotion_id` в `promotions[]`; правила `promotions.*`;
  `validatePromotions()` — цикл по набору, проверка подарка/варианта/наличия,
  комбинированный вердикт по промокоду, запрет нескольких невзаимных акций.
- [x] `Admin/OrderController` и `Public/PublicCheckoutController` — применяют
  `promotions[]`.
- [x] `PromotionPublicController`: `check-applicable` отдаёт итоговый набор
  (`findResolvedPromotions`) + `is_stackable`; `index` тоже отдаёт `is_stackable`.
- [x] `Admin/PromotionController`: `is_stackable` в store/update.

**Осталось:** — всё реализовано.

**Обновление 2026-06-26:**
- [x] Миграции применены и на БД `laravel` (ранее были только на `testing`).
- [x] Тесты ЗЕЛЁНЫЕ: `PromotionStackingTest` — 6/6
  (`docker exec lara_admin-laravel.test-1 php artisan test --filter=PromotionStackingTest`).
  Причиной «зависания»/падений была **не** бизнес-логика, а устаревший
  `OrderFactory`: ссылка на несуществующую колонку `orders.delivery_date_id`
  + `DeliveryDateFactory(order_id => Order::factory())` давали бесконечную
  взаимную рекурсию фабрик (переполнение стека), плюс stale `DeliveryTargetFactory`
  (колонки `address`/`is_active`) и несоответствие `status`/`payment_status`
  enum-ам `OrderStatus`/`PaymentStatus`. `OrderFactory` починен (минимально
  валидный заказ, enum-значения, без рекурсивных связей). Строка в `vk_settings`
  для phpunit **не нужна** — тот `TypeError` всплывает только при загрузке
  tinker/консольного ядра.
- [x] Фронт `again_front` — реализован (мультиакционная модель `promotions[]`,
  `nuxt build` ОК). См. `again_front/docs/tasks/promotions-multi-tier-gifts.md`.
- [x] Дашборд `again_dashboard` — реализован: `usePromotionForOrder.ts` +
  `OrderPromotionBlock.vue` на `promotions[]`; `is_stackable` в модели/типах,
  переключатель «Суммируется с другими акциями» в форме, колонка «Стекируется»
  в таблице; `vue-cli-service build` ОК.

---

## 1. Проблема

Сейчас механика акций работает по схеме **«один заказ → одна акция → один подарок»**:

1. На фронте (`again_front/stores/promotion.js`) и в дашборде
   (`again_dashboard/.../usePromotionForOrder.ts`) из ответа
   `POST /api/public/promotions/check-applicable` берётся **только первая,
   самая приоритетная акция** (`activePromotion = data[0]`).
2. `gift_products` внутри акции — это **альтернативы на выбор** (клиент берёт
   ОДИН подарок), а не несколько подарков сразу.
3. В заказ передаётся одиночный `gift_product_id`, и
   `PromotionService::applyPromotionToOrder()` добавляет **ровно одну**
   подарочную позицию.
4. `orders.promotion_id` — одиночный FK: на уровне заказа «помнится» только
   одна акция.

### Чего хочет бизнес

Накопительные подарки в зависимости от суммы заказа, настраиваемые **отдельными
акциями**:

| Акция | Условие | Подарок(и) |
|-------|---------|-----------|
| Акция №10 | заказ ≥ 1000 ₽ | Подарок 1 |
| Акция №13 | заказ ≥ 4000 ₽ | Подарок 2 |

- Заказ на **1500 ₽** → срабатывает только Акция №10 → клиент получает **Подарок 1**.
- Заказ на **4500 ₽** → срабатывают **обе** акции → клиент получает
  **Подарок 1 + Подарок 2**.

То есть несколько акций должны **складываться (стекироваться)** на одном заказе,
и каждая даёт свой подарок. Уровни («тиры») получаются естественно за счёт
разных порогов `min_purchase_amount` у разных акций.

---

## 2. Выбор подхода

### Подход A (рекомендуемый): стекирование нескольких акций на один заказ

Каждая акция остаётся самостоятельной (свой порог → свои подарки). Заказу
разрешается применить **все** подходящие акции одновременно. Тиры возникают
естественно: чем выше сумма, тем больше акций проходит по порогу.

**Плюсы:**
- Точно ложится на бизнес-описание («добавил Акцию №13 — и работают обе»).
- Минимально меняет саму сущность `Promotion`.
- `findApplicablePromotions()` уже возвращает **все** подходящие акции —
  меняем только потребителей (брать все, а не `[0]`).
- `order_items.promotion_id` и `promotion_usages` уже устроены «по одной записи
  на акцию» — структура частично готова.

**Минусы:**
- Нужно правило совместимости акций между собой (стекируются / взаимоисключающие).
- Меняется контракт API заказа (массив акций вместо одиночных полей).
- UI на витрине и в дашборде должен показывать список подарков, а не один блок.

### Подход B (альтернатива): тиры внутри одной акции

Одна акция содержит несколько уровней (`promotion_tiers`): порог → набор
подарков, с накопительной или замещающей семантикой.

**Минусы:** усложняет модель (`Promotion` + tiers + подарки на тир),
плохо ложится на формулировку бизнеса (он мыслит отдельными акциями №10 и №13),
больше работы на CRUD/админку.

> **Решение:** реализуем **Подход A**. Подход B зафиксирован как рассмотренная
> альтернатива на случай, если позже понадобятся именно тиры внутри одной акции.

---

## 3. Целевая бизнес-логика (Подход A)

1. На checkout запрашиваем `check-applicable` и получаем **список** подходящих
   акций (как и сейчас, но используем весь список).
2. Акции делятся на **стекируемые** и **взаимоисключающие** (новый флаг
   `is_stackable`):
   - Все стекируемые применимые акции применяются **одновременно**, каждая даёт
     свой подарок (или скидку, если разрешено и клиент так выбрал).
   - Среди **взаимоисключающих** применимых акций применяется **только одна** —
     с наибольшим `priority` (поведение, совместимое с текущим).
3. Для каждой применённой акции клиент по-прежнему выбирает **один** подарок из
   её `gift_products` (альтернативы внутри акции) и при необходимости
   вариант/размер.
4. Совместимость с промокодами: промокод/скидка разрешены, только если **все**
   применённые акции имеют `allow_promo_codes = true`. Если хотя бы одна
   запрещает — промокоды заблокированы.
5. Лимиты (`max_uses` / `times_used`) и статистика считаются **по каждой акции
   отдельно**.

### Решения по открытым вопросам (согласовано с заказчиком 2026-06-24)

- **Q1 — РЕШЕНО.** Вводим флаг `promotions.is_stackable`, `default = false`.
  - `is_stackable = true` — акция складывается со всеми другими стекируемыми
    (режим накопительных подарков, напр. Акции №10 и №13).
  - `is_stackable = false` — взаимоисключающая: если подошло несколько таких,
    применяется одна с наибольшим `priority` (текущее поведение).
  - `default = false` → существующие акции продолжают работать как раньше,
    ничего не ломается; накопительные акции помечаются стекируемыми вручную.
- **Q2 — РЕШЕНО.** Подарок бесплатный и **не** учитывается в сумме для порога
  `min_purchase_amount` ни своей, ни другой акции. Порог считается по сумме
  товаров заказа без подарочных позиций (текущее поведение, не меняем).
- **Q3 — РЕШЕНО.** Дубли **разрешаем**: если один и тот же товар-подарок
  приходит от двух акций — создаём **две** отдельные подарочные позиции
  (каждая со своим `promotion_id`, `is_gift = true`, `price = 0`).
- **Q4 — РЕШЕНО.** Если у акции из набора исчерпан `max_uses` — она **выпадает**
  из набора, остальные применяются (отдельный лимит на каждую акцию).

---

## 4. Изменения в backend (`lara_admin`)

### 4.1. Миграции

| Миграция | Изменение |
|----------|-----------|
| `add_is_stackable_to_promotions_table` | `promotions.is_stackable` — `boolean`, `default false`, индекс. (Q1: `default = false` — существующие акции не меняют поведение.) |
| `add_gift_variant_to_promotion_usages_table` | `promotion_usages.gift_product_variant_id` — `nullable`, FK → `product_variants` (`onDelete set null`). Сейчас выбранный размер подарка **не сохраняется** в историю. |

> `orders.promotion_id` **оставляем** для обратной совместимости (заполняем
> «основной»/первой применённой акцией), но источником правды о применённых
> акциях становится `promotion_usages` (по `order_id`) и
> `order_items` (`is_gift = true`, `promotion_id`). Полный отказ от
> `orders.promotion_id` — отдельной задачей.

### 4.2. Модель `Promotion`

- Добавить `is_stackable` в `$fillable` и `$casts` (`boolean`).

### 4.3. `PromotionService`

- **`findApplicablePromotions()`** — без изменений по сути (уже возвращает все
  применимые, отсортированные по `priority desc`). Добавить хелпер
  **`resolveApplicablePromotions()`**, который из полного списка формирует
  итоговый набор по правилам §3.2: все `is_stackable = true` + максимум одна
  взаимоисключающая (с наибольшим `priority`).
- **`applyPromotionToOrder()`** — оставить как есть (применяет ОДНУ акцию), но
  убедиться, что он корректно вызывается в цикле для нескольких акций в рамках
  одной outer-транзакции. Передавать и сохранять `gift_product_variant_id` в
  `promotion_usages`.
- **Новый `applyPromotionsToOrder(Order $order, array $selections)`** —
  принимает массив выборов `[{ promotion_id, gift_product_id,
  gift_product_variant_id, use_discount_instead }]`, валидирует совместимость
  набора (стекируемость, единый вердикт по промокодам) и в цикле вызывает
  `applyPromotionToOrder()` для каждой акции. Stock каждого подарка проверяется
  и списывается под `lockForUpdate` (как сейчас).
- **`getPromotionStats()`** — перевести подсчёт `total_orders` / `total_revenue`
  с relation `orders()` (через одиночный `orders.promotion_id`) на
  `promotion_usages` (`distinct order_id`), иначе при стекировании статистика
  будет занижена/искажена.
- **`cancelPromotionFromOrder()`** — обобщить на несколько акций: удалять все
  подарочные позиции и все `usages` заказа, корректно уменьшать `times_used`
  каждой затронутой акции.

### 4.4. Контроллеры / API

#### `OrderCreationService::applyPromotionToOrder()`

Расширить: вместо одиночных параметров принимать массив выборов и делегировать в
`PromotionService::applyPromotionsToOrder()`.

#### `CreateOrderRequest`

Новый контракт тела заказа — **массив акций**:

```jsonc
{
  "promotions": [
    {
      "promotion_id": 10,
      "gift_product_id": 55,
      "gift_product_variant_id": 120,   // если у подарка есть размеры
      "use_discount_instead": false
    },
    {
      "promotion_id": 13,
      "gift_product_id": 77,
      "use_discount_instead": false
    }
  ]
}
```

Правила валидации (в `withValidator`, для каждого элемента):
- `promotion_id` — `exists:promotions,id`, акция активна и проходит по условиям.
- Если `use_discount_instead = false` → `gift_product_id` обязателен и должен
  принадлежать `gift_products` этой акции (текущая проверка, но на элемент массива).
- `use_discount_instead = true` запрещён, если у акции `allow_promo_codes = false`
  (текущий BUG-3, переносим на элемент).
- Если в наборе несколько акций — все взаимоисключающие/стекируемые сочетания
  должны быть валидны (нельзя прислать две взаимоисключающие акции).
- Единый вердикт по промокоду: если хоть одна акция в наборе с
  `allow_promo_codes = false`, а в заказе есть `promo_code` — ошибка 422.

> **Обратная совместимость:** принимать и **старый** формат (одиночные
> `promotion_id` / `gift_product_id` / `gift_product_variant_id` /
> `use_discount_instead`) — нормализовать его в массив из одного элемента в
> `prepareForValidation()`. Это позволит выкатывать backend раньше фронтов.

#### `PromotionPublicController`

- `checkApplicable` / `index` — в ответ каждой акции добавить
  `is_stackable` и (для удобства фронта) поле, отражающее, стекируется ли акция.
  Список уже отсортирован по `priority desc`.

#### `Admin/PromotionController`

- `store` / `update` — добавить валидацию и сохранение `is_stackable`.
- `getPromotionStats` — см. 4.3.

### 4.5. Затрагиваемые файлы backend

| Файл | Что делаем |
|------|-----------|
| `database/migrations/*_add_is_stackable_to_promotions_table.php` | новая миграция |
| `database/migrations/*_add_gift_variant_to_promotion_usages_table.php` | новая миграция |
| `app/Models/Promotion.php` | `is_stackable` в fillable/casts |
| `app/Models/PromotionUsage.php` | `gift_product_variant_id` в fillable + связь `giftVariant()` |
| `app/Services/Promotion/PromotionService.php` | `resolveApplicablePromotions()`, `applyPromotionsToOrder()`, фикс stats и cancel |
| `app/Services/Order/OrderCreationService.php` | применение массива акций |
| `app/Http/Requests/Order/CreateOrderRequest.php` | новый контракт `promotions[]` + back-compat |
| `app/Http/Controllers/Api/Admin/OrderController.php` | прокидывание массива акций |
| `app/Http/Controllers/Api/Public/Order/PublicCheckoutController.php` | то же для публичного checkout |
| `app/Http/Controllers/Api/Public/Promotion/PromotionPublicController.php` | `is_stackable` в ответе |
| `app/Http/Controllers/Api/Admin/Promotion/PromotionController.php` | `is_stackable` в CRUD, фикс stats |

---

## 5. Изменения во фронте витрины (`again_front`)

`stores/promotion.js`:
- Заменить одиночный `activePromotion` на работу со **всем списком**
  `applicablePromotions` (с учётом `is_stackable` по правилам §3.2).
- Состояние выбора подарка/варианта/цвета хранить **по каждой акции**
  (`{ [promotionId]: { selectedGiftId, variantByGiftId, colorByGiftId, userChoice } }`).
- `useDiscountInstead` / `allowPromoCodes` пересчитать как агрегат по набору
  применённых акций (промокод разрешён только если разрешают все).
- `getDataForOrder()` — возвращать **массив** `promotions: [...]`.
- `isGiftSelectionComplete` — true, только если по каждой применённой акции
  сделан корректный выбор.

UI checkout: вместо одного блока акции рисуем список блоков «подарок за акцию»,
каждый со своим выбором подарка/размера.

| Файл | Что делаем |
|------|-----------|
| `again_front/stores/promotion.js` | мультиакционная модель состояния |
| `again_front/composables/useCheckoutSubmit.ts` | отправка `promotions[]` |
| компоненты `components/Checkout/*` (блок акции) | список блоков подарков |

---

## 6. Изменения в дашборде (`again_dashboard`)

### Создание заказа оператором
- `composables/orders/usePromotionForOrder.ts` — те же изменения, что и в
  `stores/promotion.js` витрины (мультиакционная модель, `getPayloadFragment`
  возвращает `promotions[]`).
- `components/orders/create/OrderPromotionBlock.vue` — рендер списка акций/подарков.

### CRUD акций
- `models/Promotion.ts` + `types/promotion/promotion.type.ts` — добавить
  `isStackable` / `is_stackable` (`fromJSON` / `toJSON`).
- `components/discount/Promotion/PromotionForm.vue` — переключатель «Суммируется
  с другими акциями».
- `components/discount/Promotion/PromotionListTable.vue` — колонка/бейдж
  «Стекируется».

---

## 7. Обратная совместимость и порядок выката

1. **Миграции** (`is_stackable`, `gift_product_variant_id`) — безопасны.
   `is_stackable` создаётся с `default = false` (Q1) — поведение существующих
   акций не меняется; накопительные акции помечаются стекируемыми вручную.
2. **Backend** принимает и старый (одиночные поля), и новый (`promotions[]`)
   контракт — выкатывается **первым**, ничего не ломая.
3. **Фронты** (витрина, затем дашборд) переключаются на `promotions[]`.
4. Старые поля `gift_product_id` и т.п. удаляем **после** перевода обоих фронтов.

---

## 8. Краевые случаи

- Лимит `max_uses` исчерпан у одной из акций набора → она выпадает, остальные
  применяются.
- Подарок (или его вариант) закончился на складе при оформлении → 422 по
  конкретной акции/подарку, весь заказ откатывается (outer-транзакция).
- Один товар-подарок от двух акций → дубли разрешены (Q3): создаём две
  отдельные `is_gift`-позиции, каждая со своим `promotion_id`.
- Промокод + стек акций → разрешён только если **все** акции его допускают.
- Гонки на остатках подарков → сохраняем `lockForUpdate` на каждый подарок
  (как сейчас).
- Отмена заказа → возврат остатков по всем подарочным позициям (уже делается в
  `OrderCreationService::cancelOrder()`), удаление всех `usages`, декремент
  `times_used` по каждой акции.

---

## 9. Чек-лист проверки

- [ ] Заказ ≥ порога Акции №10, но < порога Акции №13 → только Подарок 1.
- [ ] Заказ ≥ порога обеих акций → Подарок 1 + Подарок 2 (две `is_gift`-позиции).
- [ ] У каждой применённой акции — отдельная запись в `promotion_usages`,
      `times_used` инкрементится у каждой.
- [ ] Выбранный размер подарка сохраняется в `promotion_usages.gift_product_variant_id`.
- [ ] Взаимоисключающие акции (`is_stackable = false`) не складываются — берётся
      одна по `priority`.
- [ ] Промокод заблокирован, если хотя бы одна применённая акция запрещает.
- [ ] Старый формат заказа (одиночный `promotion_id`) всё ещё принимается
      (back-compat).
- [ ] Остаток каждого подарка списывается атомарно; нехватка → 422 + откат.
- [ ] Отмена заказа корректно откатывает все подарки/usages/`times_used`.
- [ ] Статистика акции (`getPromotionStats`) корректна при стекировании.
- [ ] Витрина и дашборд показывают несколько подарков и отправляют `promotions[]`.

---

## 10. Резюме изменений модели данных

```
promotions
  + is_stackable        boolean default false  // акция складывается с другими (Q1)

promotion_usages
  + gift_product_variant_id  FK nullable -> product_variants

orders.promotion_id     // остаётся для back-compat (основная акция)
                        // источник правды о применённых акциях:
                        //   promotion_usages.order_id + order_items(is_gift, promotion_id)
```
