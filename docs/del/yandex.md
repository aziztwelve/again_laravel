# Яндекс.Доставка — архитектура интеграции (NDD / Platform API)

> **Статус:** проектный документ, актуализирован 2026-07-29 по ответу
> поддержки Яндекс.Доставки. Интеграция ещё не реализована end-to-end.

## 1. Подтверждённые условия

У магазина подключена **NDD — доставка по России / в другой день**. Несмотря на
название продукта, технически используется **Platform API**, а не NDD Express
claims API.

| Вопрос | Подтверждённое решение |
|---|---|
| API | Platform API: `/api/b2b/platform/*` |
| Курьерская доставка | Доступна |
| Доставка в ПВЗ | Доступна |
| Список ПВЗ | Официальный виджет либо `pickup-points/list` |
| Вебхуки статусов | Не предоставляются |
| Статусы | Периодический polling API |
| Покупательский трекинг | Ссылка на отслеживание из ответа API |
| Тестовый контур | Тестовые host, токен и станция из официальной документации |
| Production | Bearer-токен из ЛК + `platform_station_id` склада от менеджера |

Официальные источники:

- [Введение и окружения](https://yandex.ru/support/delivery-profile/ru/api/other-day/)
- [Список методов](https://yandex.ru/support/delivery-profile/ru/api/other-day/ref/)
- [ПВЗ и точки самопривоза](https://yandex.ru/support/delivery-profile/ru/api/other-day/ref/2.-Tochki-samoprivoza-i-PVZ/apib2bplatformpickup-pointslist-post)
- [Доступ к API](https://yandex.ru/support/delivery-profile/ru/api/other-day/access)

> Не использовать в новой реализации `claims/create`, `claims/accept`,
> `claims/info`, `offers/calculate` и API `b2b/cargo/integration/v2`: они
> относятся к другому продукту и не соответствуют подключённой NDD.

## 2. Контуры и авторизация

```text
Sandbox:    https://b2b.taxi.tst.yandex.net
Production: https://b2b-authproxy.taxi.yandex.net
Base path:  /api/b2b/platform
Auth:       Authorization: Bearer <token>
```

Для production коммерческий менеджер выдаёт `platform_station_id` —
идентификатор точки отгрузки. Его нельзя подменять адресом или тестовой
станцией.

Настройки хранятся только в `.env`/`config/services.php`; токены не попадают в
БД, логи и git.

```env
YANDEX_DELIVERY_ENABLED=true
YANDEX_DELIVERY_MODE=sandbox                 # sandbox | production
YANDEX_DELIVERY_TOKEN=
YANDEX_DELIVERY_PLATFORM_STATION_ID=
YANDEX_DELIVERY_GEOCODER_KEY=
YANDEX_MAPS_JS_API_KEY=
```

### Геокодирование курьерского адреса

Для расчёта курьерской Яндекс.Доставки checkout сначала преобразует полный
адрес (`<город>, <адрес>`) в координаты через HTTP API Геокодера Яндекс.Карт.
Без `YANDEX_DELIVERY_GEOCODER_KEY` курьерские тарифы не рассчитываются, а
покупатель увидит сообщение «Не удалось определить координаты адреса».

Нужен именно **API Key** с подключённым продуктом **«API Геокодера»** (или
«JavaScript API + Геокодер») из [Кабинета разработчика
Яндекс.Карт](https://yandex.ru/maps-api/console/). `Secret Key` для этого
HTTP-запроса не используется и не должен попадать в `.env` приложения.

После выпуска ключ может активироваться до 15 минут. Передавать ключ в git,
логи, чат или клиентский runtime-config нельзя. При компрометации ключ следует
отозвать и выпустить новый.

После изменения production `.env` необходимо обновить Laravel-конфигурацию:

```bash
cd /var/www/html/laravel
php artisan optimize:clear
php artisan config:cache
```

Проверка выполняется без создания заявки в Яндекс.Доставке:

```bash
curl -sS -G 'https://againdev3.ru/api/public/delivery/yandex/geocode' \
  --data-urlencode 'address=Санкт-Петербург, переулок Каховского, 7'
```

Ожидаемый результат — `{"success":true,"coordinates":[longitude,latitude]}`.
Если Яндекс возвращает `403 Invalid apikey`, ключ отсутствует, неверен либо не
привязан к API Геокодера — это не ошибка введённого покупателем адреса.

```php
// config/services.php
'yandex_delivery' => [
    'enabled' => env('YANDEX_DELIVERY_ENABLED', false),
    'mode' => env('YANDEX_DELIVERY_MODE', 'sandbox'),
    'token' => env('YANDEX_DELIVERY_TOKEN'),
    'platform_station_id' => env('YANDEX_DELIVERY_PLATFORM_STATION_ID'),
    'geocoder_key' => env('YANDEX_DELIVERY_GEOCODER_KEY'),
    'base_url' => [
        'sandbox' => 'https://b2b.taxi.tst.yandex.net',
        'production' => 'https://b2b-authproxy.taxi.yandex.net',
    ],
],
```

## 3. Целевой пользовательский сценарий

```text
Checkout
  → выбор «Курьер» или «ПВЗ»
  → адрес / выбор ПВЗ
  → Platform API: расчёт и офферы
  → выбор подходящего оффера
  → сохранение выбора в orders.delivery_data
  → успешная оплата
  → создание и подтверждение заказа в Platform API
  → polling статуса
  → ссылка на отслеживание в ЛК и уведомлениях покупателя
```

Заявка в Яндекс создаётся **только после успешной оплаты**. Повторное получение
одного и того же платёжного события не должно создавать вторую заявку.

## 4. Методы Platform API

Точные JSON-схемы и названия параметров берём из актуального раздела «Список
методов»; не копируем контракты claims API.

| Назначение | Platform API |
|---|---|
| Расчёт/список офферов | `POST /api/b2b/platform/offers/create` |
| Подтверждение выбранного оффера | `POST /api/b2b/platform/offers/confirm` |
| ПВЗ/постаматы/точки самопривоза | `POST /api/b2b/platform/pickup-points/list` |
| Создание/редактирование/отмена/возврат | Методы из разделов «Основные запросы», «Оформить и отправить заказ», «Отредактировать или отменить заказ», «Получить или вернуть заказ» |
| Статус и трекинг | Методы раздела «Отследить доставку» |

Для ПВЗ передаём `payment_method: "already_paid"`, поскольку заказ оплачивается
на сайте. API может вернуть брендированные и партнёрские ПВЗ, постаматы,
координаты, расписание и доступные сервисы (примерка, частичный выкуп и т. п.).

### Выбор ПВЗ

Приоритет реализации:

1. Официальный виджет Яндекс.Доставки, если поддержка/документация даст способ
   инициализации для нашего продукта.
2. Встроенный список и карта на основе `pickup-points/list` — обязательный
   fallback. Фильтруем результаты по городу/координатам, `type`, оплате и при
   необходимости по операторам `market_l4g`/`5post`.

## 5. Модель данных

### `orders.delivery_data`

Черновик выбора на чекауте хранится в уже существующем JSON-поле:

```json
{
  "provider": "yandex",
  "delivery_type": "courier",
  "offer_id": "...",
  "price": 349.0,
  "tariff_code": "...",
  "destination": {"address": "...", "coordinates": [37.6, 55.7]},
  "pvz": {"id": "...", "address": "...", "coordinates": [37.6, 55.7]},
  "tracking_url": null
}
```

### `yandex_orders`

Отдельная таблица хранит связь внутреннего заказа с заказом Яндекса.

```text
id, order_id, shipment_id nullable,
yandex_order_id, status, internal_status,
delivery_type, tariff_code nullable, price nullable, currency,
offer_id nullable, pvz_id nullable,
tracking_url nullable,
external_request_id uuid unique,
last_synced_at nullable, timestamps, softDeletes
```

`external_request_id` используется как идемпотентный ключ на нашей стороне:
один заказ магазина → одна внешняя заявка. `tracking_url` дублируется в
`orders.delivery_data` и доступен покупателю.

### Логи и история

- `yandex_api_logs`: запрос, ответ, HTTP-код, длительность, признак ошибки;
  токены и персональные данные маскируются.
- `yandex_status_events`: каждый результат polling с сырым и нормализованным
  статусом.
- Очистка логов — по расписанию, срок хранения не менее 30 дней.

## 6. Backend

```text
App\Services\Delivery\Yandex\PlatformClient
  — Bearer auth, base URL, таймауты, маскирование логов.

App\Services\Delivery\YandexDeliveryService
  — calculateOffers, confirmOffer, createOrder, getOrderInfo,
    cancelOrder, createReturn, getTracking.

PackagingResolver
  — вес и габариты заказа.

YandexStatusMapper
  — сырой статус Platform API → OrderStatus / ShipmentStatus.
```

`YandexDeliveryService` реализует существующий контракт `DeliveryService`, но
внутри использует только Platform API. Старый сервис не переписывается поверх
claims-методов.

### Габариты

Источник по приоритету: габариты товара → дефолт категории → глобальный дефолт.
Для нижнего белья стартовый дефолт: `0.5 кг`, `0.2 × 0.1 × 0.1 м`; для корзины
с более чем пятью единицами габариты пересчитываются.

## 7. Оплата, создание и polling

### Мост оплаты → доставка

Используется проверенный `Pay` webhook платёжного провайдера CloudPayments,
чтобы успешная оплата:

1. обновляла `Order.payment_status`;
2. фиксировала защиту от повторного события;
3. диспатчила `CreateYandexOrderJob` только для заказа с Яндекс.Доставкой;
4. создавала `Shipment` и `YandexOrder`.

Архитектура и тексты клиентских уведомлений описаны в
`docs/tasks/yandex-delivery-customer-notifications.md`.

Очередь должна быть асинхронной (`database` или `redis`), не `sync`.

### Статусы без webhook

Яндекс подтвердил отсутствие вебхуков. Вместо endpoint для входящих событий
нужна команда `yandex:poll-statuses`:

- выбирает незавершённые `yandex_orders`;
- запрашивает актуальный статус и tracking URL;
- пишет событие только при изменении;
- обновляет `YandexOrder`, `Shipment`, `Order` и `orders.delivery_data`;
- уведомляет покупателя о значимых переходах.

Частота: раз в 10–15 минут для активных доставок с защитой
`withoutOverlapping()` и лимитами API.

## 8. Витрина и админка

### Витрина (`again_front`)

- Чекаут: переключатель «Курьер» / «ПВЗ».
- Геокодирование адреса до запроса расчёта.
- Выбор оффера, ПВЗ и отображение цены/срока.
- Сохранение выбранной доставки в заказе.
- ЛК заказа: нормализованный статус и `tracking_url`.

### Админка (`again_dashboard`)

- Карточка заказа: внешний номер Яндекса, ПВЗ/адрес, тариф, цена, статус,
  tracking URL и последняя ошибка.
- Действия: создать доставку вручную, обновить статус, отменить/оформить возврат
  с подтверждением.
- Таблица/фильтры по статусу и «зависшим» доставкам.

## 9. Порядок реализации

1. Получить production token и `platform_station_id`; проверить sandbox.
2. Добавить конфигурацию, миграции `yandex_orders`/логи/статусы и тесты клиента
   Platform API.
3. Реализовать `PlatformClient`, ПВЗ и расчёт офферов; подключить чекаут.
4. Реализовать мост оплаты → создание внешнего заказа и идемпотентность.
5. Реализовать polling, tracking URL, статусы и уведомления.
6. Добавить ручное управление в админке, отмены и возвраты.
7. Прогнать sandbox end-to-end: курьер и ПВЗ, оплата, создание, polling,
   отмена/возврат, повтор платёжного webhook.
8. Включить production после выдачи боевой станции и токена.

## 10. Критерии готовности

- [ ] В sandbox рассчитываются курьер и ПВЗ.
- [ ] Выбранный ПВЗ/адрес и оффер сохраняются в заказе.
- [ ] После оплаты создаётся ровно одна внешняя доставка.
- [ ] Polling обновляет статус и tracking URL без дублей.
- [ ] Покупатель видит ссылку на отслеживание.
- [ ] Менеджер может посмотреть и отменить доставку.
- [ ] Ошибки API логируются без токенов.
- [ ] Production проверен на реальном `platform_station_id`.
