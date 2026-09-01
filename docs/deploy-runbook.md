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
