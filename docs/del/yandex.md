# Яндекс.Доставка API Integration

## Обзор

**Яндекс.Доставка** — два разных API:

| API | Описание | Endpoint |
|-----|----------|----------|
| **NDD (Express Delivery)** | Новая платформа Яндекса, курьерская доставка | `b2b.taxi.yandex.net` |
| **Boxberry** | Старый API ПВЗ Boxberry | `api.boxberry.ru/json.php` |

### Сравнение

| Аспект | NDD (Express) | Boxberry |
|--------|---------------|----------|
| Авторизация | OAuth (Bearer token) | Token в параметрах |
| Тип доставки | Курьерская (экспресс) | ПВЗ + КД |
| Страны | Россия, СНГ | РФ, Казахстан, Беларусь и др. |
| Подход | Claim-based (заявка → подтверждение) | Прямое создание заказа |

---

# NDD Express Delivery API

## Авторизация

### Получение токена

1. Войти в ЛК: `https://dostavka.yandex.ru`
2. Раздел **Интеграция** → **Get token**

### Использование

```bash
Authorization: Bearer <OAuth_token>
```

Токен бессрочный. При смене пароля — недействителен.

## Базовый URL

```
https://b2b.taxi.yandex.net/b2b/cargo/integration/v2
```

---

## Основные методы

### Базовые

| Метод | Endpoint | Описание |
|-------|----------|----------|
| Расчёт | `POST /offers/calculate` | Получить варианты доставки |
| Создание | `POST /claims/create` | Создать заявку |
| Инфо | `POST /claims/info` | Информация о заявке |
| Подтверждение | `POST /claims/accept` | Подтвердить заявку |
| Отмена | `POST /claims/cancel` | Отменить заявку |

### Дополнительные

| Метод | Endpoint | Описание |
|-------|----------|----------|
| Оценка | `POST /check-price` | Предварительный расчёт |
| Трекинг | `GET /claims/performer-position` | Координаты курьера |
| Телефон | `POST /driver-voiceforwarding` | Телефон курьера |
| Журнал | `POST /claims/journal` | История изменений |
| Поиск | `POST /claims/search` | Поиск заявок |

---

## Порядок работы (Россия)

```
1. offers/calculate → получить варианты
2. claims/create    → создать заявку
3. claims/info      → проверить статус
4. claims/accept    → подтвердить (в течение 10 мин!)
```

---

## Расчёт доставки (offers/calculate)

### Запрос

```bash
POST /offers/calculate
Authorization: Bearer <token>
Content-Type: application/json
```

```json
{
  "items": [
    {
      "size": { "length": 0.3, "width": 0.2, "height": 0.1 },
      "weight": 1.5,
      "quantity": 1,
      "pickup_point": 1,
      "dropoff_point": 2
    }
  ],
  "route_points": [
    {
      "id": 1,
      "coordinates": [37.617635, 55.755819],
      "fullname": "Москва, ул. Тверская, д. 1"
    },
    {
      "id": 2,
      "coordinates": [30.335099, 59.934280],
      "fullname": "Санкт-Петербург, Невский пр., д. 1"
    }
  ],
  "requirements": {
    "taxi_classes": ["express"],
    "cargo_type": "lcv_m",
    "due": "2024-01-15T12:00:00+03:00"
  }
}
```

### Параметры items

| Параметр | Тип | Описание |
|----------|-----|----------|
| `size` | object | Габариты в метрах |
| `weight` | float | Вес в кг |
| `quantity` | int | Количество |
| `pickup_point` | int | ID точки забора |
| `dropoff_point` | int | ID точки доставки |

### Параметры route_points

| Параметр | Тип | Описание |
|----------|-----|----------|
| `id` | int | Уникальный ID точки |
| `coordinates` | [lon, lat] | Координаты |
| `fullname` | string | Полный адрес |

### Параметры requirements

| Параметр | Значения | Описание |
|----------|----------|----------|
| `taxi_classes` | `express`, `cargo` | Класс доставки |
| `cargo_type` | `lcv_m`, `lcv_l` | Тип авто |
| `cargo_loaders` | 0-2 | Грузчики |
| `pro_courier` | bool | Профи-курьер |
| `due` | ISO8601 | Срок доставки |

### Ответ

```json
{
  "offers": [
    {
      "offer_id": "xxx",
      "price": "500.00",
      "currency": "RUB",
      "pickup_interval": { "from": "2024-01-15T10:00:00Z", "to": "2024-01-15T12:00:00Z" },
      "delivery_interval": { "from": "2024-01-15T14:00:00Z", "to": "2024-01-15T16:00:00Z" }
    }
  ]
}
```

---

## Создание заявки (claims/create)

### Запрос

```bash
POST /claims/create?request_id=<uuid>
Authorization: Bearer <token>
Content-Type: application/json
```

```json
{
  "items": [
    {
      "size": { "length": 0.3, "width": 0.2, "height": 0.1 },
      "weight": 1.5,
      "quantity": 1,
      "pickup_point": 1,
      "dropoff_point": 2,
      "title": "Товар",
      "cost_value": "1500.00",
      "cost_currency": "RUB"
    }
  ],
  "route_points": [
    {
      "id": 1,
      "type": "source",
      "coordinates": [37.617635, 55.755819],
      "fullname": "Москва, ул. Тверская, д. 1",
      "contact": { "name": "Отправитель", "phone": "+79001234567" },
      "skip_confirmation": true
    },
    {
      "id": 2,
      "type": "destination",
      "coordinates": [30.335099, 59.934280],
      "fullname": "Санкт-Петербург, Невский пр., д. 1",
      "contact": { "name": "Получатель", "phone": "+79009876543" },
      "skip_confirmation": false
    }
  ],
  "client_requirements": {
    "taxi_class": "express"
  },
  "comment": "Комментарий к заказу"
}
```

### Параметры route_points

| Параметр | Значения | Описание |
|----------|----------|----------|
| `type` | `source`, `destination` | Тип точки |
| `skip_confirmation` | bool | Пропустить код подтверждения |

### Параметры contact

| Параметр | Тип | Описание |
|----------|-----|----------|
| `name` | string | Имя |
| `phone` | string | Телефон |
| `email` | string | Email |

### Ответ

```json
{
  "id": "claim-xxx",
  "status": "new",
  "pricing": { "price": "500.00", "currency": "RUB" }
}
```

---

## Модель статусов

### Основные статусы

| Статус | Описание | Действие |
|--------|----------|----------|
| `new` | Заявка создана | — |
| `estimating` | Расчёт стоимости | — |
| `ready_for_approval` | Готова к подтверждению | `claims/accept` (10 мин!) |
| `accepted` | Подтверждена | — |
| `performer_lookup` | Поиск курьера | — |
| `performer_found` | Курьер найден | — |
| `pickup_arrived` | Курьер на точке А | — |
| `pickuped` | Груз получен | — |
| `delivery_arrived` | Курьер у получателя | — |
| `delivered` | Доставлено | — |
| `delivered_finish` | Завершено | — |

### Статусы возврата

| Статус | Описание |
|--------|----------|
| `returning` | Возврат |
| `return_arrived` | Курьер на точке возврата |
| `returned` | Возвращено |
| `returned_finish` | Завершено с возвратом |

### Статусы отмены

| Статус | Описание |
|--------|----------|
| `cancelled` | Отменено бесплатно |
| `cancelled_with_payment` | Отменено с оплатой |
| `cancelled_by_taxi` | Отменено курьером |

### Ошибки

| Статус | Описание |
|--------|----------|
| `failed` | Ошибка |
| `estimating_failed` | Ошибка расчёта |
| `performer_not_found` | Курьер не найден |

---

## Подтверждение заявки (claims/accept)

```bash
POST /claims/accept
Authorization: Bearer <token>
Content-Type: application/json

{
  "claim_id": "claim-xxx",
  "version": 1
}
```

**Важно:** Подтвердить в течение **10 минут** после `ready_for_approval`.

---

## Информация о заявке (claims/info)

```bash
POST /claims/info
Authorization: Bearer <token>
Content-Type: application/json

{
  "claim_id": "claim-xxx"
}
```

### Ответ

```json
{
  "id": "claim-xxx",
  "status": "performer_found",
  "pricing": { "price": "500.00" },
  "performer_info": {
    "courier_name": "Иван",
    "car_model": "Volkswagen Polo",
    "car_number": "А123БВ777"
  },
  "route_points": [...]
}
```

---

## Отмена заявки (claims/cancel)

```bash
POST /claims/cancel
Authorization: Bearer <token>
Content-Type: application/json

{
  "claim_id": "claim-xxx",
  "cancel_state": "free",
  "version": 1
}
```

**Бесплатная отмена:** до статуса `pickup_arrived`.
**После pickup_arrived:** платная отмена.

---

## Трекинг курьера

### Координаты

```bash
GET /claims/performer-position?claim_id=claim-xxx
Authorization: Bearer <token>
```

### Телефон курьера

```bash
POST /driver-voiceforwarding
Authorization: Bearer <token>

{
  "claim_id": "claim-xxx"
}
```

---

# Boxberry API (ПВЗ)

## Авторизация

### Получение токена

1. Войти в [Личный Кабинет](https://account.boxberry.ru)
2. Раздел **"Интеграция"** → вкладка **"Методы API"**
3. Токен указан в этом разделе

### Использование токена

Токен передается в параметре `token` при каждом запросе:

```bash
GET https://api.boxberry.ru/json.php?method=ListCities&token=YOUR_TOKEN
```

## Основные методы API

### Справочники

| Метод | Описание | Параметры |
|-------|----------|-----------|
| `ListCities` | Список городов с ПВЗ | `CountryCode` (опционально) |
| `ListCitiesFull` | Полный список городов | `CountryCode` (опционально) |
| `ListPoints` | Список ПВЗ | — |
| `ListPointsShort` | Коды отделений с датой изменения | — |
| `PointsDescription` | Информация о ПВЗ | `code` — код пункта |
| `PointsForParcels` | Пункты приёма посылок | — |
| `ListZips` | Почтовые индексы для КД | — |
| `ZipCheck` | Проверка индекса для КД | `zip` |
| `CourierListCities` | Города курьерской доставки | — |

### Коды стран

| Код | Страна |
|-----|--------|
| 643 | Россия |
| 398 | Казахстан |
| 112 | Беларусь |
| 417 | Киргизия |
| 051 | Армения |
| 762 | Таджикистан |
| 860 | Узбекистан |

## Расчет стоимости доставки

### Метод: DeliveryCosts

**Тип: Склад-Склад (ПВЗ)**

```bash
GET https://api.boxberry.ru/json.php?token=TOKEN&method=DeliveryCosts&weight=500&targetstart=010&target=19733&ordersum=2000&deliverysum=100&paysum=2000&height=15&width=18&depth=10
```

**Тип: Склад-КД (курьерская)**

```bash
GET https://api.boxberry.ru/json.php?token=TOKEN&method=DeliveryCosts&weight=500&targetstart=010&zip=624000&ordersum=2000&deliverysum=100&paysum=2000&height=15&width=18&depth=10
```

### Параметры

| Параметр | Обяз. | Тип | Описание |
|----------|-------|-----|----------|
| `token` | ✓ | string | API токен |
| `method` | ✓ | string | `DeliveryCosts` |
| `weight` | ✓ | int | Вес в граммах |
| `targetstart` | — | string | Код пункта приема |
| `target` | — | string | Код ПВЗ (игнорируется при zip) |
| `ordersum` | — | float | Объявленная стоимость |
| `deliverysum` | — | float | Стоимость доставки для клиента |
| `paysum` | — | float | Сумма к оплате с получателя |
| `height` | — | string | Высота (см) |
| `width` | — | string | Ширина (см) |
| `depth` | — | string | Глубина (см) |
| `zip` | — | string | Индекс для КД |

### Ответ

```json
{
  "price": 224,
  "price_base": 147,
  "price_service": 77,
  "delivery_period": 1
}
```

## Создание заказа

### Метод: ParselCreate

**URL:** `https://api.boxberry.ru/json.php`
**Метод:** POST
**Content-Type:** application/json

### Структура запроса

```json
{
  "token": "YOUR_TOKEN",
  "method": "ParselCreate",
  "sdata": {
    "order_id": "ORDER_123",
    "price": "1950.00",
    "payment_sum": "2200.00",
    "delivery_sum": "250.00",
    "vid": "1",
    "issue": "1",
    "shop": {
      "name": "19733",
      "name1": "010"
    },
    "customer": {
      "fio": "Иванов Иван Иванович",
      "phone": "9001122333",
      "email": "test@test.ru"
    },
    "items": [
      {
        "id": "SKU001",
        "name": "Товар",
        "UnitName": "шт.",
        "nds": "20",
        "price": "1750",
        "quantity": "1"
      }
    ],
    "weights": {
      "weight": "1500",
      "x": "20",
      "y": "20",
      "z": "10"
    }
  }
}
```

### Основные параметры sdata

| Параметр | Обяз. | Описание |
|----------|-------|----------|
| `order_id` | ✓ | Номер заказа в ИМ |
| `price` | ✓ | Объявленная стоимость |
| `payment_sum` | ✓ | Сумма к оплате (0 для предоплаты) |
| `delivery_sum` | — | Стоимость доставки для клиента |
| `vid` | ✓ | Вид: 1=ПВЗ, 2=Курьер |
| `issue` | — | Тип выдачи: 0=без вскрытия, 1=со вскрытием, 2=частичная |

### Параметры shop

| Параметр | Обяз. | Описание |
|----------|-------|----------|
| `name` | ✓ (ПВЗ) | Код пункта выдачи |
| `name1` | ✓ | Код пункта приёма |

### Параметры customer

| Параметр | Обяз. | Описание |
|----------|-------|----------|
| `fio` | ✓ | ФИО получателя |
| `phone` | ✓ | Телефон (10 цифр) |
| `email` | — | Email для уведомлений |

### Параметры kurdost (курьерская доставка)

```json
"kurdost": {
  "index": "",
  "citi": "Нижний Новгород",
  "addressp": "ул. Дружбы, д. 5",
  "delivery_date": "",
  "timesfrom1": "10:00",
  "timesto1": "18:00",
  "comentk": ""
}
```

### Параметры items

| Параметр | Обяз. | Описание |
|----------|-------|----------|
| `id` | — | Артикул |
| `name` | ✓ | Наименование |
| `price` | ✓ | Цена за единицу |
| `quantity` | ✓ | Количество |
| `nds` | — | НДС: -1=без НДС, 0/5/7/10/18/20 |
| `marking_crpt` | — | Код маркировки ЦРПТ |

### Параметры weights

| Параметр | Обяз. | Описание |
|----------|-------|----------|
| `weight` | ✓ | Вес первого места (граммы), мин 5г, макс 31000г |
| `weight2`...`weight24` | — | Вес последующих мест |
| `barcode` | — | ШК первого места |
| `x`, `y`, `z` | — | Габариты (см), макс сторона 120см |

### Ответ

```json
{
  "track": "AAP127020243",
  "label": "https://api.boxberry.ru/parcel-files/barcodes?parcel_id=127020243&token=XXX"
}
```

## Отслеживание статусов

### Метод: ListStatuses

**GET:**

```bash
GET https://api.boxberry.ru/json.php?method=ListStatuses&token=TOKEN&ImId=AAP298792807
```

**POST:**

```json
{
  "token": "YOUR_TOKEN",
  "method": "ListStatuses",
  "ImId": "AAP298792807"
}
```

### Ответ

```json
[
  { "Date": "2024-02-07 13:38:13", "Name": "Загружен реестр ИМ" },
  { "Date": "2024-02-07 13:40:54", "Name": "Принято к доставке" },
  { "Date": "2024-02-07 13:42:55", "Name": "Передано на сортировку" },
  { "Date": "2024-02-07 13:44:03", "Name": "Отправлено в город назначения" },
  { "Date": "2024-02-07 13:52:35", "Name": "Поступило в пункт выдачи" },
  { "Date": "2024-02-07 13:54:31", "Name": "Выдано" }
]
```

### Связанные методы

- `ListStatusesFull` — расширенный список статусов
- `GetLastStatusData` — массив статусов для нескольких посылок

## Изменение и отмена заказа

### Методы

| Метод | Описание |
|-------|----------|
| `ParselUpdate` | Обновление данных заказа |
| `ParselCancel` | Отмена/отзыв заказа |

## Курьерский забор

### Метод: CreateIntake

Создание заявки на забор посылок курьером со склада ИМ.

## Ограничения

- Протоколы шифрования: TLS 1.2, TLS 1.3
- Кодировка: UTF-8
- Лимиты запросов: см. документацию

## Виджет выбора ПВЗ

Для интеграции виджета выбора пункта выдачи на фронтенде требуется ключ интеграции. Получение: ЛК → Интеграция → Виджет.

## Полезные ссылки

- [Публичная документация](https://yandex.ru/support/delivery-boxberry/ru/)
- [Postman документация](https://documenter.getpostman.com/view/12793353/U16dRoAP)
