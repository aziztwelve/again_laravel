# Деплой на сервер — runbook

Инструкция по выкатке изменений на сервер. По команде «выкати/задеплой» —
выполнять шаги ниже по порядку.

**Сервер:** `ssh root@186.246.14.59` (хост `7826976-ck036553.twc1.net`)
**Публичный адрес:** https://sub.againdev.ru
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
```bash
ssh -o BatchMode=yes -o ConnectTimeout=900 root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/nuxt-shop
npm install --no-audit --no-fund 2>&1 | tail -3
NODE_OPTIONS=--max-old-space-size=2048 npm run build 2>&1 | tail -6
pm2 restart nuxt-shop --update-env
'
```

### 6. Дашборд (vue-admin) — пересобирать всегда
```bash
ssh -o BatchMode=yes -o ConnectTimeout=900 root@186.246.14.59 '
set -euo pipefail
cd /var/www/html/vue-admin
npm install --no-audit --no-fund 2>&1 | tail -3   # может ругнуться ERESOLVE — ок, если deps не менялись
NODE_OPTIONS=--max-old-space-size=2048 npm run build 2>&1 | tail -6
'
```
> Если `npm install` падает на ERESOLVE, а package.json НЕ менялся — можно
> отдельно запустить только сборку на существующих `node_modules`. Если
> зависимости менялись — `npm install --legacy-peer-deps`.

### 7. Проверка (smoke)
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
set -euo pipefail
pm2 list | grep -E "laravel|nuxt|whatsapp"
cd /var/www/html/laravel && echo "pending: $(php artisan migrate:status | grep -ci pending || true)"
curl -s -o /dev/null -w "laravel /up -> %{http_code}\n" https://sub.againdev.ru/up -k
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

## Единый домен sub.againdev.ru (витрина + дашборд + API на одном origin)

Все три проекта обслуживаются на **одном домене** `sub.againdev.ru`. Старый
домен витрины `sub.againdev2.ru` выведен из эксплуатации. Один origin
автоматически делает все куки first-party — отдельные обходы (как раньше
same-origin `/api`) больше не нужны.

Для витрины на `sub.againdev.ru` включена basic auth в Nuxt middleware
`server/middleware/basic-auth.ts`: логин `dev`, пароль `12345678`. Защита
срабатывает только на запросах, которые доходят до `nuxt-shop` через `location /`;
`/api`, `/go` и `/admin/` обслуживаются отдельными nginx location и не закрываются
этой авторизацией.

**Маршрутизация nginx на `sub.againdev.ru` (server {443}), порядок важен —
specific ДО `location /`:**
```nginx
# API laravel (php-fpm)
location /api {
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
APP_URL=https://sub.againdev.ru
FRONTEND_URL=https://sub.againdev.ru      # витрина = тот же домен
# UTM_TRACKING_BASE_URL не задавать (по умолчанию = APP_URL)
UTM_COOKIE_SECURE=true                     # домен на HTTPS
# UTM_COOKIE_DOMAIN не задавать (host-only), UTM_COOKIE_SAMESITE=lax
# CART_COOKIE_* / SESSION_DOMAIN не задавать (host-only)
```
**Витрина nuxt-shop `.env`:** `API_URL=https://sub.againdev.ru/api` (тот же origin).

Проверка:
```bash
# guest_token — host-only first-party
curl -sI -X POST https://sub.againdev.ru/api/cart/items/bulk | grep -i 'set-cookie'
# /go ставит host-only utm_link_id и 302 на target_url
curl -sI https://sub.againdev.ru/go/<slug> | grep -iE 'location|set-cookie'
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

## История деплоев

- **2026-06-29** — переход на **единый домен** `sub.againdev.ru`: витрина,
  дашборд и API на одном origin; домен витрины `sub.againdev2.ru` выведен из
  эксплуатации. nginx маршрутит `/api` и `/go` в laravel, `/admin` — статика
  vue-admin, `/` — nuxt-shop SSR. `guest_token` и `utm_link_id` стали host-only
  first-party автоматически (обход same-origin `/api` больше не нужен). env:
  `APP_URL=FRONTEND_URL=https://sub.againdev.ru`, `UTM_COOKIE_SECURE=true`.
  Конфиги/код почищены от мёртвых доменов (`cors.php`, `app.frontend_url`,
  фолбэки `FRONTEND_URL`).
- **2026-06-28** — same-origin `/api` для витрины: nginx `sub.againdev2.ru` отдаёт
  `/api` через php-fpm (laravel), `nuxt-shop` `API_URL=https://sub.againdev2.ru/api`.
  Чинит гостевую корзину (`guest_token` стал first-party cookie). Также: фикс
  recovery-ссылки (`/cart/restore` алиас → `/cart/recovery`, `CART_RECOVERY_URL`
  на канонический путь), бейдж «Гость»/«Клиент» и контакт гостя в списке корзин,
  выключена авто-рассылка (`ABANDONED_CART_ENABLED=false`).
- **2026-06-27** — выкат: накопительные подарки (стекируемые акции,
  `promotions[]`), UTM-трекинг + фиксы дашборда, брошенные/универсальная корзина,
  restock-подписки, coming-soon. Применено 16 миграций; засеян
  `MarketingChannelSeeder` (6 каналов). Heads: laravel `2f688ea`,
  nuxt-shop `4fef036`, vue-admin `302cd60`.
