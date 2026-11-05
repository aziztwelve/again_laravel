# Перенос основного домена на again8.ru

Документ подготовлен 2026-11-03, статус обновлён там же по результатам проверки сервера
(`186.246.14.59`). Основной домен — `again8.ru`; `againdev3.ru` остаётся рабочим на время перехода
и будет выведен. Общие правила смены домена — в `docs/deploy-runbook.md`, раздел «Смена домена —
что обязательно сделать».

## Текущее состояние (проверено 2026-11-03)

Шаги 1–6 ниже выполнены, сайт, API, вебсокеты и вебхуки работают на `again8.ru`.

| Шаг | Статус |
|---|---|
| 1. Сертификат `again8.ru` (до 2027-02-01) | сделано |
| 2. nginx: боевой конфиг вместо заглушки | сделано |
| 3. Laravel `.env` | сделано, кроме `UTM_COOKIE_SECURE=true` (не задан) |
| 4. Вебхуки Telegram, MAX, VK, СДЭК | MAX, VK, СДЭК на `again8.ru`; Telegram см. ниже |
| 4. `urls:canonicalize --dry-run` | к изменению 0 |
| 5. Витрина: `.env` + пересборка | сделано |
| 6. Админка: `.env` + пересборка | сделано |
| 7. Ключ Яндекс.Карт (referer `again8.ru`) | не проверено, делается в кабинете |
| 8. Кабинеты CloudPayments, Яндекс Пэй, Метрика | не проверено, делается в кабинетах |
| 9. Полная проверка (платёж, письма, карта ПВЗ) | не выполнена |

Что ещё осталось:

- **Telegram.** В `integrations:sync-webhooks --check` у бота `again8help_bot` указана ошибка
  `Connection timed out` (`pending 0`). Адрес вебхука уже `again8.ru`; ошибка, вероятно, от
  DNS-кешей Telegram. Перепроверить позже тем же `--check`.
- **`UTM_COOKIE_SECURE=true`** в `.env` на сервере не задан (см. шаг 3).
- **Basic Auth витрины.** `again_front/server/middleware/basic-auth.ts` включает защиту только для
  хоста `againdev3.ru`, поэтому на `again8.ru` витрина открыта без пароля. Решить, так ли
  это задумано.
- **Старые домены в коде и локальных `.env`**: `again_front/.env.example`, комментарии в
  `config/utm.php`, тесты `AbandonedCartTest` и `tests/manual/.env.local`, смоук-тест
  `again_front/tests/browser/product-reviews-auth-smoke.mjs`. На работу сервера не влияют.
- **DNS.** После смены записей публичные резолверы (Google, Cloudflare) отдавали `186.246.14.59`,
  а часть кешей — прежний `185.65.148.57` (InSales, за Qrator). Подтверждение вебхуков внешними
  сервисами может какое-то время ходить на старый адрес.

Резервные копии конфигов: `/root/backups/domain-switch/` на сервере.

## Порядок переноса (шаги 1–6 выполнены)

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

Шаги 7–9 (кабинеты Яндекс.Карт, CloudPayments, Яндекс Пэй, Метрика; тестовый платёж и итоговая
проверка), задача по Telegram и пункты из списка «Что ещё осталось» выше. Эти шаги меняют боевой
домен, платёжные кабинеты и данные, поэтому выполняются только после подтверждения.
