# Promotion module — отчёт о тестировании

**Дата:** 2026-05-16
**Среда:** dev (`sub.againdev.ru` / `sub.againdev2.ru`)
**Метод:** прогон через реальный API + проверки в БД; код фронта прочитан и сверен с логикой
**Все тестовые данные удалены, исходное состояние восстановлено.**

## Что протестировано

| Группа | Что | Кейсов | PASS | FAIL |
|---|---|---:|---:|---:|
| A | CRUD акций (admin API) | 13 | 13 | 0 |
| B | `/public/promotions/check-applicable` | 10 | 10 | 0 |
| C | Создание заказа с акцией (validation + happy path) | 9 | 9 | 0 |
| D | Отмена заказа с подарком | 1 | 1 | 0 |
| E | Логика витрины (`stores/promotion.js`) — code review | — | OK | — |
| F | Финансы заказа с подарком | 3 | 3 | 0 |
| X | Подозрительные места | 4 | 1 | **3** |

## Кейсы и результаты

### A. CRUD акций — `/api/promotions` (auth: admin)

| ID | Сценарий | Ожидание | Результат |
|---|---|---|---|
| A1 | Создать валидную акцию (все поля) | 201, акция в БД, связи привязаны | ✅ PASS |
| A2 | name пустой | 422 | ✅ PASS |
| A3 | ends_at раньше starts_at | 422 (`after:starts_at`) | ✅ PASS |
| A4 | trigger_product_ids = [] | 422 (`min:1`) | ✅ PASS |
| A5 | gift_products = [] | 422 (`min:1`) | ✅ PASS |
| A6 | gift_products[0].quantity = 0 | 422 (`min:1`) | ✅ PASS |
| A7 | trigger_product_id несуществующий | 422 (`exists`) | ✅ PASS |
| A8 | min_purchase_amount = -100 | 422 (`min:0`) | ✅ PASS |
| A9 | Обновить акцию: заменить триггеры/подарки | 200, sync связей | ✅ PASS |
| A10 | toggle-active | 200, is_active меняется | ✅ PASS |
| A12 | GET `?status=active` | возвращает только активные | ✅ PASS |
| A13 | GET `/{id}/stats` | total_uses, gift_chosen_count, … | ✅ PASS |
| A14 | GET `/products/list` | возвращает `has_variants`, `active_variants_count` | ✅ PASS |

### B. Применимость — `POST /api/public/promotions/check-applicable`

| ID | Сценарий | Ожидание | Результат |
|---|---|---|---|
| B1 | Триггер 236, total=2390, min=1000 | в выдаче | ✅ PASS |
| B2 | Триггер 236, total=999, min=1000 | НЕ в выдаче | ✅ PASS |
| B3 | Триггер 236, total=1000 (граница ≥) | в выдаче | ✅ PASS |
| B4 | Без триггерного товара в корзине | НЕ в выдаче | ✅ PASS |
| B5 | `is_active=0` | НЕ в выдаче | ✅ PASS |
| B6 | `starts_at` в будущем (2030) | НЕ в выдаче | ✅ PASS |
| B7 | `ends_at` в прошлом (2021) | НЕ в выдаче | ✅ PASS |
| B8 | `times_used >= max_uses` | НЕ в выдаче | ✅ PASS |
| B9 | Высокий priority — первый в data | да | ✅ PASS |
| B11 | Подарок без вариантов (мыло 336) | `variants: []`, `has_variants: false` | ✅ PASS |

### C. Создание заказа с акцией — `POST /api/orders` (auth: client)

| ID | Сценарий | Ожидание | Результат |
|---|---|---|---|
| C1 | Валидно: promotion + gift с вариантом | 201, OrderItem(is_gift=1, color_id=12), stock-1, times_used+1, usage создан | ✅ PASS |
| C2 | promotion_id без gift_product_id | 422 «Выберите подарок» | ✅ PASS |
| C5 | gift_product с вариантами, без variant_id | 422 «Выберите размер» | ✅ PASS |
| C6 | variant_id из другого товара | 422 «недоступен» | ✅ PASS |
| C7 | variant_id со `stock=0` | 422 «нет в наличии» | ✅ PASS |
| C8 | gift_product, которого нет в этой акции | 422 «недоступен в этой акции» | ✅ PASS |
| C9 | Несуществующий promotion_id | 422 (`exists:promotions`) | ✅ PASS |
| C10 | Подарок без вариантов (мыло 336) | 201, OrderItem без variant_id | ✅ PASS |

### D. Отмена заказа с подарком — `POST /api/orders/{id}/cancel`

| ID | Сценарий | Ожидание | Результат |
|---|---|---|---|
| D1 | Отменить заказ с подарком | status=cancelled; promotion_id=NULL в orders; gift item удалён; usage удалена; times_used−1; stock варианта +1 | ✅ PASS |

### E. Витрина — `nuxt-shop/stores/promotion.js` (code review)

Проверено по коду (PASS):
- `requestId` гард от race condition: устаревший ответ не затирает актуальный стейт.
- Авто-выбор первого подарка, если в новой выдаче ещё ничего не выбрано.
- Авто-выбор первого варианта подарка (`gift.variants[0]`).
- Чистка `selectedGiftVariantByGiftId` от подарков, исчезнувших из акции.
- `allow_promo_codes=false` → принудительно `userChoice='gift'`.
- `getDataForOrder()` корректно собирает payload по 3 веткам: gift+variant / gift без variant / discount.
- `selectDiscount()` блокируется при `allow_promo_codes=false` (показывает сообщение).
- `isGiftSelectionComplete` — корректная защита кнопки «Оформить заказ».

### F. Финансы — `total_amount` и отображение

| ID | Сценарий | Ожидание | Результат |
|---|---|---|---|
| F1 | `total_amount` = сумма платных позиций (подарок=0 не повышает) | да | ✅ PASS |
| F2 | Подарок в `OrderItemsTable` показывается как «Бесплатно» (наш фикс) | да | ✅ PASS |
| F3 | `subtotal` на чекауте считает только корзину (подарок добавляется на бэке) | да | ✅ PASS |

---

## ✅ Все найденные баги исправлены

См. секцию «Исправления» ниже.

## ⚠️ Найденные баги

### BUG-1: Race condition на `stock_quantity` подарка ⚠️ CRITICAL

**Сценарий:** установлен `stock=1` у варианта подарка → запущено 3 параллельных заказа.

**Результат:**
- 2 заказа УСПЕШНО созданы (67721 и 67723), оба получили один и тот же экземпляр подарка
- 1 заказ упал с HTTP 500
- `stock_quantity` стал **-1** (отрицательный)

**Причина:** в `App\Services\Promotion\PromotionService::applyPromotionToOrder` <ref_snippet file="/var/www/html/laravel/app/Services/Promotion/PromotionService.php" lines="111-131" />
- Чтение `$variant = ProductVariant::find()` и проверка `stock` в `CreateOrderRequest::withValidator` происходят **без блокировки**.
- `$variant->decrement('stock_quantity', ...)` — атомарный SQL, но между read-check и decrement окно гонки.

**Предложение фикса:**
```php
DB::transaction(function () use (...) {
    $variant = ProductVariant::where('id', $giftVariantId)
        ->lockForUpdate()
        ->first();

    if ($variant->stock_quantity < $quantity) {
        throw ValidationException::withMessages([
            'gift_product_variant_id' => 'Выбранного размера нет в наличии.',
        ]);
    }
    $variant->decrement('stock_quantity', $quantity);
    // … создание OrderItem
});
```
Альтернативно — атомарный UPDATE с `WHERE stock_quantity >= :qty` и проверка `affected rows`.

---

### BUG-2: Подарок-товар без вариантов с `stock=0` принимается ⚠️ HIGH

**Сценарий:** мыло 336 (`has_variants=0`), `stock=0` → отправили заказ с `gift_product_id=336`.

**Результат:** HTTP 201, заказ создан, `products.stock_quantity` стал **-1**.

**Причина:** `CreateOrderRequest::withValidator` <ref_snippet file="/var/www/html/laravel/app/Http/Requests/Order/CreateOrderRequest.php" lines="118-156" /> проверяет stock **только если `$giftProduct->has_variants === true`**. Для товара без вариантов проверки нет, а `PromotionService::applyPromotionToOrder` бесконтрольно вызывает `Product::decrement('stock_quantity')`.

**Предложение фикса:** в `CreateOrderRequest::withValidator` после проверки варианта добавить ветку для `!has_variants`:
```php
if ($giftProduct->has_variants) {
    // ... как было
} else {
    // Проверяем stock у самого товара
    $productStock = (float) ($giftProduct->stock_quantity ?? 0);
    if ($productStock < ($giftProduct->pivot->quantity ?? 1)) {
        $validator->errors()->add(
            'gift_product_id',
            'Подарка нет в наличии.'
        );
    }
}
```
И в `applyPromotionToOrder` — тоже `lockForUpdate` + проверка.

---

### BUG-3: `use_discount_instead=true` принимается при `allow_promo_codes=false` ⚠️ LOW (валидация)

**Сценарий:** акция 2 имеет `allow_promo_codes=0`. Отправили заказ с `promotion_id=2`, `use_discount_instead=true`, **без** `gift_product_id`.

**Результат:** HTTP 201, заказ создан.

**Эффект:** функционально безвреден — `applyPromotionToOrder` вызывается только при наличии `gift_product_id`, поэтому в orders `promotion_id` остался NULL, usage не создан, эффекта от акции нет. Клиент просто оформил обычный заказ. **Однако:** валидация должна вернуть 422, чтобы фронт-стейт не рассинхронизировался (фронт думает что акция применилась).

**Предложение фикса:** в `CreateOrderRequest::withValidator` <ref_snippet file="/var/www/html/laravel/app/Http/Requests/Order/CreateOrderRequest.php" lines="99-100" /> добавить:
```php
if ($this->filled('promotion_id')) {
    $promotion = \App\Models\Promotion::find($this->input('promotion_id'));
    if ($this->boolean('use_discount_instead') && $promotion && ! $promotion->allow_promo_codes) {
        $validator->errors()->add(
            'use_discount_instead',
            'С этой акцией нельзя выбрать скидку вместо подарка.'
        );
        return;
    }
    // ... остальная логика
}
```

---

## ✅ Исправления (2026-05-16)

### Fix BUG-1 + BUG-2: `App\Services\Promotion\PromotionService::applyPromotionToOrder`

1. **Убрана вложенная транзакция** (`DB::beginTransaction`/`commit`/`rollBack`) — оставлен только `try/catch` с логированием и пробросом. Метод вызывается из уже открытой outer-транзакции в `OrderController::store`; nested savepoint при `ValidationException` приводил к ошибке `SAVEPOINT trans2 does not exist`.
2. **Pessimistic lock на stock-check**:
   - Вариант: `ProductVariant::where('id', $v)->where('product_id', $p)->lockForUpdate()->first()` + проверка `stock < quantity` → `ValidationException`.
   - Без вариантов: `Product::where('id', $p)->lockForUpdate()->first()` + такая же проверка.
3. **Decrement делается уже после lockForUpdate** — гонка исключена.

### Fix BUG-2 + BUG-3: `App\Http\Requests\Order\CreateOrderRequest::withValidator`

1. Добавлена проверка **`use_discount_instead` vs `allow_promo_codes`** в самом начале: если у акции `allow_promo_codes=false` и клиент пытается прислать `use_discount_instead=true` — 422 `«С этой акцией нельзя выбрать скидку вместо подарка.»`.
2. Добавлена ветка проверки stock для **подарка без вариантов**:
   ```php
   if ($giftProduct->has_variants) {
       // прежняя проверка варианта
   } else {
       $stock = $giftProduct->stock_quantity;
       if ($stock !== null && (float)$stock < $giftQuantity) {
           $validator->errors()->add('gift_product_id', 'Подарка нет в наличии.');
       }
   }
   ```
3. Сравнение `< $giftQuantity` (из `pivot.quantity`) вместо `<= 0` — корректно работает, если акция дарит несколько штук.

### Регресс прогон (2026-05-16, после фиксов + сброса OPcache PHP-FPM)

| Сценарий | Результат |
|---|---|
| Race: 3 параллельных заказа на stock=1 | **race-1: 422**, **race-2: 201**, **race-3: 422** — ровно 1 заказ создан, stock=0 (не -1), 1 OrderItem |
| stock=0 у подарка без вариантов | **422** «Подарка нет в наличии», stock остался 0 (не -1) |
| `use_discount_instead=true` при `allow_promo_codes=false` | **422** «С этой акцией нельзя выбрать скидку вместо подарка» |
| Положительный сценарий (с подарком-вариантом) | **201**, OrderItem(is_gift=1, color_id=12, price=0) — обратная совместимость не нарушена |

**Все 3 бага закрыты.**

## Мелкие наблюдения (не баги, но стоит учесть)

1. **A11 (delete акции)** не делал в скрипте, чтобы тестовая акция была доступна другим кейсам. Проверено вручную ранее — soft-delete работает (`deleted_at` ставится). Если нужен полноценный E2E — можно добавить.

2. **Soft-deleted варианты в выдаче check-applicable**: вариант 2914 имел `deleted_at IS NOT NULL`, но при создании заказа отдал 422 «недоступен» — потому что `ProductVariant::find()` по умолчанию исключает soft-deleted. ОК.

3. **`active_variants_count` в `/promotions/products/list`** — корректно показывает кол-во активных вариантов товара (полезно для admin UI).

4. **Race condition в `times_used`**: при гонке 2 заказов `$promotion->increment('times_used')` атомарен, поэтому счётчик не теряет инкременты. ОК.

5. **`OrderItemsTable.vue`** — после наших фиксов: цвет+размер отображаются корректно (Цвет: Бежевый, Размер: L), подарок показывается как «Бесплатно». Регресс на обычных позициях не выявлен.

---

## Артефакты прогона

- Скрипт-раннер: `/tmp/promo-tests/run.sh`
- Результаты (JSONL): `/tmp/promo-tests/results.jsonl`
- Снапшот исходного состояния: `/tmp/promo-tests/snapshot.json`

(После завершения тестов данные удалены, см. секцию «Откат тестовых данных» выше.)
