# Manual checkout tests — guest & client

Автоматизированный прогон ручного playbook'а из
`laravel/docs/tasks/guest-checkout.md` (раздел «Сценарии backend»).

Скрипты гоняются **против живого Laravel** (например, dev-сервера
`https://sub.againdev.ru`), а не в изолированной тестовой БД. Поэтому
существуют:
* подготовительный скрипт, который вытаскивает реальные тестовые
  данные (товар, промокоды, токен клиента) из БД и кладёт в
  `.env.local`;
* раннер, который последовательно прогоняет сценарии S1–S9 (гость) и
  C1–C7 (клиент) и печатает PASS/FAIL;
* SQL-проверки артефактов в БД;
* cleanup, удаляющий созданные тестовые заказы и токены.

UI-сценарии (U1–U6 и A1–A7) **не автоматизированы** — для них есть
человеко-читаемый чек-лист в [`ui-checklist.md`](ui-checklist.md).

---

## Требования

* `bash` 4+
* `curl`
* `jq` (`apt install jq`)
* `php artisan` запускается локально (нужен для `01-prepare.sh` и
  `99-cleanup.sh`)
* `.env` Laravel настроен, миграции `add_email_to_orders_table` и
  `make_client_id_nullable_in_promo_code_usages` применены

## Запрещено на production

`01-prepare.sh` и `99-cleanup.sh` отказываются работать при
`APP_ENV=production` (там они потенциально изменяют данные:
`syncWithoutDetaching` промокода к клиенту и удаление заказов).

`02-run.sh` сам по себе не модифицирует чужие данные, но создаёт новые
заказы (помечаются email-ом `*@example.com`, что и подхватывает
cleanup). На проде запускать тоже не следует.

---

## Порядок запуска

Из корня репо Laravel:

```bash
cd /var/www/html/laravel

# 1. Сбор тестовых данных в tests/manual/.env.local
./tests/manual/01-prepare.sh

# 2. Прогон S1–S9 + C1–C7
./tests/manual/02-run.sh

# 3. (опционально) SQL-сверка артефактов в БД
./tests/manual/03-db-checks.sh

# 4. Очистка тестовых заказов после прогона
./tests/manual/99-cleanup.sh --force
```

### Что выводит раннер

Одна строка на проверку:

```
[PASS] S1 guest min payload: client_id=null, view_token=8f3a...
[PASS] S2 guest with email: order.email=guest+xxx@example.com
[FAIL] S4 personal promo for guest: expected code=PROMO_REQUIRES_AUTH, got code=PROMO_NOT_FOUND
       response: {"success":false,"message":"...","code":"PROMO_NOT_FOUND"}
```

В конце — сводка `X passed, Y failed`.

Exit-код 0 если все PASS, 1 если есть FAIL.

---

## Что внутри `.env.local`

После `01-prepare.sh`:

```bash
BASE_URL="https://sub.againdev.ru"
PRODUCT_ID=42
PRODUCT_PRICE=1160.00
PRODUCT_STOCK=10
PUBLIC_PROMO_CODE="Скидка11"
PERSONAL_PROMO_CODE="BIRTHDAY"
FOREIGN_PERSONAL_CODE="FOREIGN_PROMO"   # опционально
CLIENT_ID=5
CLIENT_EMAIL="test@client.local"
CLIENT_TOKEN="3|aBcD..."
```

Если каких-то полей не нашлось, prepare сообщит чего не хватает и
подскажет, как засидить.

---

## Сопоставление с чек-листом

| Скрипт | Покрытые пункты чек-листа |
|--------|----------------------------|
| `02-run.sh` | S1, S2, S3, S4, S5, S6, S7, S8, S9, C1, C2, C3, C4, C5, C6, C7 |
| `03-db-checks.sh` | артефакты S3 (`promo_code_usages.client_id IS NULL`), артефакты S1–S2 |
| `g1-giftcard-as-product.sh` | G1, G2, G3 — гость покупает «Подарочный сертификат» как товар; сверка `gift_cards` + `jobs` (SendGiftCardJob) |
| `ui-checklist.md` | U1–U6, A1–A7 |

### Опционально: гость + подарочный сертификат как товар

```bash
./tests/manual/g1-giftcard-as-product.sh
```

Это **отдельный flow**, которого нет в основном playbook
`guest-checkout.md`: гость кладёт в корзину «Подарочный сертификат»,
выбирает номинал (variant), заполняет `gift_card_data`. После создания
заказа `PublicCheckoutController` дополнительно вызывает
`GiftCardService::createFromOrder` + `scheduleDelivery`.

Покрывает три сценария:

| # | Что проверяет |
|---|---------------|
| G1 | электронный сертификат для **себя**, доставка immediate; `gift_cards.sender_email = recipient_email = order.email` |
| G2 | электронный сертификат для **другого** (email-получатель); `gift_cards.recipient_*` из `gift_card_data`, `sender_email` из заказа |
| G3 | электронный сертификат **scheduled** на завтра 12:00 MSK; `gift_cards.scheduled_at` в будущем |

Дополнительно проверяет, что для каждой карты в `jobs` появился
`SendGiftCardJob`. Для G1/G2 без `delay`, для G3 — с `available_at >
now()`.

`01-prepare.sh` сам подбирает `GIFT_PRODUCT_ID` (товар «Подарочный
сертификат» с `has_variants=1`) и 2 активных варианта. Если их нет —
G1/G2/G3 SKIP.

---

## Caveats

* **`S9` забивает rate-limit на минуту.** Поэтому он стоит последним
  в гостевой части. Если запускать `02-run.sh` подряд два раза,
  первые ~30 запросов второго запуска получат 429. Между прогонами
  жди минуту или меняй IP.
* **`PRICE_MISMATCH`.** Цена товара берётся в `01-prepare.sh` на
  момент сбора. Если между prepare и run цена изменилась (применилась
  акция/промо) — заказы получат 422 PRICE_MISMATCH. Это не баг
  скрипта, а защита бэка.
* **`C5` зависит от состояния `promo_code_usages`.** Раннер
  предварительно чистит свои использования за текущий прогон (по
  `order_id` созданных в C1–C6 заказов), но если в БД уже есть
  записи с этим клиентом и кодом — C5 может «случайно» сработать на
  первой же попытке.
