# СДЭК — архитектура интеграции (API v2)

> **Статус:** частично реализовано и включено в production 2026-08-15.
> Реализованы расчёт, checkout, создание после оплаты, polling, `ORDER_STATUS`
> webhook, трекинг и базовые действия админки. Оставшиеся задачи перечислены в
> разделе «Текущий backlog».

## 1. Подтверждённые условия

| Вопрос | Решение |
|---|---|
| API | СДЭК API v2: `/v2/*` |
| Тестовый контур | `https://api.edu.cdek.ru` |
| Production | `https://api.cdek.ru` |
| Авторизация | OAuth 2.0 `client_credentials`, Bearer JWT, TTL по `expires_in` (обычно 3600 с) |
| Курьерская доставка | Поддерживается тарифами с режимом «до двери» |
| Доставка в ПВЗ/постамат | Поддерживается через `delivery_point` и `/v2/deliverypoints` |
| Расчёт | `POST /v2/calculator/tarifflist` по доступным тарифам |
| Создание заказа | Асинхронное `POST /v2/orders`, ответ `202 ACCEPTED` не означает создание заказа |
| Статусы | `ORDER_STATUS` webhook, плюс polling как защита от пропущенных событий |
| Покупательский трекинг | Номер СДЭК (`cdek_number`) и ссылка `https://www.cdek.ru/ru/tracking?order_id=<номер>` |

Официальные источники:

- [Портал документации СДЭК API](https://apidoc.cdek.ru/)
- Локальная OpenAPI-спецификация: `/home/aziz/tmp/openapi_api_v2_integration.json`

Не использовать тестовые учётные данные из публичной документации в production,
`.env`, БД или исходном коде. Устаревший `CdekSDK2` не расширять: новый клиент
работает через Laravel HTTP client непосредственно с документированным API v2.

### Текущее состояние production

- `CDEK_DELIVERY_MODE=production`; OAuth-авторизация и расчёт тарифов проверены
  на реальном API: курьерский расчёт для Москвы вернул 7 тарифов.
- Витрина поддерживает `cdek_courier`, `cdek_pickup` и `cdek_postamat`: поиск
  города СДЭК, выбор ПВЗ/постамата, расчёт и сохранение выбранного тарифа.
- После оплаты создаётся асинхронная заявка; polling каждые 10 минут и webhook
  обновляют данные через авторитетный `GET /v2/orders`.
- Зарегистрирована ровно одна production-подписка `ORDER_STATUS` на
  `https://againdev3.ru/api/public/webhooks/cdek`. Команда
  `php artisan cdek:register-webhook` сверяет существующие записи, создаёт
  отсутствующую и удаляет только дубли с тем же URL.
- В документации СДЭК для callback не описаны подпись, секрет или список IP.
  Endpoint ограничен `throttle:60,1` и не доверяет payload: он лишь ставит
  синхронизацию заказа в очередь, а статус читается из API СДЭК.

### Текущий backlog

- [x] Заполнены production `CDEK_DELIVERY_SENDER_NAME` и
  `CDEK_DELIVERY_SENDER_PHONE` данными ООО «ЭГЕЙН» с сайта.
- [ ] Провести реальный E2E: оплаченный заказ, `ACCEPTED → SUCCESSFUL`, номер
  СДЭК, callback `ORDER_STATUS` и отмена.
- [ ] Реализовать PDF накладной и клиентский возврат.
- [ ] Заменить фиксированные габариты на общий `PackagingResolver`.
- [ ] Добавить `cdek_api_logs` с маскированием токенов и персональных данных.
- [ ] При получении официальных IP СДЭК добавить allowlist в nginx/firewall.

## 2. Контуры и авторизация

```text
Sandbox:    https://api.edu.cdek.ru
Production: https://api.cdek.ru
Auth:       POST /v2/oauth/token?grant_type=client_credentials&client_id=...&client_secret=...
Requests:   Authorization: Bearer <access_token>
```

Токен кэшируется вне БД под ключом, зависящим от режима, до
`expires_in - 60 секунд`. При `401` клиент один раз сбрасывает кэш, получает
новый токен и повторяет запрос. `client_secret`, Bearer-токен и полные
персональные данные маскируются в логах.

```env
CDEK_DELIVERY_ENABLED=true
CDEK_DELIVERY_MODE=sandbox                    # sandbox | production
CDEK_DELIVERY_ACCOUNT=
CDEK_DELIVERY_SECURE_PASSWORD=
CDEK_DELIVERY_ORDER_TYPE=1                    # 1: интернет-магазин; 2: доставка
CDEK_DELIVERY_SENDER_CITY_CODE=
CDEK_DELIVERY_SENDER_POSTAL_CODE=
CDEK_DELIVERY_SENDER_ADDRESS=
CDEK_DELIVERY_SENDER_NAME=
CDEK_DELIVERY_SENDER_PHONE=
CDEK_DELIVERY_SHIPMENT_POINT=                 # код ПВЗ для самостоятельного привоза, если применимо
CDEK_DELIVERY_WEBHOOK_URL=https://example.ru/api/webhooks/cdek
```

```php
// config/services.php
'cdek_delivery' => [
    'enabled' => env('CDEK_DELIVERY_ENABLED', false),
    'mode' => env('CDEK_DELIVERY_MODE', 'sandbox'),
    // Account and Secure password are CDEK's names for OAuth client credentials.
    'account' => env('CDEK_DELIVERY_ACCOUNT'),
    'secure_password' => env('CDEK_DELIVERY_SECURE_PASSWORD'),
    'order_type' => (int) env('CDEK_DELIVERY_ORDER_TYPE', 1),
    'sender' => [
        'city_code' => env('CDEK_DELIVERY_SENDER_CITY_CODE'),
        'postal_code' => env('CDEK_DELIVERY_SENDER_POSTAL_CODE'),
        'address' => env('CDEK_DELIVERY_SENDER_ADDRESS'),
        'name' => env('CDEK_DELIVERY_SENDER_NAME'),
        'phone' => env('CDEK_DELIVERY_SENDER_PHONE'),
        'shipment_point' => env('CDEK_DELIVERY_SHIPMENT_POINT'),
    ],
    'webhook_url' => env('CDEK_DELIVERY_WEBHOOK_URL'),
    'base_url' => [
        'sandbox' => 'https://api.edu.cdek.ru',
        'production' => 'https://api.cdek.ru',
    ],
],
```

После изменения production `.env` необходимо обновить Laravel-конфигурацию:

```bash
cd /var/www/html/laravel
php artisan optimize:clear
php artisan config:cache
```

## 3. Целевой пользовательский сценарий

```text
Checkout
  → ввод города и адреса либо выбор ПВЗ/постамата
  → поиск города СДЭК и загрузка актуальных ПВЗ
  → расчёт тарифов по направлению, весу и габаритам
  → выбор подходящего тарифа
  → сохранение выбора в orders.delivery_data
  → успешная оплата
  → асинхронная регистрация заказа СДЭК
  → проверка результата создания до SUCCESSFUL/INVALID
  → webhook ORDER_STATUS + polling
  → номер и ссылка на отслеживание в ЛК и уведомлениях
```

Внешний заказ создаётся только после успешной оплаты. `orders.id` используется
как стабильный ASCII `number` заказа интернет-магазина, например `order-12345`;
он уникален среди активных заказов договора и служит нашей защитой от дублей.
Повторный платёжный webhook не должен создавать вторую доставку.

## 4. Методы CDEK API v2

| Назначение | API |
|---|---|
| OAuth-токен | `POST /v2/oauth/token` |
| Поиск города | `GET /v2/location/suggest/cities`, `GET /v2/location/cities` |
| Список ПВЗ и постаматов | `GET /v2/deliverypoints` |
| Расчёт доступных тарифов | `POST /v2/calculator/tarifflist` |
| Точный расчёт выбранного тарифа и услуг | `POST /v2/calculator/tariff` или `/v2/calculator/tariffAndService` |
| Регистрация заказа | `POST /v2/orders` |
| Получение заказа и статусов | `GET /v2/orders?im_number=...` или `GET /v2/orders/{uuid}` |
| Редактирование/отмена | `PATCH /v2/orders`, `DELETE /v2/orders/{uuid}` |
| Клиентский возврат | `POST /v2/orders/{uuid}/clientReturn` |
| Накладная и ШК | `POST /v2/print/orders`, `GET /v2/print/orders/{uuid}.pdf` |
| Подписки на вебхуки | `GET, POST /v2/webhooks`, `DELETE /v2/webhooks/{uuid}` |

Для оплаченного на сайте заказа не передаём наложенный платёж
`delivery_recipient_cost`. ПВЗ выбирается только из актуального ответа
`/v2/deliverypoints`: кэш/синхронизация допустимы не дольше суток, как
рекомендует СДЭК. Для ПВЗ и постамата в расчёт и создание передаётся
`delivery_point`; для курьера передаётся `to_location` с городом и адресом.

## 5. Модель данных

### `orders.delivery_data`

```json
{
  "provider": "cdek",
  "delivery_type": "courier",
  "tariff_code": 136,
  "tariff_name": "Посылка дверь-дверь",
  "delivery_mode": 1,
  "price": 349.0,
  "currency": "RUB",
  "period": {"min": 2, "max": 4},
  "destination": {"city_code": 44, "address": "..."},
  "pvz": {"code": "MSK123", "type": "PVZ", "address": "...", "coordinates": [37.6, 55.7]},
  "cdek_number": null,
  "tracking_url": null
}
```

### `cdek_orders`

Отдельная таблица хранит связь внутреннего заказа с асинхронно созданным
заказом СДЭК.

```text
id, order_id unique, shipment_id nullable,
cdek_uuid nullable unique, cdek_number nullable unique,
request_uuid nullable unique, creation_state nullable,
status_code nullable, status_name nullable, internal_status nullable,
delivery_type, delivery_mode nullable, tariff_code, price nullable, currency,
pvz_code nullable, tracking_url nullable,
external_order_number unique, last_synced_at nullable, last_error nullable,
timestamps, softDeletes
```

`external_order_number` формируется один раз до запроса и передаётся как
`number`. `request_uuid` и `creation_state` отражают состояние асинхронного
запроса (`ACCEPTED`, `SUCCESSFUL`, `INVALID`), а не статус перевозки.

### Логи и история

- `cdek_api_logs`: метод, HTTP-код, длительность, очищенные запрос/ответ и ошибка.
- `cdek_status_events`: источник (`webhook` или `polling`), код/имя статуса, raw payload, время получения.
- Уникальность события: `cdek_order_id + status_code + status_datetime`.
- Хранение логов не менее 30 дней; токены, секреты, телефон, адрес и ФИО маскируются.

## 6. Backend

```text
App\Services\Delivery\Cdek\CdekClient
  — OAuth-кэш, Bearer auth, retry после 401, таймауты, маскированные API-логи.

App\Services\Delivery\Cdek\PayloadBuilder
  — locations, recipient, packages, selected tariff and CDEK order payload.

App\Services\Delivery\Cdek\StatusMapper
  — последний актуальный статус CDEK → OrderStatus / ShipmentStatus / customer status.

App\Services\Delivery\CdekDeliveryService
  — cities, pickupPoints, calculateTariffs, createOrder, sync, cancel, return, printLabel.
```

`CdekDeliveryService` сохраняет существующий контракт `DeliveryService`, но
legacy-вызовы `CdekSDK2` и жёстко заданный `setTest(true)` удаляются. Настройки
сервиса объединяются в порядке `config/services.php` →
`delivery_service_settings.settings` → настройки метода доставки, как уже
реализовано для Яндекс.Доставки.

### Габариты и состав

`packages` обязателен в расчёте и создании. Вес передаётся в граммах, размеры
в сантиметрах. Для пакета обязательны `number` и `weight`; при весе от 100 г и
для постамата обязательны все три габарита. Для заказа типа `1` также передаём
товарные позиции в `packages[].items`. Источник размеров: товар → дефолт
категории → глобальный дефолт; применяется общий `PackagingResolver`.

## 7. Оплата, создание, webhook и polling

### Мост оплаты → доставка

Проверенный `Pay` webhook CloudPayments:

1. обновляет `Order.payment_status`;
2. идемпотентно создаёт `CdekOrder` с `external_order_number`;
3. диспатчит `CreateCdekOrderJob` только для `provider: cdek`;
4. job регистрирует `POST /v2/orders` и сохраняет `request_uuid`;
5. job повторно читает заказ по `im_number` до `SUCCESSFUL` или `INVALID`;
6. при успехе создаёт/обновляет `Shipment`, `cdek_number` и tracking URL.

`202 ACCEPTED` не является успешным созданием отправления. При `INVALID` заказ
не повторяется автоматически без исправления причины: ошибка сохраняется и
видна менеджеру.

### Вебхуки и fallback-polling

При развёртывании production команда `cdek:register-webhook` сверяет
`GET /v2/webhooks` и создаёт ровно одну подписку `ORDER_STATUS` на HTTPS URL из
конфигурации. Не создавать подписку без сверки: API разрешает несколько
одинаковых подписок.

Endpoint `POST /api/public/webhooks/cdek` ограничен `throttle:60,1`; payload не
считается доверенным. Если номер заказа известен, endpoint ставит
`SyncCdekOrderJob` в очередь, который получает авторитетный статус через API
СДЭК. Документация управления webhook не описывает подпись, секрет или IP
источника; при получении официальных диапазонов IP ограничить endpoint также на
nginx/firewall.

Команда `cdek:poll-statuses` каждые 10-15 минут выбирает незавершённые заказы,
а также запросы со состоянием `ACCEPTED`, получает заказ по `im_number`, пишет
события только при изменении и обновляет `CdekOrder`, `Shipment`, `Order` и
`orders.delivery_data`. Используются `withoutOverlapping()`, лимит запросов и
экспоненциальная задержка при ошибках API.

## 8. Витрина и админка

### Витрина (`again_front`)

- Чекаут: переключатель «Курьер» / «ПВЗ» / «Постамат» только для доступных тарифов.
- Поиск населённого пункта возвращает и сохраняет `city_code` СДЭК, не название города как идентификатор.
- Карта и список фильтруют актуальные ПВЗ по `city_code`, типу и доступности; выбранный `pvz.code` повторно проверяется сервером.
- Показываются название тарифа, стоимость и диапазон сроков из расчёта.
- В ЛК: понятный нормализованный статус, номер СДЭК и ссылка на трекинг.

### Админка (`again_dashboard`)

- Карточка заказа: UUID, номер СДЭК, запрос создания, тариф, ПВЗ/адрес, статус, последняя ошибка и PDF накладной.
- Действия: повторно проверить создание, обновить статус, отменить до движения груза, оформить возврат и запросить накладную.
- Таблица/фильтры по состоянию создания, статусу перевозки и зависшим `ACCEPTED`.
- «Параметры отправки» → «Город отправки» — автокомплит по складам СДЭК:
  `GET /api/third-party-integrations/cdek/warehouses?query=…&limit=…` отдаёт
  ПВЗ/постаматы РФ (поиск по городу, региону и адресу; ранжирование: префикс
  города → вхождение города → регион → адрес). Полный справочник кэшируется
  на сутки (`cdek:warehouses:ru`, один запрос `/v2/deliverypoints`), выбор
  склада заполняет `sender.city_name` + `sender.city_code`.

## 9. Порядок реализации

1. Получить production `client_id`, `client_secret`, параметры договора, склад отправления и подтвердить схему защиты webhook.
2. Добавить конфигурацию, миграции `cdek_orders`/логов/событий и тесты OAuth-клиента.
3. Реализовать поиск города, актуальные ПВЗ и расчёт тарифов; подключить чекаут.
4. Реализовать `PayloadBuilder`, создание после оплаты и обработку асинхронного результата.
5. Реализовать `ORDER_STATUS` webhook, polling, трекинг, маппинг статусов и уведомления.
6. Реализовать накладную, отмену, возврат и действия админки.
7. Прогнать sandbox end-to-end: курьер, ПВЗ, постамат, повтор оплаты, `ACCEPTED` → `SUCCESSFUL`, `INVALID`, webhook, polling, отмена и печать.
8. Включить production только после проверки реальных тарифов, склада и webhook.

## 10. Критерии готовности

- [ ] Sandbox рассчитывает курьерскую доставку, ПВЗ и постамат.
- [ ] Выбранные `city_code`, ПВЗ, тариф и цена серверно валидируются и сохраняются.
- [ ] После оплаты создаётся ровно один заказ СДЭК; `ACCEPTED` не показывается как созданная доставка.
- [ ] `SUCCESSFUL` сохраняет UUID, `cdek_number`, Shipment и tracking URL.
- [ ] Webhook и polling идемпотентно обновляют статусы без дублей.
- [ ] Покупатель видит номер и ссылку на отслеживание.
- [ ] Менеджер может посмотреть ошибку, накладную и отменить доступную отправку.
- [ ] Логи не содержат токенов, секретов или незащищённых персональных данных.
- [ ] Production проверен на реальном договоре, складе, тарифах и webhook URL.
