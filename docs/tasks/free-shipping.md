# Задача: Функционал «Бесплатная доставка» (гибкие правила)

**Статус:** Реализовано (backend + админка + витрина), выкачено и проверено на
dev `againdev3.ru` 2026-08-18. Осталось: прокликать сценарий в браузере
(создание правила в админке → «Бесплатно» в чекауте) и подтвердить семантику
товарного условия (см. §12.1).
**Дата:** 2026-08-18
**Раздел админки:** Настройки → Бесплатная доставка (`again_dashboard`)
**Раздел сайта:** Чекаут → «Способ доставки», корзина → прогресс-бар (`again_front`)
**Backend:** `lara_admin`
**Связанные документы:**
[`free-shipping-architecture.md`](./free-shipping-architecture.md) — диаграммы,
[`promotions.md`](./promotions.md),
[`promotions-multi-tier-gifts.md`](./promotions-multi-tier-gifts.md),
[`guest-checkout.md`](./guest-checkout.md),
[`../deploy-runbook.md`](../deploy-runbook.md)

---

## 1. Описание

Нужна возможность гибко настраивать условия, при которых способ доставки
становится бесплатным. Условия задаются в админке отдельным правилом:

- название (например, `Доставка_СДЭК_ПВЗ`);
- служба доставки (Яндекс.Доставка, СДЭК) — мультивыбор;
- вид доставки (ПВЗ / курьер) — мультивыбор;
- товары из каталога онлайн — мультивыбор;
- сумма бесплатной доставки (например, от 5000 ₽);
- страны — мультивыбор;
- регионы — мультивыбор;
- способы оплаты (карта, СБП, T-Pay и т.д.) — мультивыбор.

Правила обязаны учитывать существующие скидки, промокоды и акции: если после
скидок сумма выкупа меньше порога — доставка платная.

## 2. Что было до фичи (исходная точка)

- Порог бесплатной доставки **захардкожен** в
  `app/Services/Order/OrderCreationService::resolveDeliveryCost()`:
  Яндекс курьер — от `7900`, Яндекс ПВЗ — от `4500`, СДЭК — всегда платно.
  Сравнение идёт с `itemsTotal` **до** применения промокода и акций
  (они применяются позже, шаги 6–7 чекаута), т.е. текущая логика противоречит
  требованию «учитывать акции».
- В админке пункта «Бесплатная доставка» нет.
  `src/components/settings/delivery_method/index.vue` — заглушка.
- На витрине бесплатность нигде не отображается: в `Checkout/Delivery.vue`
  тарифы Яндекса/СДЭК рендерятся как «цена ₽», в итогах чекаута
  (`Cart/Total.vue`) строки «Доставка» вообще нет, а `Cart/Progress.vue`
  («До бесплатной доставки осталось») — статичный мок с `1.212 ₽` и `70%`.

### Что важно знать из текущей архитектуры

| Факт | Где | Значение для фичи |
|---|---|---|
| Способы доставки: `cdek_pickup`, `cdek_courier`, `yandex_pickup`, `yandex_courier` (+ legacy boxberry/почта, `courier`, `email`, `none`) | таблица `delivery_methods` | из кода метода выводим службу и вид |
| Провайдер и вид доставки заказа лежат в `orders.delivery_data` (`provider` = `cdek\|yandex`, `delivery_type` = `pickup\|courier`, `price`) | `OrderCreationService::buildDeliveryData()` | источник правды для матчинга |
| `item.price` в `order_items` — финальная цена после товарных скидок, промокода и «скидки вместо подарка» | `OrderValidationService`, `Order::updateTotalAmount()` | сумма выкупа = `sum(qty * price)` по не-подарочным позициям |
| `Order::updateTotalAmount()` = `items + delivery_cost − gift_card_amount` | `app/Models/Order.php` | после обнуления доставки достаточно вызвать пересчёт |
| География — таблицы `country` (217), `region` (1610, есть `country_id`), `city` (17286, есть `region_id`) | `App\Models\Country/Region/City` | регион однозначно выводится из города |
| В чекауте страна и город — селекты с `id`, регион — свободный текст | `Checkout/Delivery.vue` | витрина должна присылать `country_id`/`city_id`; фолбэк — матч по названиям |
| Способы оплаты — свободная строка `orders.payment_method` с каноническим набором кодов | `constants/payment.ts`, миграция `2026_05_14_120000_normalize_orders_payment_method` | канон выносим в `config/free_shipping.php` |

## 3. Решения

**Решение 1. Правило = набор условий, пустое условие = «любое».**
Правило срабатывает, если совпали **все** заполненные группы условий. Пустая
группа (не выбрано ничего) ограничения не накладывает. Так одно правило
«от 5000 ₽» без гео/оплат работает как глобальный порог.

**Решение 2. Список товаров задаёт базу для порога.**
Если в правиле выбраны товары — к порогу суммируются **только эти товары**
корзины (и требуется наличие хотя бы одного из них). Если список пуст — порог
считается по всей корзине. Это позволяет делать правила вида «бесплатная
доставка при заказе этой коллекции от 5000 ₽».
*Альтернатива (не выбрана): товары как простой фильтр «в корзине есть хотя бы
один из них», а порог всегда по всей корзине. Если бизнесу нужна именно она —
меняется один метод сервиса.*

**Решение 3. Сумма выкупа — после всех скидок, до оплаты.**
`qualifying_amount` = сумма `qty × финальная цена` по не-подарочным позициям,
т.е. **после** товарных скидок и промокода. Подарки акций
(`order_items.is_gift = true`, цена 0) не считаются. **Не** уменьшают сумму
выкупа: стоимость доставки и оплата подарочной картой (карта — способ оплаты,
а не скидка).

> Уточнение по акциям: в этом проекте акция даёт **подарок**, а не денежную
> скидку (`promotions` не содержит поля скидки; «скидка вместо подарка» =
> отказ от подарка в пользу промокода/обычных скидок,
> `promotion_usages.used_discount_instead`). Поэтому требование «с акцией сумма
> выкупа ниже 5000 → доставка платная» на практике выполняется так: подарок в
> сумму не идёт, а промокод/скидки уменьшают её — и правило перестаёт
> срабатывать.

**Решение 4. Сервер — источник правды.**
Витрина показывает «Бесплатно» по ответу публичного endpoint'а, но итоговая
`orders.delivery_cost` считается на бэкенде при создании заказа. Подменить
цену доставки из фронта нельзя.

**Решение 5. Пересчёт после применения промокода и акций.**
В чекауте доставка сначала пишется по тарифу, а после шагов «промокод» и
«акции» пересчитывается сервисом и заказ обновляется (`updateTotalAmount()`).
Иначе требование «с акцией сумма ниже порога → доставка платная» не выполнить,
т.к. скидки применяются после создания заказа.

**Решение 6. Несколько подходящих правил → выигрывает покупатель.**
Если под контекст подходит несколько правил, доставка бесплатна; в заказ
пишется правило с наименьшим порогом среди сработавших (`priority` — только
для сортировки в админке и детерминизма при равных порогах).

**Решение 7. Обратная совместимость через сидер.**
Хардкод `7900/4500` удаляется, вместо него идемпотентный
`FreeShippingRuleSeeder` создаёт два правила, повторяющие текущее поведение
(Яндекс курьер от 7900, Яндекс ПВЗ от 4500). Без сидера после деплоя
бесплатной доставки не будет ни у кого — это регресс.

**Решение 8. Служба/вид доставки выводятся из данных заказа, а не из имени метода.**
`service` = `delivery_data.provider`, при отсутствии — префикс `code` способа
доставки (`cdek_*` → `cdek`, `yandex_*` → `yandex`).
`delivery_type` = `delivery_data.delivery_type`, при отсутствии — суффикс кода
(`*_pickup`/`*_postamat` → `pickup`, `*_courier` → `courier`). Постамат
считается ПВЗ.

**Решение 9. Регион определяется по городу.**
Приоритет: `city_id → city.region_id → region.country_id`. Если `city_id` нет —
матч по названиям (`city.name`, `region.name`, `country.name`/`code`,
регистронезависимо). Свободный текст поля «Регион» на витрине используется
только как фолбэк.

**Решение 10. Место в админке.**
Новый пункт в «Настройки» → «Бесплатная доставка» (`/settings/free-shipping`),
плюс ссылка из раздела способов доставки. Отдельная сущность, а не поля внутри
`delivery_methods`: одно правило распространяется на несколько служб и видов.

## 4. Модель данных

### `free_shipping_rules`

| Поле | Тип | Описание |
|---|---|---|
| `id` | bigint pk | |
| `name` | string | название правила (`Доставка_СДЭК_ПВЗ`) |
| `is_active` | bool, default true | |
| `priority` | int, default 0 | порядок сортировки/детерминизм |
| `min_order_amount` | decimal(12,2) | «сумма бесплатной доставки», порог `>=` |
| `services` | json nullable | `["cdek","yandex"]`, пусто = любая |
| `delivery_types` | json nullable | `["pickup","courier"]`, пусто = любой |
| `payment_methods` | json nullable | коды оплат, пусто = любая |
| `starts_at` / `ends_at` | datetime nullable | необязательное окно действия |
| `timestamps`, `softDeletes` | | |

### Пивоты (мультивыбор с FK)

- `free_shipping_rule_products` — `rule_id`, `product_id` (unique пара)
- `free_shipping_rule_countries` — `rule_id`, `country_id`
- `free_shipping_rule_regions` — `rule_id`, `region_id`

`country`/`region` — legacy-таблицы без `timestamps`; FK ставим с
`cascadeOnDelete` для `rule_id` и без FK на гео-таблицы, если их движок/типы
не позволяют (проверяется при миграции; тогда — обычный индексированный
`unsignedBigInteger`).

### `orders` (дополнение)

- `free_shipping_rule_id` — nullable FK → `free_shipping_rules.id`
  (`nullOnDelete`): какое правило дало бесплатную доставку.
- `delivery_cost_original` — decimal(12,2) nullable: цена тарифа до обнуления
  (аналитика «сколько подарили на доставке»).

### Модель `FreeShippingRule`

Касты: `services/delivery_types/payment_methods` → `array`,
`min_order_amount` → `decimal:2`, даты → `datetime`, `is_active` → `bool`.
Связи: `products()`, `countries()`, `regions()` (belongsToMany), `orders()`.
Скоуп `active()` — `is_active` + окно дат.

## 5. Сервис `App\Services\Delivery\FreeShippingService`

```php
// Контекст оценки: собирается и из корзины (витрина), и из заказа (чекаут).
final class FreeShippingContext {
    public array  $items;            // [['product_id'=>int,'quantity'=>int,'price'=>float,'is_gift'=>bool], ...]
    public ?string $service;         // 'cdek' | 'yandex' | null
    public ?string $deliveryType;    // 'pickup' | 'courier' | null
    public ?string $paymentMethod;   // код оплаты
    public ?int   $countryId; public ?int $regionId; public ?int $cityId;
    public ?string $countryName; public ?string $regionName; public ?string $cityName;
}
```

Публичные методы:

- `evaluate(FreeShippingContext $ctx): ?FreeShippingMatch` — лучшее правило или
  `null`. `FreeShippingMatch`: `rule_id`, `rule_name`, `min_order_amount`,
  `qualifying_amount`.
- `progress(FreeShippingContext $ctx): ?array` — ближайшее правило, до которого
  не хватает суммы: `['rule_name','min_order_amount','qualifying_amount','remaining']`.
  Нужно для подсказки «до бесплатной доставки осталось N ₽».
- `applyToOrder(Order $order): void` — собирает контекст из заказа, при матче
  пишет `delivery_cost_original`, `delivery_cost = 0`,
  `free_shipping_rule_id`, вызывает `Order::updateTotalAmount()`. При отсутствии
  матча возвращает тариф из `delivery_data.price` (идемпотентно — повторный
  вызов не «схлопывает» цену).

Порядок матчинга правила: активность и окно дат → служба → вид доставки →
страна → регион → способ оплаты → товары (наличие) → порог по
`qualifying_amount`.

## 6. API

### Админка (auth: sanctum + админ-мидлвары, как у прочих `/api/...` админ-роутов)

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/free-shipping-rules` | список (поиск по названию, фильтр активности, пагинация) |
| GET | `/api/free-shipping-rules/options` | справочники для селектов: службы, виды доставки, способы оплаты, страны, регионы |
| GET | `/api/free-shipping-rules/products` | лёгкий поиск товаров для мультивыбора (`search`, `ids[]`) |
| POST | `/api/free-shipping-rules` | создать |
| GET | `/api/free-shipping-rules/{rule}` | получить с составом мультивыборов |
| PUT | `/api/free-shipping-rules/{rule}` | обновить (пивоты через `sync`) |
| POST | `/api/free-shipping-rules/{rule}/toggle` | быстрое вкл/выкл из списка |
| DELETE | `/api/free-shipping-rules/{rule}` | soft delete |

Валидация (`FreeShippingRuleRequest`): `name` обязателен;
`min_order_amount` — `numeric|min:0`; `services.*` ∈ `cdek,yandex`;
`delivery_types.*` ∈ `pickup,courier`; `payment_methods.*` — из
`config('free_shipping.payment_methods')`; `product_ids.*`, `country_ids.*`,
`region_ids.*` — `exists`.

Товары для мультивыбора берём существующим поиском товаров админки
(тот же, что используют триггерные товары акций) — отдельного endpoint'а не
добавляем.

### Витрина (публичный)

`POST /api/public/delivery/free-shipping/evaluate` (throttle 60/мин)

```jsonc
// запрос
{
  "items": [{"product_id": 12, "quantity": 2, "price": 2600}],
  "payment_method": "cloudpayments_sbp",
  "country_id": 0, "city_id": 1, "region": "Москва и Московская обл.",
  "candidates": [                        // варианты доставки, показанные покупателю
    {"key": "cdek:136", "service": "cdek", "delivery_type": "pickup", "price": 390},
    {"key": "yandex:time_interval", "service": "yandex", "delivery_type": "courier", "price": 890}
  ]
}
// ответ
{
  "success": true,
  "qualifying_amount": 5200,
  "candidates": [
    {"key": "cdek:136", "is_free": true, "price": 0, "rule": {"id": 3, "name": "Доставка_СДЭК_ПВЗ", "min_order_amount": 5000}},
    {"key": "yandex:time_interval", "is_free": false, "price": 890, "rule": null}
  ],
  "progress": {"rule_name": "Курьер от 7900", "min_order_amount": 7900, "remaining": 2700}
}
```

Цены товаров из запроса **не** принимаются на веру: как и в акциях, суммы
пересчитываются по актуальным ценам/скидкам на бэкенде (reuse валидации
позиций). Endpoint публичный (гость + клиент), только чтение, без побочных
эффектов.

## 7. Применение в чекауте

`OrderCreationService::resolveDeliveryCost()` — убрать хардкод порогов: цена
доставки = тариф (`delivery_data.price`), для отсутствующей доставки — 0.

Публичный чекаут `PublicCheckoutController::store()` — новый шаг **7.5**
(после промокода и акций, до подарочной карты):

```php
$this->freeShippingService->applyToOrder($order->fresh(['items']));
```

То же — в админском `Api\Admin\OrderController::store()` (он использует тот же
`OrderCreationService`) и в `OrderUpdateService`, если админ меняет состав/
доставку заказа: пересчёт вызывается после пересчёта позиций.

Ручное поле «Стоимость доставки» в админке остаётся приоритетнее автоматики:
если админ явно задал `delivery_cost`, пересчёт его не перетирает (флаг
`delivery_cost_manual`, если понадобится — уточняется на этапе реализации).

## 8. Админка (`again_dashboard`)

- Меню «Настройки» (`components/settings/index.vue`) → пункт
  «Бесплатная доставка», роут `/settings/free-shipping`.
- Список правил: название, службы, виды, порог, гео (кратко), способы оплаты,
  статус, кнопки «Изменить»/«Удалить», переключатель активности.
- Форма (создание/редактирование): название, порог, мультиселекты служб, видов,
  способов оплаты, стран, регионов (регионы фильтруются выбранными странами),
  выбор товаров через модалку поиска товаров (паттерн
  `discount/Promotion/trigger_product/*`), опциональные даты, активность.
- Подсказки в UI: «Пустой список = условие не ограничивает»,
  «Порог считается по сумме выкупа после скидок, промокода и акций».

## 9. Витрина (`again_front`)

- `Checkout/Delivery.vue`: после расчёта тарифов Яндекса/СДЭК вызываем
  `evaluate` и для бесплатных вариантов показываем «Бесплатно» (зачёркнутая
  цена), плюс строку «Бесплатно от 5000 ₽».
- Итоги чекаута: строка «Доставка» — цена или «Бесплатно»
  (`Cart/Total.vue` через пропс, чтобы корзина без выбранной доставки её не
  показывала).
- `Cart/Progress.vue`: заменить мок на реальные значения из `progress`
  (осталось N ₽, ширина полосы = `qualifying_amount / min_order_amount`).
  Если подходящих правил нет — блок скрывается.
- Composable `useFreeShipping()` — запрос, дебаунс, кеш по составу корзины/гео.

## 10. Тесты (phpunit, `lara_admin`)

`tests/Feature/Delivery/FreeShippingTest.php` — **21 тест, 70 проверок, зелёные**
(`php vendor/bin/phpunit --filter FreeShippingTest`).

1. Матчинг: пустые группы = «любое»; несовпадение службы/вида/страны/региона/
   оплаты → нет матча; постамат считается ПВЗ.
2. Порог: `qualifying_amount >= min_order_amount` → бесплатно; на копейку ниже —
   платно.
3. Скидки: промокод опускает сумму выкупа ниже порога → доставка снова платная
   (ключевое требование заказчика; проверяется через реальный
   `POST /api/public/orders`).
4. Товарное условие: считается сумма только выбранных товаров; правило требует
   хотя бы один из них в корзине; подарки (`is_gift`) не считаются.
5. Несколько правил: выигрывает меньший порог; выключенные и просроченные
   игнорируются; `free_shipping_rule_id` и `delivery_cost_original` записаны.
6. Интеграция чекаута: `delivery_cost = 0`, `total_amount` пересчитан;
   `applyToOrder` идемпотентен и возвращает платную доставку, если условия
   перестали выполняться.
7. Прогресс: остаток до порога; при взятом нижнем пороге подсказка показывает
   следующий тариф.
8. Админ CRUD: создание с пивотами (товары/страны/регионы), обновление через
   `sync`, актуальный `is_active` в ответе, валидация неизвестных кодов, 401 без
   авторизации.

> В `setUp()` тесты выключают уже существующие правила (в БД может быть засеян
> `FreeShippingRuleSeeder`) — прогон не зависит от данных окружения.
>
> Полный `php vendor/bin/phpunit` красный и **до** этой фичи: тесты с
> `RefreshDatabase` (`Auth/*`, `ProfileTest`, `ExampleTest`) пересоздают общую БД
> `testing` и оставляют схему неполной, из-за чего падают остальные наборы.
> Прогонять пакеты по отдельности через `--filter`.

## 11. Этапы

1. ✅ Спека (этот документ) + диаграммы
   ([`free-shipping-architecture.md`](./free-shipping-architecture.md)).
2. ✅ Миграции + модель + связи (+ guard-миграция legacy `country/region/city`).
3. ✅ `FreeShippingService` (контекст, матчинг, прогресс, `applyToOrder`).
4. ✅ Admin CRUD API + `options` + `products` + публичный `evaluate`.
5. ✅ Применение в чекауте (публичный + админский) + `FreeShippingRuleSeeder`.
6. ✅ Тесты backend — 21/21.
7. ✅ Админка: меню, список, форма с мультивыборами.
8. ✅ Витрина: «Бесплатно», строка «Доставка» в итогах, прогресс-бар.
9. ✅ Деплой и проверка на сервере по
   [`../deploy-runbook.md`](../deploy-runbook.md) — см. журнал §14.
10. ⬜ Живой прокликивание сценария в браузере (админка → витрина).

## 12. Открытые вопросы

1. **Товарное условие — ждём подтверждения бизнеса.** Реализовано Решение 2:
   если товары в правиле выбраны, порог считается **только по ним** (и требуется
   хотя бы один в корзине).

   | Корзина (правило: от 5000 ₽, товар A) | Сейчас | Альтернатива «товары = фильтр» |
   |---|---|---|
   | A 3000 + прочее 4000 | 3000 → платно | 7000 → бесплатно |
   | A 3000 + A 2500 | 5500 → бесплатно | 5500 → бесплатно |
   | только прочее 6000 | платно | платно |

   Переключение — правка `FreeShippingService::qualifyingAmount()` + тест.
   Третий вариант: чекбокс «считать порог по всей корзине» в форме правила —
   тогда оба сценария доступны менеджеру без правок кода.
2. **Подарочная карта** — не уменьшает сумму выкупа (Решение 3): это способ
   оплаты, а не скидка.
3. **Легаси-способы доставки** (Boxberry, Почта России) в мультивыборе служб не
   участвуют: на витрине активны только СДЭК и Яндекс. Новые службы добавляются
   в `config/free_shipping.php`.
4. **Ручная стоимость доставки в админском заказе** — сейчас пересчёт правил
   выполняется и для админских заказов. Если менеджер должен иметь возможность
   зафиксировать цену доставки вручную, нужен флаг `delivery_cost_manual`
   (в спеке §7 помечено как уточняемое).

## 13. Реализация (файлы)

**lara_admin**
- `database/migrations/2026_08_18_090001_create_free_shipping_rules_table.php`
- `database/migrations/2026_08_18_090002_create_free_shipping_rule_pivot_tables.php`
- `database/migrations/2026_08_18_090003_add_free_shipping_to_orders_table.php`
- `config/free_shipping.php` — справочники служб/видов/оплат + карта кодов методов
- `app/Models/FreeShippingRule.php`, связи в `app/Models/Order.php`
- `app/Services/Delivery/FreeShippingService.php` (+ `FreeShipping/FreeShippingContext.php`,
  `FreeShipping/FreeShippingMatch.php`)
- `app/Services/Order/OrderValidationService::priceItemsForEstimate()` — пересчёт
  цен для «прикидочной» оценки без проверок остатков
- `app/Http/Controllers/Api/Admin/FreeShippingRuleController.php`,
  `app/Http/Requests/Delivery/FreeShippingRuleRequest.php`
- `app/Http/Controllers/Api/Public/Delivery/FreeShippingController.php`
- `PublicCheckoutController` шаг 7.5 и `Api\Admin\OrderController` шаг 7.6
- `database/seeders/FreeShippingRuleSeeder.php`
- `tests/Feature/Delivery/FreeShippingTest.php`

**again_dashboard**
- `src/features/free-shipping/{types,composables,components}` — список + форма
- пункт меню в `src/components/settings/index.vue`, роут `/settings/free-shipping`

**again_front**
- `stores/freeShipping.ts` — оценка правил и прогресс
- `components/Checkout/Delivery.vue` — «Бесплатно» у тарифов + подсказка,
  прокидывание `country_id`/`city_id`
- `components/Cart/Total.vue` + `Cart/Subtotal.vue` — строка «Доставка» и учёт
  её в «Итого» на чекауте (`withDelivery`)
- `components/Cart/Progress.vue` — реальный прогресс вместо мока
- `composables/useCheckoutSubmit.ts`, `types/order.ts` — гео-id в payload

## 14. Журнал

- **2026-08-18** — спека составлена; зафиксированы решения 1–10, найден и описан
  текущий хардкод порогов в `OrderCreationService::resolveDeliveryCost()`.
- **2026-08-18** — реализованы backend (правила, сервис, CRUD, публичная оценка,
  применение в чекауте, сидер, тесты), админка и витрина. Уточнено поведение
  акций (подарок, а не денежная скидка) — см. Решение 3.
- **2026-08-18** — выкачено на dev (`againdev3.ru`) по `../deploy-runbook.md`:
  5 миграций применены, `FreeShippingRuleSeeder` засеян (2 правила),
  `--filter FreeShippingTest` — 21/21 OK, фронты пересобраны.
  Проверено на сервере:
  - `POST /api/public/delivery/free-shipping/evaluate`: 5040 ₽ → ПВЗ Яндекса
    бесплатно (правило «от 4500»), курьер платный, прогресс «остаётся 2860 ₽»;
    2520 ₽ → всё платно, прогресс до 4500;
  - admin CRUD с реальным токеном: создание правила с товарами/страной/регионом/
    оплатой, `toggle`, удаление; правило срабатывает при оплате СБП и **не**
    срабатывает при оплате картой;
  - `applyToOrder` на живой БД (в откаченной транзакции): 5040 ₽ → доставка 0,
    `delivery_cost_original = 390`, total 5040; после падения суммы до 4000 ₽ —
    доставка снова 390, `free_shipping_rule_id` очищен, total 4390.
  Тестовые данные (правило, токен, заказ) удалены.
  По ходу выкатки исправлено: добавлена guard-миграция для legacy-таблиц
  `country/region/city` (их не было в схеме — тесты падали в чистом окружении);
  `store()` теперь отдаёт актуальный `is_active` (дефолт БД).
