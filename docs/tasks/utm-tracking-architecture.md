# UTM-трекинг — архитектура (визуально)

Дополнение к [`utm-tracking.md`](./utm-tracking.md). Диаграммы Mermaid:
поток данных (runtime) и схема БД (ER). Открывается в любом просмотрщике
Markdown с поддержкой Mermaid (GitHub, GitLab, IDE-плагины).

---

## 1. Поток данных (от создания метки до аналитики)

```mermaid
flowchart TD
    subgraph ADMIN["Админка · again_dashboard"]
        M["Менеджер: название + канал + тег + target_url"]
        CREATE["POST /api/utm/links"]
        SVC["UtmLinkService<br/>генерит slug + tracking_url"]
        COPY["Кнопка «Копировать»<br/>https://site/go/{slug}"]
        M --> CREATE --> SVC --> COPY
    end

    SHARE["Раздаётся в канале<br/>IG / TG / VK / Email / WA / MAX"]
    COPY --> SHARE

    subgraph PUBLIC["Публичный трекер · web.php (throttle:60,1)"]
        GO["GET /go/{slug}"]
        FIND["UtmLink по slug + is_active<br/>иначе 404"]
        VISIT["INSERT utm_visits<br/>ip, user_agent, referrer,<br/>visitor_hash = sha256(ip|ua)"]
        COOKIE["Set-Cookie utm_link_id<br/>httpOnly · 30 дней · config/utm.php"]
        REDIRECT["302 redirect →<br/>target_url + utm-параметры"]
        GO --> FIND --> VISIT --> COOKIE --> REDIRECT
    end

    SHARE -->|клик| GO

    subgraph FRONT["Витрина · again_front"]
        BROWSE["Клиент ходит по сайту,<br/>кладёт товары в корзину<br/>(кука живёт 30 дней, last-click)"]
        CHECKOUT["POST /api/.../orders"]
        RESOLVE["resolveUtmLinkId(request.cookie)"]
        ORDERCREATE["OrderCreationService::createOrder<br/>orders.utm_link_id = N"]
        BROWSE --> CHECKOUT --> RESOLVE --> ORDERCREATE
    end

    REDIRECT --> BROWSE

    subgraph ANALYTICS["Аналитика · auth:sanctum"]
        REQ["GET /api/analytics/utm"]
        CTRL["UtmAnalyticsController::index"]
        V["visitsByLink:<br/>COUNT(DISTINCT visitor_hash)"]
        O["ordersByLink: GROUP BY utm_link_id<br/>orders / orders_amount (все статусы)<br/>purchases / purchases_amount (paid)<br/>clients = COUNT(DISTINCT client_id)"]
        OUT["rows + totals + pie(клиенты) + chart(посещения)"]
        REQ --> CTRL --> V --> OUT
        CTRL --> O --> OUT
    end

    VISIT -. источник посещений .-> V
    ORDERCREATE -. источник заказов .-> O
    OUT --> UI["Таблица · круговая (клиенты) · гистограмма (посещения)"]
```

### Метрики и формулы (как в контроллере)

| Метрика | Источник / формула |
|---------|--------------------|
| Посещения | `utm_visits`, `COUNT(DISTINCT visitor_hash)` за период |
| Заказы | `orders` по `utm_link_id`, все статусы оплаты (refunded входит) |
| Оборот (заказы) | `SUM(total_amount)` всех заказов по метке |
| Покупки | заказы с `payment_status = paid` (refunded НЕ входит) |
| Сумма покупок | `SUM(total_amount)` где `payment_status = paid` |
| Клиенты (для круговой) | `COUNT(DISTINCT client_id)` |
| Конв. в заказ | `заказы / посещения × 100` (деление на 0 → 0) |
| Конв. в покупку | `покупки / заказы × 100` (деление на 0 → 0) |

---

## 2. Схема БД (ER)

```mermaid
erDiagram
    MARKETING_CHANNELS ||--o{ UTM_LINKS : "канал"
    UTM_TAGS ||--o{ UTM_LINKS : "тег (nullable)"
    UTM_LINKS ||--o{ UTM_VISITS : "посещения"
    UTM_LINKS ||--o{ ORDERS : "атрибуция (nullable)"
    CLIENTS ||--o{ ORDERS : "клиент (nullable)"

    MARKETING_CHANNELS {
        bigint id PK
        string name
        string code UK "= utm_source (ig, tg, …)"
        boolean is_system "системный — нельзя удалить"
        boolean is_active
        int sort
    }

    UTM_TAGS {
        bigint id PK
        string name UK "Блогер1, Блогер2, …"
    }

    UTM_LINKS {
        bigint id PK
        string name
        bigint marketing_channel_id FK
        bigint utm_tag_id FK "nullable"
        string target_url
        string utm_source
        string utm_medium "nullable"
        string utm_campaign "nullable"
        string utm_content "nullable"
        string utm_term "nullable"
        string slug UK "идентификатор метки для /go/{slug}"
        boolean is_active
        timestamp deleted_at "SoftDeletes"
    }

    UTM_VISITS {
        bigint id PK
        bigint utm_link_id FK
        timestamp visited_at "index"
        string ip_address "nullable"
        text user_agent "nullable"
        string referrer "nullable"
        string visitor_hash "index · sha256(ip|ua)"
    }

    ORDERS {
        bigint id PK
        bigint client_id FK "nullable (гость)"
        bigint utm_link_id FK "nullable · атрибуция"
        string payment_status "paid / pending / refunded / failed"
        decimal total_amount
        timestamp created_at "фильтр по периоду"
    }

    CLIENTS {
        bigint id PK
        string email "клиент (self-auth)"
        bigint client_level_id FK "nullable"
    }
```

---

## 3. Демо-данные

Сидер `Database\Seeders\UtmDemoSeeder` наполняет все таблицы вариантами данных
(в т.ч. граничные случаи). Запуск:

```bash
php artisan db:seed --class=UtmDemoSeeder
```

После сидинга открыть в дашборде: **Аналитика → Источники заказов**
(`/analytics/order-sources`).

> Даты привязаны к `now()`: большая часть данных попадает в окно «последние
> 30 дней» (период по умолчанию), часть — на 60–90 дней назад, чтобы проверить
> фильтр по периоду и месячную гранулярность графика (пресет «Всё время»).
