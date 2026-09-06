# Деплой на сервер — runbook

Инструкция по выкатке изменений на сервер. По команде «выкати/задеплой» —
выполнять шаги ниже по порядку.

**Сервер:** `ssh root@186.246.14.59` (хост `7826976-ck036553.twc1.net`)
**Публичный адрес:** https://againdev3.ru
**Окружение laravel:** `APP_ENV=local`, БД `laravel` (dev/демо-сервер).

> Доступ по SSH-ключу (BatchMode работает). Подключение:
> `ssh -o BatchMode=yes -o ConnectTimeout=20 root@186.246.14.59 '<cmd>'`

---

## Соответствие проектов и папок

| Репозиторий (GitHub) | Локально (dev) | На сервере |
|---|---|---|
| `aziztwelve/again_laravel` | `lara_admin` | `/var/www/html/laravel` |
| `aziztwelve/again_front`   | `again_front` | `/var/www/html/nuxt-shop` |
| `aziztwelve/again_admin`   | `again_dashboard` | `/var/www/html/vue-admin` |

Все три на ветке `main`, upstream `origin/main`.

## Рабочее правило: код локально, сборка и проверка на сервере

Код и документацию пишем и редактируем **локально**. Локальные сборки не
запускаем: запрещены `npm run build`, `nuxi build`, `yarn build`,
`composer install` и аналогичные команды, устанавливающие зависимости или
создающие production-артефакты.

После правок обязательно:

1. Закоммитить и запушить изменения в соответствующий `main`.
2. Выполнить `pull --ff-only` на сервере.
3. Установить зависимости, пересобрать фронтенд и перезапустить необходимые
   процессы **на сервере**.
4. Выполнить серверные тесты и функциональные smoke-проверки **на сервере**.

Нельзя считать задачу проверенной только по локальному `diff` или успешному
локальному запуску. Это правило действует и для небольших изменений
фронтенда, документации и конфигурации.

Локально допустимы только быстрые статические проверки, не создающие артефакты
сборки: например `git diff --check`, линтер отдельного файла или `php -l`.

Для Laravel после деплоя запускать затронутые тесты точечно, например:

```bash
ssh -o BatchMode=yes -o ConnectTimeout=120 root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/laravel
php artisan test --filter FreeShippingTest
'
```

Полный `php vendor/bin/phpunit` без необходимости не запускать: тесты с
`RefreshDatabase` используют общую серверную БД `testing` и могут оставить её
схему неполной. Если нужен полный прогон, сначала подготовить отдельную
тестовую БД и проверить последствия для окружения.

## Процессы (pm2) и веб

- `pm2`: `laravel-queue`, `laravel-reverb`, `laravel-scheduler`, `nuxt-shop`,
  `whatsapp-service`.
- **nuxt-shop** — SSR под pm2 (после сборки делать `pm2 restart nuxt-shop`).
- **vue-admin** — статика, раздаётся nginx из `dist/` (после сборки рестарт НЕ нужен).
- **laravel** — php-fpm + nginx; очереди/reverb/scheduler под pm2 (после деплоя
  перезапускать, чтобы воркеры подхватили новый код).

---

## Порядок деплоя

### 0. Локальный push всех трёх проектов
Перед сервером запушить `main` во всех трёх локальных репозиториях. Делать это
даже если правки были только в одном проекте: команда деплоя всегда работает с
актуальными `origin/main` всех частей.
```bash
git -C lara_admin push origin main
git -C again_front push origin main
git -C again_dashboard push origin main
```

### 1. Предпроверка сервера (read-only)
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
for p in /var/www/html/laravel /var/www/html/nuxt-shop /var/www/html/vue-admin; do
  echo "== $p =="; git -C "$p" rev-parse --abbrev-ref HEAD;
  echo "dirty tracked: $(git -C "$p" status --porcelain --untracked-files=no | wc -l)";
  echo "untracked non-env-backup: $(git -C "$p" status --porcelain --untracked-files=all | grep -Ev "^[?][?] \\.env\\.bak" | grep -c "^[?][?] " || true)";
done'
```
Деревья должны быть чистыми (`dirty tracked: 0`, `untracked non-env-backup: 0`).
Untracked `.env.bak*` на сервере допустимы. Если есть другие незакоммиченные
правки на сервере — НЕ продолжать, сначала разобраться.

### 2. Pull всех трёх проектов (fast-forward only)
Pull выполнять всегда для всех трёх серверных папок, даже если в конкретном
проекте изменений не было.
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
set -euo pipefail
for p in /var/www/html/laravel /var/www/html/nuxt-shop /var/www/html/vue-admin; do
  echo "== $p =="; git -C "$p" pull --ff-only origin main 2>&1 | tail -3;
  echo "head: $(git -C "$p" rev-parse --short HEAD)";
done'
```

### 3. Backend (laravel)
Обычно backend-команды нужны, если менялся Laravel-код, зависимости или миграции.
Для чисто фронтового/doc-деплоя можно ограничиться pull и smoke-проверкой backend.
```bash
ssh -o BatchMode=yes -o ConnectTimeout=120 root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/laravel
composer install --no-interaction --prefer-dist --no-progress 2>&1 | tail -5
php artisan migrate --force 2>&1 | tail -40
php artisan optimize:clear
pm2 restart laravel-queue laravel-scheduler laravel-reverb
'
```

### 4. Сиды (ОСТОРОЖНО)
- **НЕ запускать** `php artisan db:seed` целиком (`DatabaseSeeder`) — он
  пересоздаёт Users/Products/Clients/Orders/PromoCodes и т.д. и побьёт/задублирует
  данные сервера.
- Запускать только нужные идемпотентные сидеры точечно, например каналы UTM:
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/laravel && php artisan db:seed --class=MarketingChannelSeeder --force'
```
  (`MarketingChannelSeeder` использует `updateOrCreate` по `code` — безопасен.)

### 5. Витрина (nuxt-shop) — пересобирать всегда
`nuxt-shop` использует npm и `package-lock.json`. Для деплоя использовать
`npm ci`, а не `npm install`: так зависимости ставятся строго по lockfile и
`package-lock.json` не меняется на сервере.
```bash
ssh -o BatchMode=yes -o ConnectTimeout=900 root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/nuxt-shop
npm ci --no-audit --no-fund 2>&1 | tail -8
NODE_OPTIONS=--max-old-space-size=2048 npm run build 2>&1 | tail -6
pm2 restart nuxt-shop --update-env
'
```

### 6. Дашборд (vue-admin) — пересобирать всегда
`vue-admin` использует Yarn 1 (`packageManager` в `package.json` и `yarn.lock`).
Не использовать `npm install`: npm строго валидирует peer-зависимости и падает
на существующем конфликте `vue-chart-3` / `chart.js`, а также может менять
`package-lock.json` на сервере. Источник правды для зависимостей — `yarn.lock`.
```bash
ssh -o BatchMode=yes -o ConnectTimeout=900 root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/vue-admin
corepack enable
corepack yarn install --frozen-lockfile --non-interactive 2>&1 | tail -8
NODE_OPTIONS=--max-old-space-size=2048 corepack yarn build 2>&1 | tail -6
'
```

### 7. Проверка (smoke)
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
set -euo pipefail
pm2 list | grep -E "laravel|nuxt|whatsapp"
cd /var/www/html/laravel && echo "pending: $(php artisan migrate:status | grep -ci pending || true)"
curl -s -o /dev/null -w "laravel /up -> %{http_code}\n" https://againdev3.ru/up -k
'
```
Ожидаем: все pm2 `online`, `pending: 0`, `/up -> 200`.

---

## Правила безопасности

- `git pull --ff-only` (не делать merge/rebase на сервере вслепую).
- Никогда не запускать `migrate:fresh` / полный `db:seed` на сервере.
- Перед pull убедиться, что рабочее дерево на сервере чистое.
- `.env`/`.env.bak` на сервере не трогать и не коммитить.
- Билды фронтов запускать с `--max-old-space-size=2048`, чтобы не словить OOM
  (на сервере ~8 ГБ RAM).

---

## Единый домен againdev3.ru (витрина + дашборд + API на одном origin)

Все три проекта обслуживаются на **одном домене** `againdev3.ru`. Предыдущие домены
витрины выведены из эксплуатации. Один origin
автоматически делает все куки first-party — отдельные обходы (как раньше
same-origin `/api`) больше не нужны.

Для витрины на `againdev3.ru` включена basic auth в Nuxt middleware
`server/middleware/basic-auth.ts`: логин `dev`, пароль `12345678`. Защита
срабатывает только на запросах, которые доходят до `nuxt-shop` через `location /`;
`/api`, `/go` и `/admin/` обслуживаются отдельными nginx location и не закрываются
этой авторизацией.

**Маршрутизация nginx на `againdev3.ru` (server {443}), порядок важен —
specific ДО `location /`:**
```nginx
# API laravel (php-fpm)
location /api {
    root /var/www/html/laravel/public;
    try_files $uri /index.php?$query_string;
}
# Yandex Pay webhook. Кабинет вызывает именно /v1/webhook, а не /api.
location = /v1/webhook {
    root /var/www/html/laravel/public;
    try_files $uri /index.php?$query_string;
}
# UTM редирект-трекер (ставит host-only cookie utm_link_id)
location /go {
    root /var/www/html/laravel/public;
    try_files $uri /index.php?$query_string;
}
# Исполнитель php для laravel-локейшенов выше
location ~ \.php$ {
    root /var/www/html/laravel/public;
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_read_timeout 1800;
    fastcgi_send_timeout 1800;
}
# Дашборд (статика vue-admin)
location /admin/ {
    alias /var/www/html/vue-admin/dist/;
    try_files $uri $uri/ /admin/index.html;
}
# Витрина (nuxt-shop SSR на 127.0.0.1:3000) — всё остальное
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```
Применять: `cp` бэкап → правка → `nginx -t` → `nginx -s reload`
(при ошибке `nginx -t` откатить из бэкапа).

**Куки на едином домене (host-only, first-party):**
- `guest_token` (гостевая корзина) — ставится бэком, читается тем же origin.
- `utm_link_id` (атрибуция UTM) — ставит `GET /go/{slug}`, читает api-чекаут.

Оба должны выходить **без атрибута `Domain`** (host-only) и доходить до заказа,
т.к. `/go`, `/api` и витрина — один origin.

**Обязательные env (laravel `.env` на сервере):**
```
APP_URL=https://againdev3.ru
FRONTEND_URL=https://againdev3.ru      # витрина = тот же домен
# UTM_TRACKING_BASE_URL не задавать (по умолчанию = APP_URL)
UTM_COOKIE_SECURE=true                     # домен на HTTPS
# UTM_COOKIE_DOMAIN не задавать (host-only), UTM_COOKIE_SAMESITE=lax
# CART_COOKIE_* / SESSION_DOMAIN не задавать (host-only)
```
**Витрина nuxt-shop `.env`:** `API_URL=https://againdev3.ru/api` (тот же origin).

Проверка:
```bash
# guest_token — host-only first-party
curl -sI -X POST https://againdev3.ru/api/cart/items/bulk | grep -i 'set-cookie'
# /go ставит host-only utm_link_id и 302 на target_url
curl -sI https://againdev3.ru/go/<slug> | grep -iE 'location|set-cookie'
# config должен совпадать с env выше
cd /var/www/html/laravel && php artisan tinker --execute="echo config('utm.attribution.cookie_secure') ? 'UTM secure: yes' : 'UTM secure: no';"
# атрибуция реально пишется в заказы
cd /var/www/html/laravel && php artisan tinker --execute="echo \App\Models\Order::whereNotNull('utm_link_id')->where('created_at','>=',now()->subDay())->count();"
```
Ожидаем: `Set-Cookie` без `Domain=` и с `Secure`; `/go` → 302 + `utm_link_id`;
`UTM secure: yes`; счётчик заказов с меткой растёт.

> Если деплоят на новый сервер/домен — настроить nginx (`/api`, `/go`, `/admin`,
> `/`) и env (`APP_URL`/`FRONTEND_URL` = один домен), иначе гостевая корзина
> (`guest_token`) и UTM-атрибуция (`utm_link_id`) работать не будут.

---

## Смена домена — что обязательно сделать

Домен нигде не зашит в код: канонический адрес берётся из `APP_URL`/`FRONTEND_URL`
(`App\Support\PublicUrl`), прежние хосты — из `LEGACY_HOSTS`. При переезде:

1. В laravel `.env`: `APP_URL` и `FRONTEND_URL` = новый домен, а прежний хост
   добавить в `LEGACY_HOSTS` (через запятую). `php artisan optimize:clear`.
2. `php artisan integrations:sync-webhooks` — переводит вебхуки Telegram, MAX,
   VK и CDEK на новый адрес (VK попутно синхронизирует `confirmation_token`).
   Сначала можно посмотреть состояние: `integrations:sync-webhooks --check`.
3. `php artisan urls:canonicalize` — переписывает сохранённые в БД ссылки
   (`utm_links.target_url`, `images.url`, `message_attachments.url`) с прежних
   хостов на новый. Есть `--dry-run` и `-v`.
4. В `.env` витрины и дашборда — новый домен, затем пересборка (`npm ci` +
   build + `pm2 restart nuxt-shop`; `corepack yarn install --frozen-lockfile` +
   build).
5. nginx: `server_name`, сертификат, location'ы `/api`, `/go`, `/admin/`, `/`.

> **Забытый вебхук = молча сломанный канал.** Telegram/MAX/VK не сообщают об
> ошибке в интерфейсе: апдейты просто копятся на стороне мессенджера
> (`last_error_message: Connection refused`). Поэтому шаг 2 обязателен.

Бэкапы `.env.bak*` и `*.bak` конфигов nginx держать **вне** `sites-enabled/`:
nginx подключает `sites-enabled/*` целиком и дублирующиеся `server_name` дают
`conflicting server name ... ignored`.

---

## История деплоев

- **2026-09-06 (11)** — «после нажатия ничего нет» на
  https://againdev3.ru/admin/order/71716/cdek. Причина: у legacy-заказа
  71716 `delivery_data` пустой (тарифа/города СДЭК нет вообще —
  выбранная доставка `cdek_courier` пришла из импорта). Контроллер ставил
  `CreateCdekOrderJob` в очередь и отвечал 200 «поставлено в очередь», а
  job падал в воркере с `InvalidArgumentException: Не выбран тариф СДЭК.`
  (failed_jobs 438) — в интерфейсе не менялось ничего. Из 23 986
  cdek-заказов данные доставки есть только у 9, так что случай типовой.
  Исправлено:
  - `CdekDeliveryService::readinessError(Order)` — единая предпроверка
    (настроен отправитель → тариф → телефон получателя → ПВЗ для
    pickup/postamat или город для курьера). `createCdekDelivery`
    вызывает её **до** dispatch и возвращает 422 с текстом причины,
    попутно записывая её в `cdek_orders.last_error`;
    `createExternalOrder()` использует ту же проверку вместо трёх
    разрозненных бросков исключений;
  - `CreateCdekOrderJob` при неполных данных сохраняет причину в
    `last_error` и выходит без падения (повтор всё равно не помог бы), а
    при ошибке API пишет читаемый текст ошибки СДЭК;
  - фронт (`OrderCdekCard.vue`) после неудачного запроса перечитывает
    заказ, поэтому причина остаётся на странице в красной плашке, а не
    только во всплывающем сообщении.
  Заодно починена синхронизация статусов: `PollCdekDeliveryStatusesJob`
  валился на каждом заказе с `Column 'status_id' cannot be null` —
  справочник `shipment_statuses` на сервере был пуст (0 строк), а
  `shipments` требует NOT NULL `status_id`/`shipping_address`/
  `recipient_name`/`recipient_phone`. Теперь `upsertShipment()` заполняет
  получателя, адрес, город и тариф, статус берётся через
  `ShipmentStatus::idFor()` (создаёт отсутствующий код),
  `Shipment::$fillable` дополнен колонками из миграции 2025_05_28
  (`location_code`, `city`, `tariff_code`, `period_min/max`),
  `ShipmentStatusSeeder` стал идемпотентным (`updateOrCreate` по `code`)
  и был запущен точечно на сервере — справочник заполнен (7 статусов).
  Миграций нет. Тесты на сервере: новый `CdekOrderReadinessTest` 7/7,
  новый `CdekShipmentSyncTest` 1/1 (13 assertions), регресс
  `CdekClientTest` 10/10, `CdekStatusEventTest` 2/2,
  `CdekOrderAfterPaymentTest` 2/2, `CdekWarehousesTest` 7/7,
  `YandexTrackingInfoTest` 1/1. Проверено headless-браузером на живом API
  с временным sanctum-токеном (удалён): 71716 — клик по кнопке даёт 422,
  текст причины и во всплывающем сообщении, и на странице; 67886 —
  «Обновить историю статусов» → 200, `creation_state=SUCCESSFUL`,
  `shipment_id=4` (статус `new`, город «Санкт-Петербург», тариф 138,
  `orders.tracking_number=10317088749`), история «05.09.2026 Создан /
  Офис СДЭК», печать накладной и ШК возвращают PDF-ссылки;
  `PollCdekDeliveryStatusesJob` прогнан вручную — без исключений.
  Пересобраны nuxt-shop (`npm ci` + build + `pm2 restart`) и vue-admin.
  Smoke: pm2 online, pending миграций 0, /up → 200. Heads: laravel
  `2eed63c`, vue-admin `4fb7e70`, nuxt-shop `5af8f9f` (без изменений).

- **2026-09-05 (10)** — страница СДЭК по заказу: блок действий по образцу
  эталона Insales. Пока заявка не создана — одна кнопка «Отправить данные в
  СДЭК». После создания — над кнопками таблица «История статусов заказа»
  (Дата / Статус заказа / Город — город подтягивается из payload события,
  новый accessor `CdekStatusEvent::getCityAttribute()`) и четыре кнопки:
  «Печать накладной», «Печать ШК», «Обновить историю статусов» (существующий
  create/sync), «Удалить» (DELETE заявки в СДЭК + сброс локальной записи).
  Backend:
  - `GET /orders/{order}/cdek-delivery/waybill` и `.../barcode` (admin) —
    печать через `POST /v2/print/orders` / `POST /v2/print/barcodes`
    (payload `orders: [{order_uuid}]`, накладная `format=pdf`, ШК `A6`).
    СДЭК отвечает 202 без ссылки — сервис поллит `GET /v2/print/{path}/{uuid}`
    до появления url (до 10 попыток × 0.5 с), url может лежать в
    `data.url` или `data.entity.url` (нормализуется);
  - `cancelCdekDelivery` теперь «удаление заявки»: при успехе и при
    `v2_entity_not_found` (заявки на стороне СДЭК уже нет) локальная
    `cdek_orders`-запись **сбрасывается** (`resetCdekOrder`: uuid/статусы/
    ошибки в null, события статусов удаляются), а не soft-delete —
    `order_id` и `external_order_number` UNIQUE, мягко-удалённая запись
    блокировала повторное создание (Duplicate entry 1062).
  Попутно починено сломанное состояние на сервере: soft-deleted запись
  заявки заказа 67886 удалена (`forceDelete` через tinker), заявка создана
  заново. Фронт: `openPrintUrl()` открывает окно до запроса (иначе
  блокировщик popup'ов), кнопка «Удалить» с confirm. Миграций/сидов нет;
  laravel — `optimize:clear` + рестарт pm2-воркеров, vue-admin пересобран
  (коммит 549be94, без изменений в таблице товаров с (9)). Тесты:
  `CdekClientTest` 10/10 (новый: печать — POST payload `order_uuid`,
  поллинг 202→GET→url из entity; без uuid — InvalidArgumentException),
  `CdekStatusEventTest` 2/2 (accessor города). Проверено headless-браузером
  с временным админом (удалён, временный sanctum-токен удалён) на живом API
  на заказе 67886, полный цикл: удалить заявку → «Отправить данные в
  СДЭК»» → заявка создана → история «05.09.2026 Создан / Офис СДЭК» →
  печать накладной открыла
  `api.cdek.ru/v2/print/orders/…pdf`, печать ШК — `…/print/barcodes/…pdf`;
  71717 — только кнопка «Отправить данные в СДЭК», истории нет; JS-ошибок
  нет. Smoke: pm2 online, /up → 200. Heads: laravel `7926533`, vue-admin
  `549be94`.

- **2026-09-05 (9)** — редизайн блока «Товары в заказе» на странице СДЭК по
  заказу (https://againdev3.ru/admin/order/71716/cdek): вместо стилизации
  под эталон Insales (зелёные ячейки greenka) — таблица в стиле дашборда
  (как «Позиции заказа» на странице заказа): №, фото (миниатюра варианта/
  товара), артикул, наименование + цвет-кружок и строка варианта, вес (г,
  с фолбэком на «Вес по умолчанию» настроек СДЭК), кол-во, цена, сумма,
  объявленная стоимость (та же формула, что бэкенд шлёт в СДЭК:
  фиксированное значение → процент → цена позиции); строка «Итого»
  (вес/кол-во/сумма/объявленная) и подстрока-примечание об источнике
  объявленной стоимости. Попутно: убран дубль варианта в наименовании
  (вариант — отдельной строкой), итог веса считает по той же логике с
  фолбэком, что строка (раньше при пустых весах показывал 0 при строке
  80). Примечание: у legacy-заказов (71716) наименование берётся из
  `legacy_name` и может уже содержать вариант — это данные заказа, не
  дубль. Backend не менялся, миграций/сидов нет; vue-admin пересобран.
  Проверено headless-браузером с временным админом (удалён): 67886 —
  заголовки таблицы, строка с вариантом и итог 579/1/7900/2490; 71716 —
  legacy-имя, итог 160/2/5580/4980; старых greenka-ячеек нет, JS-ошибок
  нет. Smoke: pm2 online, /up → 200. Head: vue-admin `68ebd81`.

- **2026-09-05 (8)** — страница СДЭК по заказу
  (https://againdev3.ru/admin/order/71716/cdek, дашборд `OrderCdekCard.vue`)
  переработана по структуре служебной страницы СДЭК из приложения Insales:
  навбар с брендом + ссылкой «Отследить на cdek.ru», синий баннер «ВАШ ЗАКАЗ
  №N» (номер = `order_number`), статусбар (создание/статус/синхронизация),
  таблица «Товары в заказе» в стиле эталона: слева артикул + наименование
  (цвет/размер) + вес, справа зелёные ячейки «Количество штук» / «Стоимость
  товара» / «Объявленная стоимость» (объявленная считается как в
  `CdekDeliveryService::declaredCost`: фиксированное значение > процент >
  цена позиции; настройки читаются с бэка
  `third-party-integrations/cdek/settings`), блок «Информация о доставке»:
  Получатель (имя/телефон/email) + Оплата (стоимость доставки зелёным,
  способ оплаты — человекочитаемый лейбл через
  `useOrderPaymentMethods`), Адрес доставки (город/адрес, для
  pickup/postamat — строка «В пункт ПВЗ: код, адрес») + Тариф (имя или код,
  срок «1–2 дн.» из period-объекта, тип доставки по-русски). Внизу кнопка
  «Отправить данные в СДЭК» (существующий create/sync), отмена и таймлайн
  истории статусов (как раньше). Блок «вызов курьера» не реализован (в CDEK
  API v2 нет публичного метода). Попутные фиксы: дубль адреса при пустом
  `delivery_data.destination` (фолбэк на order.address больше не
  дублируется), тариф «—» (добавлен фолбэк на `delivery_data.tariff_code`),
  `period` выводится форматированно (объект `{min,max}` отображался сырым
  JSON), `delivery_type` переводится в «Курьер/Пункт выдачи/Постамат».
  Backend не менялся, миграций/сидов нет; vue-admin пересобран. Проверено
  headless-браузером с временным админом (удалён) на двух заказах: 71716
  (пустое delivery_data — адрес не дублируется, тариф «—», кнопка «Отправить
  данные в СДЭК») и 67886 (pickup СПб, ПВЗ SPB1241, тариф «Посылка
  дверь-склад», срок 1–2 дн., оплата «Яндекс Пэй», кнопка «Обновить статус
  заявки»); desktop + mobile 375px, JS-ошибок от страницы нет (в консоли
  только старый 404 service-worker). Smoke: pm2 online, /up → 200. Head:
  vue-admin `82b4fe0`.

- **2026-09-05 (7)** — доработка выбора варианта по умолчанию на странице
  товара (продолжение (5)): если у товара и всех вариантов есть цена,
  дефолт выбирается по **сортировке по умолчанию** — первый цвет из списка
  карточки + первый размер в порядке списка размеров (XS→S→M→L→XL→XXL→
  XXXL, неизвестные размеры в конце). Если цена в МС задана не у всех —
  первый покупаемый вариант в той же сортировке. Логика вынесена в общий
  `utils/productVariants.ts` (`sortVariantsDefault` + `pickDefaultVariant`),
  им пользуются и SSR-выбор в `pages/catalog/[slug].vue`, и
  `Variations.vue` (onMounted и смена цвета) — оба дают одинаковый дефолт;
  `Variations.vue` заодно использует `sortVariantsDefault` для списка
  размеров. До этого дефолт брался первым из порядка API (`variations[0]`),
  который не совпадает с отображаемым списком (BOX 233 открывался на
  XXXXL — последнем размере в UI). Миграций/сидов нет; nuxt-shop
  пересобран, `pm2 restart nuxt-shop`. Проверено headless: 354 — дефолт
  Чёрный/L (1000 ₽, «В корзину»), «Розовая пудра» → «Сообщить о
  поступлении», возврат к Чёрному → снова L/«В корзину»; 357 — первый цвет
  (2) + XS; BOX 233/343 — XS (было XXXXL/M); 356 — S (единственный вариант
  с ценой в МС, цены обновлены пользователем), «В корзину»; JS-ошибок нет.
  Smoke: pm2 online, /up → 200, /catalog → 200. Heads: nuxt-shop `5af8f9f`.

- **2026-09-05 (6)** — со страницы СДЭК убран декоративный чекбокс
  «Страхование» (checked disabled, без v-model — никуда не сохранялся и ни на
  что не влиял; раздел «Дополнительные услуги» остался: подсказка про
  страховку, «Объявленная стоимость, %» / «Фиксированная объявленная
  стоимость, ₽», НДС, услуги, ПВЗ с безналом). Поля объявленной стоимости
  рабочие и есть в БД (`declared: {value: 2490, percent: 0}`): при создании
  заказа СДЭК каждая позиция уходит с `cost = declaredCost()` — фиксированное
  значение приоритетнее процента, оба нуля = полная цена товара; значения
  читаются из БД при каждом оформлении, смена в админке применяется сразу.
  Backend не менялся; vue-admin пересобран. Проверено headless-браузером с
  временным админом (удалён): чекбокса нет, поля показывают 0 и 2490.
  Head: vue-admin `31d0896`.

- **2026-09-05 (5)** — кнопка «В корзину»/«Сообщить о поступлении» на
  странице товара для вариантов с частичной ценой (пример:
  https://againdev3.ru/catalog/nabor-belia-start-again, товар 354 из
  первых четырёх каталога). У товара `price: 0` (цена продажи в МС задана
  только у одного варианта из 12: Чёрный/L 1000 ₽), `stock_quantity: 1200`.
  Из-за этого на странице не было видно НИ ОДНОЙ кнопки: «В корзину»
  скрыта за `v-if="product.price"` (0), а ветка «Сообщить» — за
  `v-else if !canPurchaseSelectedOption`, который был true (варианты в
  наличии). Аналогично товар 356 (цен нет ни у одного варианта). Сделано
  (nuxt-shop, только фронт):
  - вариант по умолчанию — первый в наличии И с ценой (fallback: просто
    в наличии): в SSR-выборе `pages/catalog/[slug].vue` и в
    `Variations.vue` (onMounted раньше безусловно ставил `variations[0]`
    без цены, перетирая выбор родителя; при смене цвета тоже выбирается
    покупаемый вариант цвета);
  - «В корзину»/Quantity показываются по цене выбранного варианта
    (`getCurrentPrice()` = цена размера или товара) вместо `product.price`;
  - `canPurchaseSelectedOption` теперь требует вариант в наличии И с
    ценой (для товара без вариантов — наличие + цену), поэтому при
    выборе непокупаемого варианта показывается «Сообщить о поступлении» —
    одна из двух кнопок есть всегда.
  Миграций/сидов нет; nuxt-shop пересобран, `pm2 restart nuxt-shop`.
  Проверено headless-браузером (basic auth витрины): 354 — дефолт Чёрный+L,
  цена 1000 ₽, «В корзину» (клик → POST /api/cart 200); выбор «Розовая
  пудра» или Чёрный/S → «Сообщить о поступлении»; 356 → «Сообщить о
  поступлении» (цен в МС нет — задать их в МС, тогда появится корзина);
  BOX 233/343 и сертификат 341 — «В корзину» с ценой, без регрессий;
  JS-ошибок нет. Попутно: клик по кнопке перекрывается OTO-модалкой
  «Скидка 15%» (существующее поведение, не от этой выкатки). Smoke: pm2
  online, /up → 200, /catalog → 200. Heads: nuxt-shop `8a12509`.

- **2026-09-05 (4)** — расчёт СДЭК в чекауте: габариты/вес из карточки товара,
  фолбэк — настройки «Вес по умолчанию» / «Длина/Ширина/Высота» (страница
  СДЭК, секция «Параметры отправки»). Логика фолбэка на бэке существовала
  всегда (`measurement()`), но витрина слала только `weight` с хардкодом 500 г
  и не слала габариты, а валидация `CdekDeliveryController::calculate`
  габариты вообще отбрасывала и ломалась на весе-строке (`"350.000"` из
  decimal-каста API не проходит `integer` → 422, т.е. товар с заполненным
  весом убивал расчёт СДЭК в чекауте). Сделано:
  - backend: валидация `items.*.weight/length/width/height` —
    `nullable|numeric` (бэку достаточно: отсутствующие поля `measurement()`
    заполнит из `default_package`);
  - витрина: `cdekItems()` шлёт `weight/length/width/height` из карточки
    (предпочитается `selected_variant`), без хардкода 500;
  - при оформлении заказа сервер и раньше брал реальные данные из БД
    (`checkoutItem()`), менялся только расчёт показа в чекауте.
  Миграций/сидов нет; laravel — `optimize:clear` + рестарт pm2-воркеров,
  nuxt-shop пересобран и перезапущен. Тесты: `CdekClientTest` 8/8 (новый:
    посылка = 350+80×2 г / max длины-ширины / сумма высот — данные товара +
    фолбэк настроек), `CdekWarehousesTest` 7/7 (новый: публичный calculate
  принимает decimal-строки `"350.000"`/`"35.00"`, посылка уходит с данными
  товара; без измерений — 200). Проверено на живом API: расчёт с
  decimal-строками → success (до фикса был бы 422), без измерений → success
  (фолбэк). Smoke: pm2 online, /up → 200. Heads: laravel `983139e`,
  nuxt-shop `36042ab`.

- **2026-09-05 (3)** — подсказка о пустом поле «Пароль» на странице СДЭК
  (https://againdev3.ru/admin/integrations/delivery/cdek): под полем добавлен
  текст «Пароль скрыт в целях безопасности: он не возвращается с сервера и
  показывается пустым. Поле заполняйте только при смене пароля — пустое поле
  при сохранении оставит текущий пароль без изменений.» Backend не менялся;
  vue-admin пересобран. Проверено headless-браузером с временным админом
  (создан/удалён на сервере): подсказка видна, «Аккаунт» предзаполнен из БД,
  поле пароля пустое. Попутно (без кода): креды СДЭК из `.env` скопированы в
  БД (`settings.account`/`settings.secure_password`), OAuth-токен сброшен,
  проверен свежий логин по кредам из БД (расчёт тарифа работает, пароль в
  ответах API не светится). Head: vue-admin `0fb0afe`.

- **2026-09-05 (2)** — кнопка «Отменить оплату» на странице заказа
  (https://againdev3.ru/admin/order/71716, блок «Статусы»): над кнопкой
  возвращён заголовок «Возврат оплаты» (как в ранней версии кнопки), а сама
  она стоит справа от поля «Дата оплаты» и ровно по верхней грани инпута.
  Итоговая структура клетки повторяет DOM поля «Дата оплаты» (label
  text-xs uppercase + блочный контрол `mt-1 w-fit`), поэтому лейблы и
  верхи совпадают попиксельно без подбора отступов (промежуточные варианты
  «без лейбла с pt-7» и «лейбл block + mt-1» давали перекос 4-8px из-за
  inline-strut строки). `OrderStatuses.vue` (vue-admin), правка только в
  разметке/классах. Backend не менялся, миграций/сидов нет; vue-admin
  пересобран на сервере, laravel/nuxt не трогались. Проверено
  headless-браузером с временными админ-токенами (удалены): desktop —
  лейблы на одной высоте, кнопка y=260 = инпут y=260 (h 36=36), x=740
  (инпут 344+380 + 16px gap); mobile 375px — лейбл сверху, кнопка под ним
  по левому краю; JS-ошибок нет. Smoke: pm2 online, /up → 200. Head:
  vue-admin `721e1d3`.

- **2026-09-05** — привязка поля «Увеличить время доставки на, дней» на странице
  СДЭК (https://againdev3.ru/admin/integrations/delivery/cdek). Поле и логика
  существовали давно (дашборд отправлял `delivery_days_offset`, сервис
  `CdekDeliveryService` прибавлял его к `period_min/max`), но
  `CDEKController::saveSettings` не имел правила валидации для
  `settings.delivery_days_offset` — `validate()` молча отбрасывал ключ, и
  значение никогда не сохранялось в БД (та же история, что с
  `sender.city_name` 2026-09-04 (4)). Добавлено правило
  `nullable|integer|min:0|max:30`. Правок фронта не потребовалось: витрина
  уже показывает `X-Y дн.` (`components/Checkout/Delivery.vue`). Миграций/сидов
  нет; laravel — `optimize:clear` + рестарт pm2-воркеров; фронты не
  пересобирались (код не менялся, только pull). Тесты: `CdekClientTest` 7/7
  (в т.ч. новый: offset 3 → период 2-4 становится 5-7), `CdekWarehousesTest`
  6/6 (в т.ч. новый: PUT сохраняет offset=2, отрицательное значение → 422).
  Проверено end-to-end на сервере с временным sanctum-токеном (удалён):
  PUT offset=2 → в БД 2; `/api/public/delivery/cdek/calculate` курьер Москва
  тариф 137: период 2-3 → 4-5; значение возвращено в 0. Head: laravel
  `1e1ff39` (nuxt-shop `1944805`, vue-admin `47fd44e` без изменений).

- **2026-09-04 (4)** — «Город отправки» в настройках СДЭК
  (https://againdev3.ru/admin/integrations/delivery/cdek, «Параметры
  отправки»): вместо свободного текстового поля — автокомплит по складам
  СДЭК. Сделано:
  - backend: `GET /api/third-party-integrations/cdek/warehouses?query&limit`
    (admin, sanctum) — справочник ПВЗ/постаматов РФ одним запросом
    `/v2/deliverypoints`, кэш на сутки (`cdek:warehouses:ru`); поиск по
    городу/региону/адресу с ранжированием (префикс города → вхождение →
    регион → адрес) и нормализацией уличных сокращений («проспект» →
    «пр-кт», СДЭК пишет адреса сокращённо). В `saveSettings` разрешено
    сохранять `sender.city_name` (раньше отбрасывался валидацией);
  - дашборд: дропдаун с дебаунс-поиском (300 мс), клавиатурная навигация
    (↑/↓/Enter/Esc), выбор склада заполняет «Город отправки» и «Код города
    СДЭК» (например, Москва → 44).
  Миграций/сидов нет; laravel — `optimize:clear` + рестарт pm2-воркеров,
  vue-admin пересобран. Тесты: `CdekWarehousesTest` 5/5 (сортировка/лимит,
  ранжирование поиска, кэш — один запрос deliverypoints, сохранение
  city_name, 401 без токена). Проверено headless-браузером на реальной
  странице: фокус → 100 складов, ввод «Моск» → сверху Москва, выбор
  подставляет city=Москва и code=44, повторное открытие и клавиатурный
  выбор работают, ошибок JS нет. API-проверки с реальным токеном
  (временные токены удалены). Heads: laravel `146fa6d`, vue-admin
  `47fd44e` (включают попутные коммиты пользователя по refund-кнопке).

- **2026-09-04 (3)** — главная: блоки товаров привязаны к управляемым
  категориям. Секция «Технология белья» запрашивала несуществующую категорию
  `novinki` (её нет в БД/админке), и API молча игнорировал фильтр — блок
  показывал первые 4 товара всего каталога (233/343/354/356), никак не
  управляемые из админки. Теперь: блок 4 карточек в секции «Технология
  белья» (`components/Home/Technology.vue`) тянет
  `Товары на главной_4` (358/356/357/355 по позициям из админки); в секции
  каталога под «Все товары» (`components/Home/Catalog.vue`) оставлен один
  грид из 8 — `Товары на главной_8` (дубль блока _4 удалён). Итого на
  главной ровно 12 карточек (4 + 8), порядок как в админке. Backend не
  менялся; nuxt-shop пересобран, `pm2 restart nuxt-shop`. Проверено
  headless (desktop+mobile): grid#1 = 358/356/357/355 (все видны),
  grid#2 = 357/337/355/246/235/238/348/358, total 12, лишних товаров нет;
  /up → 200. Скриншоты: `/tmp/opencode/pwtest/home-tech-block.png`,
  `home-after-fix.png`. Heads: nuxt-shop `1944805`.

- **2026-09-04 (2)** — выравнивание кнопки «Заказать» в каталоге
  (https://againdev3.ru/catalog): во всех карточках одного ряда «Заказать»
  теперь на одном уровне, кнопки маркетплейсов идут ниже и занимают резерв
  только в тех рядах, где они есть. Сделано через CSS `subgrid`
  (`components/Catalog/Grid.vue`, правка только в сетке): карточка
  `.catalog-item` в grid-контексте — `display: grid; grid-row: span 2;
  grid-template-rows: subgrid`, т.е. делится на два подряда — контент
  (`__card`) и блок кнопок (`cart_btns`). Треки общие для ряда: верх трека
  кнопок у всех карточек ряда одинаков → «Заказать» выровнен; маркетплейс-
  кнопки внутри `cart_btns` идут сразу под «Заказать», у карточек без них —
  пустой резерв до низа трека (только если в ряду есть карточки с кнопками).
  Межрядный отступ переехал из `row-gap` (в subgrid-режиме `row-gap: 0`) в
  `margin-bottom` у `cart_btns` (3rem / 2rem mobile) — визуально то же
  расстояние. Браузеры без subgrid (Chrome < 117 и т.п.) через
  `@supports` получают прежнее поведение (flex-колонка, кнопки потоком).
  Блок «C этим покупают» (swiper, не grid) не затронут — там карточка
  остаётся flex, кнопки прижаты к низу. Backend не менялся; nuxt-shop
  пересобран, `pm2 restart nuxt-shop`. Проверено headless-браузером
  (Chromium 152, subgrid): desktop — во всех рядах delta top «Заказать» = 0
  (233 c 3 mp-кнопками и соседи без них: btn@665 у всех; ряд 358/337 с 1
  mp-кнопкой — btn@1874 у всех), ряды без mp-кнопок не выросли; mobile —
  delta 0, межрядный ~20.8px; related — flex, кнопки у низа. Скриншоты:
  `/tmp/opencode/pwtest/subgrid-desktop-full.png`, `subgrid-card-358.png`,
  `subgrid-mobile.png`. Smoke: pm2 online, /up → 200. Heads: nuxt-shop
  `56c1b9b` (+ попутно выкачан локальный коммит пользователя `98c1c39` —
  CDEK pickup points for settlements, components/Checkout).

- **2026-09-04** — фикс пустого промежутка в карточках каталога
  (https://againdev3.ru/catalog): между «Заказать» и кнопками маркетплейсов
  растягивалось пустое место. Причина — из коммита выравнивания 57a0e28
  остались `.cart_btns { min-height: 21rem }` (18.5/15.5rem на
  планшете/мобиле) и `margin-top: auto` у маркетплейс-кнопок (дублем и в
  `Card.vue`, и в самом `MarketplaceLinksButtons.vue`): кнопка «Заказать»
  вставала сразу после контента, а WB/Ozon/ЗЯ прижимались к низу блока —
  промежуток раздувался до ~24 см в rem-резерве. Убраны `min-height` и оба
  `margin-top: auto` (кнопки идут потоком с отступом 10px, как до 57a0e28).
  Выравнивание по низу ряда не пострадало: `.catalog-item` — flex-колонка с
  `height: 100%`, `__card { flex: 1 }`, `cart_btns` в конце — группа кнопок
  остаётся прижатой к низу растянутой гридом карточки. Backend не менялся,
  миграций/сидов нет; nuxt-shop пересобран, `pm2 restart nuxt-shop`.
  Проверено headless-браузером на /catalog: у всех карточек с
  маркетплейс-кнопками (233/358/337) зазор «Заказать» → WB = 10px (до фикса
  — сотни px), у карточек без маркетплейс-кнопок «Заказать» на низу ряда
  (btnBottom 888 = низ ряда), низ группы кнопок у 233 совпадает с соседями.
  Smoke: pm2 online, /up → 200. Head: nuxt-shop `2315b8a`.

- **2026-09-01 (5)** — фикс «почему СДЭК не бесплатно при 15800 ₽»: в правиле
  «СДЭК: бесплатная доставка из настроек» (id 4, порог 7900) было условие по
  товарам — только «Любимый SET от доктора Садовская» (id 235), прикреплённое
  из админки 01.09 09:24. Движок считает порог только по товарам условия, и
  требует их наличия в корзине — корзина без этого товара не получала
  бесплатную доставку при любой сумме. Кода не менялось: из правила убрано
  товарное условие (detach через tinker), порог 7900 теперь считается по всей
  корзине. Проверено по API `/api/public/delivery/free-shipping/evaluate`:
  корзина 17230 без товара 235, тариф 136 → `is_free: true` (до фикса —
  false). Кеш правил перезапросный (на запрос), рестарты не нужны. Тарифы
  бесплатной доставки СДЭК: 137/368/136 из настроек интеграции.

- **2026-09-01 (4)** — фикс выбора ПВЗ/постамата СДЭК в чекауте (два бага):
  - карта не показывалась при повторном открытии модалки: DOM модалки живёт
    под `v-if="isOpen"`, а инстанс `ymaps.Map` кэшировался в переменной
    компонента — при повторном открытии карта оставалась привязанной к
    уничтоженному контейнеру и не пересоздавалась. Теперь при закрытии
    модалки карта уничтожается (`map.destroy()`) и создаётся заново при
    каждом открытии. Исправлено в обеих модалках: `CdekPvzModal.vue` (СДЭК)
    и `YandexPvzModal.vue` (Яндекс — та же латентная проблема);
  - блок «Пункт выдачи СДЭК» не показывал адрес (и в заказ уходил
    `pvz_address: null`): в `watch(selectedCdekPvzCode)` адрес читался из
    `address.value` сразу после записи в ту же модель. С привязанным
    родительским v-model `defineModel.set()` не обновляет локальное
    значение синхронно (только эмит; значение возвращается после
    раунд-трипа через родителя) — читалось старое значение (null). Исправлено
    через локальную переменную `pointAddress` (по образцу Яндекс-пути
    `onPvzSelect`).
  Деплой: nuxt-shop пересобран, `pm2 restart nuxt-shop`. Проверено
  headless-браузером на https://againdev3.ru/checkout: 3 цикла
  открыть/закрыть модалку ПВЗ + переход на постамат — карта рендерится
  каждый раз (113/118 ymaps-элементов, 435 ПВЗ / 62 постамата в Москве);
  адрес выбранного пункта отображается в блоке, подставляется в поле
  «Адрес», тариф рассчитывается. Известное: в `.env` витрины нет
  `YANDEX_MAPS_API_KEY` — карты грузятся keyless (работают, но в консоли
  `Invalid API key`; ключ получить на developer.tech.yandex.ru и добавить,
  см. деплой 2026-08-18). Head: nuxt-shop `70e632a`.

- **2026-09-01 (3)** — СДЭК (Интеграции → Доставка → СДЭК): выбор тарифов
  переделан с multi-select (с подсказкой «Для выбора нескольких удерживайте
  Ctrl / ⌘») на прокручиваемый список с чекбоксами (269 тарифов, сохранённые
  отмечены). Подсказка про Ctrl удалена. Дашборд
  `src/components/integrations/delivery/cdek/index.vue`: `Checkbox` из
  ui-кита, `toggleTariff` для `form.tariff_codes`, стили `.tariff-list`.
  Backend не менялся, миграций/сидов нет; vue-admin пересобран на сервере.
  Проверено headless-браузером: список с чекбоксами рендерится, сохранённые
  тарифы 137/368/136 отмечены, клики отмечают/снимают тарифы, старого select
  и подсказки нет. Настройки в БД не трогались. Head: vue-admin `d466e0c`.

- **2026-09-01 (2)** — фикс поиска по номеру заказа: запрос «12989» возвращал
  лишний заказ с другим номером. Причина: `OrderFilterService` матчил не
  только `order_number LIKE %12989%`, но и внутренний `orders.id = 12989`
  (у того заказа номер 1985 — пользователь видел в выдаче «чужой» номер).
  У всех 33553 заказов `order_number` заполнен, т.е. id в выдаче никогда
  не соответствует видимому номеру. Сделано:
  - backend: `filterByOrderNumber` и общий `search` — id матчится только у
    заказов с `order_number IS NULL` (в списке такие показывали бы id как
    номер);
  - дашборд: подпись фильтра столбца «Номер заказа» — «Номер заказа»
    (было «Номер или ID заказа»).
  Миграций/сидов нет; laravel — `optimize:clear` + рестарт pm2-воркеров,
  vue-admin пересобран. Проверено по API: `order_number=12989` → ровно
  заказ №12989 (total 1), частичный ввод `1298` работает; headless-браузером:
    фильтр столбца «Номер заказа» + ввод 12989 → одна строка, №1985 в
  выдаче нет. Верхний поиск `search=12989` цепляет заказ №1572 — это
  телефон клиента `+79037129898` содержит «12989», штатное поведение
  поиска по телефону. Heads: laravel `c04e7b1`, vue-admin `52e9f02`.

- **2026-09-01** — «Использован» в статистике промокода стал ссылкой на заказы.
  Клик по счётчику в модалке статистики (Скидки и промокоды → Промокоды →
  клик по коду) ведёт на `/orders/list?promo_code_id=N&promo_code=code` —
  список заказов, в которых применён этот купон. Сделано:
  - backend: `OrderFilterService` — новый точный фильтр `promo_code_id`
    (`orders.promo_code_id = N`), валидация `exists:promo_codes,id`,
    отображение в `filters` ответа (существовавший `promo_code` — LIKE и
    мог зацепить похожие коды);
  - дашборд: `PromoStatisticList` — счётчик «Использован» теперь router-link;
    `OrdersList` принимает `promo_code_id`/`promo_code` из query (в т.ч.
    повторный переход по ссылке при уже открытой странице), показывает чип
    «Промокод: gorelova ✕» с удалением фильтра, «Сбросить» чистит и его;
    `useOrderFunctions.getOrders` прокидывает `promo_code_id`.
  Миграций/сидов нет; laravel — `optimize:clear` + рестарт pm2-воркеров,
  vue-admin пересобран на сервере. Проверено с реальным админ-токеном:
  `/api/orders?promo_code_id=24` (`gorelova`) → ровно заказ 2204/№1004
  (total 1), несуществующий id → 422, без фильтра total 33553; тестовые
  токены удалены. Heads: laravel `011ff3e`, vue-admin `265b62e`.
  Доработка в тот же день: цифра-ссылка неочевидна пользователю, вместо
  неё явная кнопка «Открыть заказы с этим промокодом» (variant=outline,
  иконка List), счётчик остался текстом «Использован: N раз».
  Перепроверено headless-браузером: клик по кнопке → /orders/list с чипом
  фильтра и заказом №1004. Head: vue-admin `d8b9aa2`.

- **2026-08-27** — восстановление мессенджеров после переезда на `againdev3.ru`
  и снятие привязки к домену в коде. Вебхуки Telegram, MAX и VK всё ещё
  указывали на выведенный из эксплуатации `sub.againdev.ru`, поэтому входящие
  сообщения молча терялись (`last_error_message: Connection refused`,
  `pending_update_count` рос). Сделано:
  - Telegram: старые боты (`againChilla_bot`, `againdev_test_bot`) отвязаны
    (`deleteWebhook`) и удалены из `telegraph_bots`/`telegraph_chats` (бэкап
    `/root/backups/telegraph-backup-*.json`), подключён новый
    `@again8help_bot` + `TELEGRAM_BOT_USERNAME` в `.env`;
  - MAX: `MAX_WEBHOOK_URL` убран из `.env` (теперь = `APP_URL`), подписка
    перерегистрирована;
  - VK: callback-сервер переведён на новый домен; попутно оказался устаревшим
    `vk_settings.confirmation_token` (VK ждал другую строку → `status=failed`),
    синхронизирован через `groups.getCallbackConfirmationCode`;
  - `WHATSAPP_SERVICE_URL` переведён на `http://127.0.0.1:3002` (сервис живёт
    на том же хосте, публичный домен в цепочке не нужен);
  - `urls:canonicalize` вылечил 138 строк (`utm_links` 11, `images` 4,
    `message_attachments` 123) — до этого `/go/{slug}` редиректил на мёртвый
    домен;
  - код: `App\Support\PublicUrl`, `config/cors.php` из env, письма и билдеры
    сообщений без зашитого `again8.ru`, исправлена опечатка `env('FRONDEND_URL')`
    в сбросе пароля, удалён мёртвый блок `TELEGRAM_PROXY`;
  - новые команды `integrations:sync-webhooks` и `urls:canonicalize`;
  - из `sites-enabled/` вынесены два `*.bak` конфига nginx (давали
    `conflicting server name`).
  Тесты: `PublicUrlTest` 7/7, `UtmTrackingTest` 15/15, `OtoBannerResourceTest`
  1/1. Heads: laravel `c805197`, vue-admin `4427d08`, nuxt-shop `3f2a60e`.

  > Известный красный тест **не от этой выкатки**: `FreeShippingTest` —
  > 2 падения (`Expected 201, received 500`). Причина: публичное создание
  > заказа теперь жёстко требует настройки МойСклад
  > (`MoySklad\OrderService::__construct` бросает исключение), а в тестовой БД
  > строки `delivery_services_settings` нет. На проде настройки есть
  > (`moysklad`, `yandex`), чекаут работает. Воспроизводится и на коммите
  > `ccc63ac` — до правок этой выкатки.

- **2026-08-18** — фича «Бесплатная доставка» (см.
  `docs/tasks/free-shipping.md`): гибкие правила в админке (Настройки →
  Бесплатная доставка), применение в чекауте и показ на витрине. Применено
  5 миграций (`free_shipping_rules` + пивоты, `orders.free_shipping_rule_id` и
  `delivery_cost_original`, а также guard-миграция для legacy-справочников
  `country/region/city`). Засеян `FreeShippingRuleSeeder` (2 правила,
  идемпотентный, воспроизводит прежний хардкод Яндекс 4500/7900). Тесты
  `--filter FreeShippingTest` — 21/21 OK. Проверено на dev: публичная оценка
  `/api/public/delivery/free-shipping/evaluate` (4500 взят / 7900 нет,
  прогресс), admin CRUD с реальным токеном (условие по способу оплаты
  отсекает правило), `applyToOrder` на живой БД в откаченной транзакции
  (обнуление и возврат платной доставки при падении суммы ниже порога).
  Тестовые данные удалены. Heads: laravel `f0a4b9b`, nuxt-shop `165ec38`,
  vue-admin `1d0994d`.
  Отдельными коммитами выкачена работа, лежавшая в рабочем дереве:
  СДЭК-ревалидация тарифа при оформлении и выбор ПВЗ Яндекс.Карт на витрине
  (последняя требует `YANDEX_MAPS_API_KEY` в `.env` витрины).

  > Известный баseline: полный прогон `php vendor/bin/phpunit` красный и **до**
  > этой выкатки — тесты с `RefreshDatabase` (Auth/*, ProfileTest, ExampleTest)
  > пересоздают общую БД `testing` и оставляют её схему неполной, из-за чего
  > падают остальные наборы. Прогонять пакеты по отдельности
  > (`--filter <TestName>`).

- **2026-08-04** — единый домен сменён на **`againdev3.ru`**; прежний адрес
  перенаправляется на новый.

- **2026-07-12** — deeplink-привязка переписки из мессенджеров к клиенту/заказу
  (см. `docs/tasks/messenger-deeplink-binding.md`): новая таблица/модель
  `chat_binding_tokens`, `ChatBindingService`, публичный эндпоинт
  `GET /api/public/chat/messenger-links`, чтение токена в вебхуках TG/MAX/VK.
  Смена Telegram-бота на `againdev_test_bot` (вынесен в
  `config/services.php → messenger_deeplinks`), удалён WhatsApp из виджета чата.
  Отдельным коммитом — привязка гостевого заказа к автосозданному клиенту
  (`orders.client_id`). Применена 1 миграция. Проверено: эндпоинт отдаёт верные
  ссылки/боты, идемпотентность токена, `resolveBinding` привязывает клиента по
  заказу. Heads: laravel `db2cec5`, nuxt-shop `e93336e`, vue-admin `542604e`.
- **2026-06-29** — переход на единый домен: витрина,
  дашборд и API на одном origin; прежний домен витрины выведен из
  эксплуатации. nginx маршрутит `/api` и `/go` в laravel, `/admin` — статика
  vue-admin, `/` — nuxt-shop SSR. `guest_token` и `utm_link_id` стали host-only
  first-party автоматически (обход same-origin `/api` больше не нужен). env:
  `APP_URL=FRONTEND_URL`, `UTM_COOKIE_SECURE=true`.
  Конфиги/код почищены от мёртвых доменов (`cors.php`, `app.frontend_url`,
  фолбэки `FRONTEND_URL`).
- **2026-06-28** — same-origin `/api` для витрины: nginx отдаёт
  `/api` через php-fpm (laravel), `nuxt-shop` использует единый API URL.
  Чинит гостевую корзину (`guest_token` стал first-party cookie). Также: фикс
  recovery-ссылки (`/cart/restore` алиас → `/cart/recovery`, `CART_RECOVERY_URL`
  на канонический путь), бейдж «Гость»/«Клиент» и контакт гостя в списке корзин,
  выключена авто-рассылка (`ABANDONED_CART_ENABLED=false`).
- **2026-06-27** — выкат: накопительные подарки (стекируемые акции,
  `promotions[]`), UTM-трекинг + фиксы дашборда, брошенные/универсальная корзина,
  restock-подписки, coming-soon. Применено 16 миграций; засеян
  `MarketingChannelSeeder` (6 каналов). Heads: laravel `2f688ea`,
  nuxt-shop `4fef036`, vue-admin `302cd60`.
