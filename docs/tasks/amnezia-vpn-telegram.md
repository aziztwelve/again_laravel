# Amnezia VPN для Telegram в админ-панели

## Контекст

Telegram API может быть недоступен с основного production-сервера. Нужно
поднять отдельный иностранный сервер и направлять через него только Telegram
трафик админки и backend-задач.

Новый сервер:

- SSH: `root@85.159.228.227`
- OS после первичной проверки: Ubuntu 24.04 LTS, x86_64
- Назначение: self-hosted Amnezia VPN / SOCKS5 egress для Telegram

Фактическая проверка `2026-07-08`:

```text
os: Ubuntu 24.04.4 LTS
arch: x86_64
kernel: 6.8.0-111-generic
systemd: yes
memory_mb: 961
disk: 9.8G total, 6.4G free, 32% used
ipv4: 85.159.228.227/24
virt: kvm
```

Сервер соответствует минимальным требованиям Amnezia. RAM и диск находятся на
нижней границе, поэтому не стоит размещать на нём ничего кроме VPN/proxy.

Текущий production:

- Laravel: `/var/www/html/laravel` на `186.246.14.59`
- Dashboard: `/var/www/html/vue-admin`
- Публичный домен: `https://sub.againdev.ru`

## Цель

В админке добавить раздел:

`Интеграции -> VPN Amnezia`

Раздел должен позволять:

- включить/выключить использование proxy для Telegram;
- сохранить SOCKS5 host/port/login/password;
- проверить доступность proxy;
- проверить внешний IP через proxy;
- проверить доступность Telegram API через proxy;
- видеть последний статус проверки.

## Архитектура

Нельзя направлять весь production-сервер через VPN. Это может сломать входящий
трафик nginx, SSH, webhook-и, платежи, доставки и другие интеграции.

Целевая схема:

```text
Laravel Telegram HTTP client
  -> SOCKS5 proxy на 85.159.228.227
  -> Telegram API
```

Через proxy идут только исходящие HTTP-запросы к Telegram API. Остальной backend
трафик работает как раньше.

## Amnezia-сервер

Amnezia устанавливается и управляется официальным AmneziaVPN client по SSH.
Админка не должна хранить root-пароль от VPN-сервера и не должна выполнять
произвольные SSH-команды на нём.

Порядок:

1. В AmneziaVPN client добавить self-hosted server `85.159.228.227`.
2. Подключиться как `root` по SSH.
3. Установить AmneziaWG.
4. Настроить UDP-порт до `9999`, например `585` или `1234`.
5. Установить дополнительный сервис `SOCKS5 Proxy Server`.
6. Скопировать SOCKS5 host, port, username, password.
7. Ограничить firewall так, чтобы SOCKS5 был доступен только с production IP
   `186.246.14.59`, если Amnezia/iptables позволяют сделать это без поломки.

## Хранение настроек

Настройки приложения хранятся в таблице `settings`, группа `amnezia_vpn`.

Ключи:

- `enabled` bool
- `host` string, по умолчанию `85.159.228.227`
- `port` int
- `username` string nullable
- `password` encrypted string nullable
- `scheme` string, по умолчанию `socks5h`
- `last_check` object nullable

Пароль возвращается в API только как флаг `has_password`. Если в форме пароль
не передан, старое значение не перезаписывается.

## Backend API

Роуты внутри admin API:

```text
GET   /api/third-party-integrations/amnezia-vpn
PATCH /api/third-party-integrations/amnezia-vpn
POST  /api/third-party-integrations/amnezia-vpn/test
```

Ответ `GET`:

```json
{
  "success": true,
  "data": {
    "enabled": true,
    "scheme": "socks5h",
    "host": "85.159.228.227",
    "port": 1080,
    "username": "user",
    "has_password": true,
    "last_check": {}
  }
}
```

`test` выполняет:

- `https://api.ipify.org?format=json` через proxy;
- `https://api.telegram.org` через proxy.

При наличии Telegram bot token можно дополнительно проверять
`getMe`, но базовый VPN-test не должен зависеть от настроенного бота.

## Интеграция Telegram

Все backend-запросы к Telegram API должны проходить через единый сервис/клиент,
который применяет proxy только если `amnezia_vpn.enabled = true`.

Минимальный первый шаг:

- использовать proxy при подключении Telegram webhook в
  `ChatsIntegrationController::telegram_integration`;
- вынести создание Telegram HTTP client в сервис;
- добавить тестовый endpoint для проверки proxy.

Следующий шаг:

- пройти по всем прямым `Http::get/post` к `api.telegram.org` и заменить на
  общий клиент.

## Dashboard UI

Страница `Интеграции -> VPN Amnezia`:

- компактная форма настроек;
- switch `Использовать для Telegram`;
- поля `host`, `port`, `username`, `password`;
- бейджи состояния;
- кнопки `Сохранить`, `Проверить`;
- блок результата: external IP, Telegram HTTP status, message, checked at.

В меню интеграций добавить отдельный пункт `VPN Amnezia`.

## Деплой

Деплой выполнять по `docs/deploy-runbook.md`:

1. push всех трёх проектов;
2. clean-check на сервере;
3. `git pull --ff-only` всех трёх проектов;
4. backend: `composer install`, `php artisan migrate --force`,
   `php artisan optimize:clear`, restart `laravel-queue`, `laravel-scheduler`,
   `laravel-reverb`;
5. пересборка `nuxt-shop`;
6. пересборка `vue-admin`;
7. smoke.

## Smoke-проверка

На production:

```bash
curl -s -o /dev/null -w "laravel /up -> %{http_code}\n" https://sub.againdev.ru/up -k
```

Через backend API:

- `GET /api/third-party-integrations/amnezia-vpn`
- `POST /api/third-party-integrations/amnezia-vpn/test`

Ожидаем:

- proxy reachable;
- external IP = IP VPN-сервера или его egress IP;
- Telegram endpoint отвечает не сетевой ошибкой;
- Telegram webhook подключается через proxy.

## Риски

- Не хранить SSH root-пароль от VPN-сервера в приложении.
- Не включать system-wide VPN на production.
- Не открывать SOCKS5 на весь интернет без firewall.
- Не логировать proxy password.
- При утечке SOCKS5 credentials сменить пароль в Amnezia и обновить настройки.
