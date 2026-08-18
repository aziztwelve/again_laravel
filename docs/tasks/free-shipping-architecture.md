# Бесплатная доставка — архитектура (визуально)

Дополнение к [`free-shipping.md`](./free-shipping.md). Диаграммы Mermaid:
поток данных (runtime), схема БД (ER), алгоритм матчинга и порядок расчёта
в чекауте. Открывается в любом просмотрщике Markdown с поддержкой Mermaid
(GitHub, GitLab, IDE-плагины).

---

## 1. Поток данных: от правила в админке до бесплатной доставки в заказе

```mermaid
flowchart TD
    subgraph ADMIN["Админка · again_dashboard · Настройки → Бесплатная доставка"]
        M["Менеджер заполняет правило:<br/>название · порог ₽ · службы · вид доставки<br/>товары · страны · регионы · способы оплаты"]
        CRUD["POST/PUT /api/free-shipping-rules"]
        DB1[("free_shipping_rules<br/>+ пивоты товаров/стран/регионов")]
        M --> CRUD --> DB1
    end

    subgraph FRONT["Витрина · again_front"]
        CART["Корзина: Cart/Progress.vue"]
        TARIFF["Чекаут: рассчитаны тарифы<br/>СДЭК и Яндекс.Доставки"]
        EVAL["POST /api/public/delivery/free-shipping/evaluate<br/>items · candidates[] · payment_method<br/>promo_code · country_id/city_id"]
        UI["«Бесплатно» вместо цены +<br/>«Бесплатная доставка от N ₽ —<br/>добавьте ещё M ₽»"]
        BAR["Прогресс-бар:<br/>«До бесплатной доставки осталось M ₽»"]
        CART --> EVAL --> BAR
        TARIFF --> EVAL --> UI
    end

    subgraph BACKEND["Бэкенд · lara_admin"]
        PRICE["OrderValidationService::priceItemsForEstimate<br/>цены пересчитываются на сервере<br/>(цены с фронта игнорируются)"]
        SVC["FreeShippingService<br/>evaluate / progress / evaluateCandidates"]
        EVAL --> PRICE --> SVC
        DB1 -. активные правила .-> SVC
        SVC -->|"is_free по каждому варианту<br/>+ progress"| EVAL
    end

    subgraph CHECKOUT["Оформление заказа · PublicCheckoutController"]
        ORDER["4. createOrder<br/>delivery_cost = цена тарифа"]
        PROMO["6. промокод → item.price"]
        PROMOTION["7. акции (подарки, is_gift)"]
        APPLY["7.5 FreeShippingService::applyToOrder<br/>сумма выкупа = Σ qty × item.price<br/>(без подарков)"]
        GIFTCARD["8. подарочная карта"]
        ORDER --> PROMO --> PROMOTION --> APPLY --> GIFTCARD
    end

    UI -->|«Оформить заказ»| ORDER
    DB1 -. активные правила .-> APPLY

    subgraph RESULT["Заказ"]
        FREE["Правило сработало:<br/>delivery_cost = 0<br/>delivery_cost_original = 390<br/>free_shipping_rule_id = N"]
        PAID["Не сработало:<br/>delivery_cost = цена тарифа<br/>free_shipping_rule_id = NULL"]
        TOTAL["Order::updateTotalAmount()<br/>total = Σ items + delivery − gift_card"]
        FREE --> TOTAL
        PAID --> TOTAL
    end

    APPLY -->|сумма ≥ порога| FREE
    APPLY -->|сумма < порога| PAID
```

Ключевое: витрина **показывает**, сервер **решает**. Цена доставки в заказе
считается только в шаге 7.5 — после промокода и акций, поэтому скидки, уронившие
сумму выкупа ниже порога, автоматически возвращают платную доставку.

---

## 2. Алгоритм матчинга правила

```mermaid
flowchart TD
    START["Контекст: корзина · служба · вид доставки<br/>способ оплаты · страна/регион/город"] --> RULES["Активные правила<br/>is_active + окно дат<br/>сортировка: порог ↑, приоритет ↓"]
    RULES --> LOOP{"Следующее правило"}

    LOOP --> C1{"services заданы?"}
    C1 -->|"нет — «любая»"| C2
    C1 -->|"да"| C1A{"служба в списке?"}
    C1A -->|нет| NEXT["→ следующее правило"]
    C1A -->|да| C2

    C2{"delivery_types заданы?"} -->|нет| C3
    C2 -->|да| C2A{"вид доставки в списке?<br/>постамат = ПВЗ"}
    C2A -->|нет| NEXT
    C2A -->|да| C3

    C3{"payment_methods заданы?"} -->|нет| C4
    C3 -->|да| C3A{"способ оплаты в списке?"}
    C3A -->|нет| NEXT
    C3A -->|да| C4

    C4{"страны/регионы заданы?"} -->|нет| C5
    C4 -->|да| C4A["город → region_id → country_id<br/>(фолбэк: матч по названиям)"]
    C4A --> C4B{"попадает в списки?"}
    C4B -->|нет| NEXT
    C4B -->|да| C5

    C5{"товары заданы?"} -->|нет| SUM1["сумма выкупа = вся корзина"]
    C5 -->|да| C5A{"есть хотя бы один<br/>из этих товаров?"}
    C5A -->|нет| NEXT
    C5A -->|да| SUM2["сумма выкупа =<br/>только выбранные товары"]

    SUM1 --> CMP
    SUM2 --> CMP
    CMP{"сумма выкупа ≥<br/>min_order_amount?"}
    CMP -->|да| WIN["✅ Доставка бесплатна<br/>(правило с наименьшим порогом)"]
    CMP -->|нет| PROG["учитывается в progress:<br/>remaining = порог − сумма"]
    PROG --> NEXT
    NEXT --> LOOP
    LOOP -->|правила закончились| PAID["❌ Доставка платная<br/>+ подсказка по ближайшему порогу"]
```

Два режима проверки:

| Режим | Где | Неизвестное значение в контексте (NULL) |
|---|---|---|
| строгий | `evaluate()` — решение о бесплатности | условие **не** выполнено |
| щадящий | `progress()` — подсказка «осталось N ₽» | условие пропускается (покупатель ещё выберет) |

Поэтому в корзине, где доставка и оплата ещё не выбраны, прогресс-бар всё равно
показывает ближайший порог.

---

## 3. Сумма выкупа: что входит, что нет

```mermaid
flowchart LR
    A["Цена товара<br/>original_price"] --> B["− скидка товара"]
    B --> C["− промокод"]
    C --> D["= item.price<br/>(финальная цена позиции)"]
    D --> E["Σ qty × item.price<br/>по не-подарочным позициям<br/><b>= сумма выкупа</b>"]

    G1["Подарок акции<br/>is_gift = true, price = 0"] -.->|не входит| E
    G2["Стоимость доставки"] -.->|не входит| E
    G3["Оплата подарочной картой<br/>(это оплата, не скидка)"] -.->|не уменьшает| E

    E --> CMP{"≥ min_order_amount?"}
    CMP -->|да| FREE["доставка 0 ₽"]
    CMP -->|нет| PAID["доставка по тарифу"]
```

> Акции в проекте дают **подарок**, а не денежную скидку. «Скидка вместо
> подарка» = отказ от подарка в пользу промокода/обычных скидок. Требование
> «с акцией сумма ниже 5000 → доставка платная» выполняется так: подарок в сумму
> не идёт, а промокод и скидки её уменьшают.

---

## 4. Схема БД

```mermaid
erDiagram
    free_shipping_rules {
        bigint id PK
        string name "Доставка_СДЕК_ПВЗ"
        boolean is_active
        int priority "порядок в списке"
        decimal min_order_amount "порог, ₽"
        json services "[cdek, yandex] · пусто = любая"
        json delivery_types "[pickup, courier] · пусто = любой"
        json payment_methods "коды оплат · пусто = любая"
        timestamp starts_at "необязательное окно"
        timestamp ends_at
        timestamp deleted_at "soft delete"
    }

    free_shipping_rule_products {
        bigint free_shipping_rule_id FK
        bigint product_id FK
    }

    free_shipping_rule_countries {
        bigint free_shipping_rule_id FK
        bigint country_id "legacy: signed bigint, есть id = 0"
    }

    free_shipping_rule_regions {
        bigint free_shipping_rule_id FK
        bigint region_id
    }

    orders {
        bigint id PK
        decimal delivery_cost "0 при бесплатной"
        decimal delivery_cost_original "цена тарифа до обнуления"
        bigint free_shipping_rule_id FK "какое правило сработало"
        json delivery_data "provider · delivery_type · price"
        string payment_method
    }

    order_items {
        bigint order_id FK
        bigint product_id FK
        int quantity
        decimal price "финальная цена после скидок"
        boolean is_gift "подарки в сумму не идут"
    }

    country { bigint id PK
        string name }
    region { bigint id PK
        bigint country_id FK
        string name }
    city { bigint id PK
        bigint region_id FK
        string name }
    products { bigint id PK
        string name }

    free_shipping_rules ||--o{ free_shipping_rule_products : "товары"
    free_shipping_rules ||--o{ free_shipping_rule_countries : "страны"
    free_shipping_rules ||--o{ free_shipping_rule_regions : "регионы"
    free_shipping_rule_products }o--|| products : ""
    free_shipping_rule_countries }o--|| country : ""
    free_shipping_rule_regions }o--|| region : ""
    country ||--o{ region : ""
    region ||--o{ city : "город определяет регион и страну"
    free_shipping_rules ||--o{ orders : "free_shipping_rule_id"
    orders ||--o{ order_items : ""
```

---

## 5. Порядок расчёта в чекауте (почему именно 7.5)

```mermaid
sequenceDiagram
    participant F as Витрина
    participant C as PublicCheckoutController
    participant O as OrderCreationService
    participant P as PromoCode/Promotion
    participant S as FreeShippingService
    participant D as БД

    F->>C: POST /api/public/orders
    C->>O: 4. createOrder
    Note over O: delivery_cost = цена тарифа<br/>(хардкода 7900/4500 больше нет)
    O->>D: INSERT orders + order_items
    C->>P: 6. промокод → item.price
    C->>P: 7. акции → подарки (is_gift, price 0)
    C->>S: 7.5 applyToOrder(order, гео-id)
    S->>D: SELECT активные правила
    S->>S: сумма выкупа = Σ qty × item.price
    alt сумма ≥ порога
        S->>D: delivery_cost = 0<br/>delivery_cost_original = 390<br/>free_shipping_rule_id = N
    else сумма < порога
        S->>D: delivery_cost = 390<br/>free_shipping_rule_id = NULL
    end
    S->>D: updateTotalAmount()
    C->>P: 8. подарочная карта (списывается с итога)
    C-->>F: 201 + заказ
```

Если считать бесплатность на шаге 4, промокод и акции ещё не применены — сумма
была бы завышена. Поэтому расчёт идёт после них, а `applyToOrder` идемпотентен:
исходная цена тарифа хранится в `delivery_cost_original`, повторный вызов её не
теряет и умеет вернуть платную доставку.

---

## 6. Пример: два тарифных «этажа» (данные сидера)

```mermaid
flowchart LR
    A["Корзина 2 520 ₽"] --> A1["ПВЗ 390 ₽ · курьер 590 ₽<br/>оба платные<br/>подсказка: до 4 500 осталось 1 980 ₽"]
    B["Корзина 5 040 ₽"] --> B1["ПВЗ — <b>Бесплатно</b> (правило «от 4 500»)<br/>курьер 590 ₽<br/>подсказка: до 7 900 осталось 2 860 ₽"]
    C["Корзина 8 000 ₽"] --> C1["ПВЗ и курьер — <b>Бесплатно</b><br/>подсказки нет"]
```

Проверено на dev `againdev3.ru` — см. журнал в
[`free-shipping.md`](./free-shipping.md#14-журнал).
