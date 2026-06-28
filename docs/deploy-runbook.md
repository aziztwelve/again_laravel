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

### 0. Предпроверка (read-only)
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
for p in /var/www/html/laravel /var/www/html/nuxt-shop /var/www/html/vue-admin; do
  echo "== $p =="; git -C "$p" rev-parse --abbrev-ref HEAD;
  echo "dirty: $(git -C "$p" status --porcelain | wc -l)";
done'
```
Деревья должны быть чистыми (`dirty: 0`). Если есть незакоммиченные правки на
сервере — НЕ продолжать, сначала разобраться.

### 1. Pull (fast-forward only)
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
for p in /var/www/html/laravel /var/www/html/nuxt-shop /var/www/html/vue-admin; do
  echo "== $p =="; git -C "$p" pull --ff-only origin main 2>&1 | tail -3;
  echo "head: $(git -C "$p" rev-parse --short HEAD)";
done'
```

### 2. Backend (laravel)
```bash
ssh -o BatchMode=yes -o ConnectTimeout=120 root@186.246.14.59 '
cd /var/www/html/laravel
composer install --no-interaction --prefer-dist --no-progress 2>&1 | tail -5
php artisan migrate --force 2>&1 | tail -40
php artisan optimize:clear
pm2 restart laravel-queue laravel-scheduler laravel-reverb
'
```

### 3. Сиды (ОСТОРОЖНО)
- **НЕ запускать** `php artisan db:seed` целиком (`DatabaseSeeder`) — он
  пересоздаёт Users/Products/Clients/Orders/PromoCodes и т.д. и побьёт/задублирует
  данные сервера.
- Запускать только нужные идемпотентные сидеры точечно, например каналы UTM:
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
cd /var/www/html/laravel && php artisan db:seed --class=MarketingChannelSeeder --force'
```
  (`MarketingChannelSeeder` использует `updateOrCreate` по `code` — безопасен.)

### 4. Витрина (nuxt-shop)
```bash
ssh -o BatchMode=yes -o ConnectTimeout=900 root@186.246.14.59 '
cd /var/www/html/nuxt-shop
npm install --no-audit --no-fund 2>&1 | tail -3
NODE_OPTIONS=--max-old-space-size=2048 npm run build 2>&1 | tail -6
pm2 restart nuxt-shop --update-env
'
```

### 5. Дашборд (vue-admin) — статика для nginx
```bash
ssh -o BatchMode=yes -o ConnectTimeout=900 root@186.246.14.59 '
cd /var/www/html/vue-admin
npm install --no-audit --no-fund 2>&1 | tail -3   # может ругнуться ERESOLVE — ок, если deps не менялись
NODE_OPTIONS=--max-old-space-size=2048 npm run build 2>&1 | tail -6
'
```
> Если `npm install` падает на ERESOLVE, а package.json НЕ менялся — игнорировать,
> сборка пройдёт на существующих `node_modules`. Если зависимости менялись —
> `npm install --legacy-peer-deps`.

### 6. Проверка (smoke)
```bash
ssh -o BatchMode=yes root@186.246.14.59 '
pm2 list | grep -E "laravel|nuxt|whatsapp"
cd /var/www/html/laravel && echo "pending: $(php artisan migrate:status | grep -ci pending)"
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

## Витрина и API на одном origin (важно для гостевой корзины)

Витрина (`sub.againdev2.ru`) и API (`sub.againdev.ru`) — **разные домены**. Гостевая
корзина завязана на HttpOnly-cookie `guest_token`; на разных доменах это сторонняя
(third-party) cookie, и браузеры её блокируют → гостевая корзина не работает.

**Решение (внедрено): same-origin `/api`.** На vhost витрины `/api` обслуживается
напрямую laravel через php-fpm, а витрина шлёт запросы на свой же origin.

1. **nginx** `/etc/nginx/sites-available/sub.againdev2.ru` — в `server {443}` ДО
   `location /` добавлены:
   ```nginx
   location /api {
       root /var/www/html/laravel/public;
       try_files $uri /index.php?$query_string;
   }
   location ~ \.php$ {
       root /var/www/html/laravel/public;
       include snippets/fastcgi-php.conf;
       fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
       fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       include fastcgi_params;
       fastcgi_read_timeout 1800;
       fastcgi_send_timeout 1800;
   }
   ```
   Применять: `cp` бэкап → правка → `nginx -t` → `nginx -s reload` (при ошибке `nginx -t` откатить из бэкапа).
2. **nuxt-shop `.env`** — `API_URL=https://sub.againdev2.ru/api` (тот же origin;
   `useApi` строит URL как `DEV_URI(=API_URL) + path`). После правки — пересборка.
   `API_BASE_URL` (для картинок/echo) можно оставить на `sub.againdev.ru`.
3. SSL-сертификат `live/sub.againdev.ru` имеет SAN на оба домена — SSR-self-вызовы
   витрины на `sub.againdev2.ru` валидны по TLS.

Проверка: `Set-Cookie: guest_token=…` без атрибута `Domain` (host-only) при
`POST https://sub.againdev2.ru/api/cart/items/bulk` → cookie first-party.

> Если деплоят на новый сервер/домены — повторить оба шага (nginx `/api` + `API_URL`),
> иначе гостевая корзина/`guest_token` работать не будут.

---

## История деплоев

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
