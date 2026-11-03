# Перенос основного домена на again8.ru

Документ подготовлен 2026-11-03. Сейчас сайт работает на `againdev3.ru`, домен `again8.ru`
пока указывает на старую площадку (InSales, `185.65.148.57`), на нашем сервере для него стоит
заглушка «технические работы». Ниже порядок переноса. Общие правила смены домена — в
`docs/deploy-runbook.md`, раздел «Смена домена — что обязательно сделать».

## Что уже подготовлено на сервере (`186.246.14.59`)

- В заглушке `again8.ru` (`/etc/nginx/sites-enabled/again8.ru`) добавлен путь
  `/.well-known/acme-challenge/` для выпуска сертификата. Копия старой заглушки:
  `/root/backups/domain-switch/again8.ru.stub.before`.
- Готов полный боевой конфиг `again8.ru` + `www.again8.ru` на основе конфига `againdev3.ru`
  (маршруты `/api`, `/go`, `/admin/`, витрина, вебсокеты): `/root/backups/domain-switch/again8.ru.https.draft`.
  В него включены пути сертификата `/etc/letsencrypt/live/again8.ru/` и редирект с порта 80.
  Он **не включён**, пока нет сертификата.
- Сертификата для `again8.ru` пока нет (есть только `againdev3.ru` и `sub.againdev.ru`).

## Блокер: DNS

Домен `again8.ru` делегирован на серверы **InSales** (`ns3.insales.ru`, `ns4.insales.ru`).
Они сейчас отдают `185.65.148.57` для `again8.ru` и `www.again8.ru`. Записи, изменённые в
панели DNS на reg.ru, не действуют, пока домен делегирован на InSales. Нужно сделать одно из двух:

1. изменить A-записи `again8.ru` и `www.again8.ru` на `186.246.14.59` **в DNS-панели InSales**, или
2. у регистратора (reg.ru) сменить серверы имён (NS) на серверы reg.ru и уже там задать A-записи
   `186.246.14.59`. Смена NS занимает до 24–48 часов.

Проверка, что адрес применился (должен вернуться `186.246.14.59`):
`dig +short again8.ru A @ns3.insales.ru` или `getent hosts again8.ru` на сервере.

## Порядок переноса после смены DNS

1. **Сертификат.** На сервере:
   `certbot certonly --webroot -w /var/www/maintenance -d again8.ru -d www.again8.ru`
2. **nginx.** Заменить заглушку боевым конфигом: сохранить текущий файл вне `sites-enabled/`,
   скопировать `again8.ru.https.draft` в `/etc/nginx/sites-enabled/again8.ru`, затем
   `nginx -t && nginx -s reload`. Бэкапы конфигов держать вне `sites-enabled/`.
3. **Laravel `.env`** (после копии `.env`):
   - `APP_URL=https://again8.ru`, `FRONTEND_URL=https://again8.ru`;
   - `LEGACY_HOSTS` — добавить `againdev3.ru` к текущим значениям;
   - `YANDEX_PAY_CALLBACK_URL=https://again8.ru` (без суффикса `/v1/webhook`);
   - `UTM_COOKIE_SECURE=true`, `SESSION_DOMAIN` и `UTM_TRACKING_BASE_URL` не задавать;
   - на время перехода можно добавить `CORS_EXTRA_ORIGINS=https://againdev3.ru`;
   - затем `php artisan optimize:clear` и `pm2 restart laravel-queue laravel-scheduler laravel-reverb`.
   Вебсокеты (`REVERB_HOST=127.0.0.1`) менять не нужно.
4. **Вебхуки и ссылки в базе.**
   `php artisan integrations:sync-webhooks --check`, затем `php artisan integrations:sync-webhooks`
   (Telegram, MAX, VK, СДЭК). Далее `php artisan urls:canonicalize --dry-run -v`, затем без `--dry-run`.
5. **Витрина** (`/var/www/html/nuxt-shop/.env`): `API_URL=https://again8.ru/api`; пересобрать
   (`npm ci`, сборка, `pm2 restart nuxt-shop`).
6. **Админка** (`/var/www/html/vue-admin/.env`): `VUE_APP_BASE_URL=https://again8.ru/api`,
   `VUE_APP_API_BASE_URL=https://again8.ru`, `VUE_APP_REVERB_HOST=again8.ru`; пересобрать
   (`yarn install --frozen-lockfile`, сборка).
7. **Ключ Яндекс.Карт.** В кабинете Яндекс.Карт у ключа с JavaScript API в «Ограничение по HTTP Referer»
   добавить `again8.ru` (сейчас стоит только `againdev3.ru`), иначе карта ПВЗ на новом домене не загрузится.
   Для геокодера бэкенда нужен отдельный ключ без Referer-ограничения (Key #1) или с ограничением по IP сервера.
8. **Кабинеты платёжных и других сервисов.**
   - CloudPayments: адреса уведомлений Check, Pay, Fail, Refund вида
     `https://again8.ru/api/webhooks/cloudpayments/<check|pay|fail|refund>` (метод POST, UTF-8).
     Уведомления Confirm в системе нет. Двойной слэш и опечатки в адресах недопустимы.
   - Яндекс Пэй: Callback URL `https://again8.ru`.
   - Яндекс.Метрика: домен счётчика.
   - Мессенджеры подхватятся шагом 4.
9. **Проверка.** `/up`, главная, `/admin/`, `/checkout`, `/api/public/delivery/methods`; в админке
   карта ПВЗ; вебхуки СДЭК, Telegram, MAX, VK; тестовый платёж на малую сумму (заказ должен стать
   «Оплачен»); письмо клиенту со ссылкой на `again8.ru`.

## Откат

Домен `againdev3.ru` остаётся рабочим (его конфиг и сертификат не меняются). Чтобы вернуться:
вернуть значения `APP_URL`, `FRONTEND_URL` и прочие в `.env` из копии, пересобрать витрину и админку,
повторить `integrations:sync-webhooks`, вернуть заглушку из `again8.ru.stub.before`.

## Не сделано

Перенос не начат: ждём смены DNS. Все шаги выше требуют вашего подтверждения перед выполнением,
потому что они меняют боевой домен, вебхуки и ссылки в базе.
