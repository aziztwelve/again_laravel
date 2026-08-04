# Задача: привязка переписки из мессенджеров к клиенту и заказу по deeplink-токену

**Статус:** Задеплоено и проверено на dev (`againdev3.ru`) — 2026-07-12.
Осталось: живой смоук через реальные боты (написать в TG/MAX/VK из виджета),
автотесты, проброс `order_id` в сообщения входящих.
**Дата:** 2026-07-12
**Раздел витрины:** виджет онлайн-чата (иконки VK / Telegram / MAX) — `again_front`
**Бэкенд:** `lara_admin`
**Раздел админки:** Заказы → карточка заказа → «Чат»; Диалоги — `again_dashboard`
**Связанная задача:** `docs/tasks/order-card-chats.md` (уведомления TG/MAX/Email +
отображение переписки по заказу). Текущая задача закрывает то, от чего та задача
осознанно отказалась (решение #4 «привязка по клиенту») — вводит **точную
привязку через токен**, оставляя матчинг по клиенту как fallback.

---

## Описание (ТЗ)

Референс — магазин на InSales (`again8.ru`). В виджете онлайн-чата есть иконки
мессенджеров со ссылками-диплинками, в которых зашит **токен сессии/клиента**:

```html
<a href="https://vk.me/public228837691?ref=c3abce363bcdb365af9c6f59:ru">VK</a>
<a href="tg://resolve?domain=again8help_bot&start=c3abce363bcdb365af9c6f59:ru">Telegram</a>
<a href="https://max.ru/id4707052811_1_bot?start=c3abce363bcdb365af9c6f59:ru">MAX</a>
```

Когда клиент переходит по ссылке и пишет боту, мессенджер передаёт этот токен в
первом апдейте (`/start <TOKEN>` в Telegram/MAX, `ref`/`payload` в VK). Бэкенд по
токену **определяет, что это за клиент и заказ**, и автоматически привязывает
переписку к клиенту (и заказу). Оператор в админке видит цельную переписку,
связанную с конкретным человеком/заказом, независимо от канала.

**Наша цель:** реализовать такой же механизм у себя.

**Факт на сейчас:** механизма нет. Диплинки в виджете — статический хардкод без
токена; входящие вебхуки токен не читают; привязка к клиенту делается только по
заранее сохранённым `telegram_user_id` / `max_user_id` / `vk_user_id` или ручным
вводом email в Telegram-боте. Привязки к заказу нет.

---

## Что реально в коде сейчас (проверено 2026-07-12)

### Генерация диплинков

- **Единственная генерация** deeplink-ссылки — Telegram, в письме с чеком заказа:
  `app/Http/Controllers/Api/Admin/OrderController.php:894-900` —
  `tg://resolve?domain=again8help_bot&start={$order->view_token}`.
  Токен = `order->view_token` (генерируется в
  `OrderCreationService::generateViewToken()` и `OrderImportService`).
- Ссылок с токеном для **MAX** (`max.ru/...?start=`) и **VK** (`vk.me/...?ref=`)
  на бэкенде **нет**.
- В витрине (`again_front/features/LiveChat/components/Chatsociallinks.vue`) все
  ссылки мессенджеров — **хардкод без токена**. Дополнительно: Telegram-бот там
  указан `againChilla_bot` (везде остальное — `again8help_bot`), VK-сообщество
  отличается от шапки/футера. WhatsApp из виджета **убираем** (см. решение #4).
  Заготовки `constants.ts` (`SOCIAL_SOURCES`) и типы `SourceLink`/`ChatSource`
  не используются.

### Чтение токена на входе (главный разрыв)

- **Telegram:** `app/Telegraph/Handlers/TelegramWebhookHandler.php:39` —
  `public function start()` объявлен **без параметра**. Пакет Telegraph парсит
  `/start <TOKEN>` и вызывает `$this->start($parameter)`
  (`vendor/defstudio/telegraph/src/Handlers/WebhookHandler.php:268-276`), но
  токен **игнорируется**. Вместо этого `start()` → `user_profile(true)`
  (строки 46, 67-83): ищет `UserProfile` по `telegram_user_id`, а если не нашёл —
  просит **ввести email вручную**.
- **MAX:** `app/Services/Max/MaxService.php:741-747` — `handleBotStarted()` =
  заглушка `// TODO: Implement bot started logic if needed`. Именно тут должен
  разбираться `?start=<TOKEN>`.
- **VK:** `VKService::handleMessageNew()` (`app/Services/Vk/VKService.php:82-143`)
  не читает `ref`/`payload` первого сообщения.

### Привязка клиента и заказа (факт)

- Диалог: `Conversation` (`source`, `external_id`, `client_id`, `assigned_to`,
  `status`, `last_message_at`, `unread_messages_count`). **Поля `order_id` НЕТ.**
  Уникальный индекс `(source, external_id)` (`2026_04_26_052709`).
- Идентичность мессенджера ↔ клиент: `user_profiles.telegram_user_id` /
  `telegram_chat_id` / `vk_user_id` / `max_user_id`.
- Привязка к клиенту в webhook'ах возможна только если эти id **уже сохранены**.
  Кода, который заполнял бы их из первого контакта (через токен), нет.
- Привязка к заказу отсутствует. В карточке заказа чат строится по клиенту:
  `ConversationController::byClient` (`ConversationController.php:113`) — прямые
  диалоги по `client_id` + хрупкий матчинг «анонимных» по email (точно) и по
  хвосту телефона (`external_id LIKE %tail%`). Для MAX/VK `external_id` — это
  `user_id`, **а не телефон**, поэтому по телефону такой диалог не найдётся
  никогда.

---

## Принятые решения (утверждены 2026-07-12)

1. **Основной механизм привязки — deeplink-токен** (`start`/`ref`), по образцу
   InSales. Токен кодирует и клиента, и (опционально) заказ.
2. **Fallback — существующий матчинг по клиенту** (email/телефон/профильные id)
   остаётся для случаев, когда токена нет (клиент написал боту напрямую, не из
   виджета). Токен имеет приоритет.
3. **`order_id` несёт токен и пишется в сообщения**, а не в `conversations`.
   Поле `conversations.order_id` **не добавляем** (миграции нет). Конкретную
   отбивку/ветку связываем с заказом через `messages.source_data.order_id`
   (или новое поле `messages.order_id` — см. «Открытые вопросы»). Это гибко для
   гостей и клиентов с несколькими заказами.
4. **WhatsApp из задачи и из виджета убираем.** По ТЗ переписка: Live-чат,
   Telegram, MAX, VK. Диплинки строим только для **Telegram, MAX, VK**. Кнопку
   WhatsApp из `Chatsociallinks.vue` удаляем, канал в справочнике не задействуем.

---

## Предлагаемая архитектура

Ключевая идея: ввести **токен привязки чата** — короткоживущий идентификатор,
который витрина зашивает в диплинки, а бэкенд разбирает при первом входящем
сообщении и по нему проставляет `client_id` диалогу (+ `order_id` сообщениям).

### 1. Хранилище токенов привязки

Новая сущность `ChatBindingToken` (таблица `chat_binding_tokens`) — токен
связывает сессию витрины с клиентом/заказом:

| Поле | Назначение |
|---|
| `token` | случайная строка (напр. 24 hex-символа), уникально; идёт в `start`/`ref` |
| `client_id` | nullable FK — клиент, если авторизован на витрине |
| `order_id` | nullable FK — заказ, если токен создан из контекста заказа |
| `external_id` | `external_id` веб-чата витрины (localStorage), чтобы склеить с web_chat-диалогом |
| `expires_at` | TTL (напр. 24–72 ч), протухшие игнорируются |
| `used_at` | отметка первого использования (не инвалидируем сразу — клиент может писать не раз) |

Альтернатива без миграции — хранить в кэше (`cache()->put("chat_bind:$token", ...)`),
но БД предпочтительнее: аналитика, переживает рестарт, TTL контролируемый.
**Формат токена:** без разделителя `:ru` из InSales (это их локаль) — наш токен
самодостаточен; локаль/язык не нужны.

### 2. Эндпоинт выдачи токена + ссылок для витрины

Публичный эндпоинт (напр. `GET /api/public/chat/messenger-links`):
- принимает `external_id` (веб-чат) и опционально Bearer-токен клиента и/или
  `order_token` (view_token заказа);
- создаёт/возвращает `ChatBindingToken`, связанный с `client_id`/`order_id`/`external_id`;
- возвращает **готовые диплинки** для всех каналов с зашитым токеном:
  ```json
  {
    "token": "c3abce363bcdb365af9c6f59",
    "links": {
      "telegram": "tg://resolve?domain=againdev_test_bot&start=c3abce...",
      "max": "https://max.ru/id4707052811_1_bot?start=c3abce...",
      "vk": "https://vk.me/public228837691?ref=c3abce..."
    }
  }
  ```
- имена ботов/сообществ берём из конфига интеграций (МAX/VK/Telegram settings),
  **не хардкодим** на витрине (P: сейчас хардкод и рассинхрон ботов).

### 2a. Как строятся диплинки (формат по каналам)

Общий принцип: у каждого мессенджера есть свой способ передать «стартовый
параметр» боту/сообществу при переходе по ссылке. Наш `token` из
`ChatBindingToken` подставляется в этот параметр. При первом сообщении мессенджер
пришлёт токен в вебхуке — по нему делаем `resolveBinding` (п.3).

**Telegram** — deeplink на бота с параметром `start`:
```
https://t.me/<bot_username>?start=<TOKEN>
tg://resolve?domain=<bot_username>&start=<TOKEN>   // нативная схема (открывает приложение)
```
- `bot_username` = `againdev_test_bot` (вынесено в
  `config/services.php` → `messenger_deeplinks.telegram_bot`, env
  `TELEGRAM_BOT_USERNAME`; `OrderController.php` уже берёт из конфига).
- Ограничения `start`: только `A-Z a-z 0-9 _ -`, до **64 символов**. Наш токен
  (hex) под это подходит. Если понадобится кодировать больше данных — использовать
  `startapp` или base64url, но нам достаточно короткого id-ссылки на БД.
- Бот получит апдейт `/start <TOKEN>` → Telegraph вызовет `start($parameter)`.

**MAX** — deeplink на бота с параметром `start` (аналогично Telegram):
```
https://max.ru/id4707052811_bot?start=<TOKEN>
```
- Публичное имя нашего MAX-бота: **`id4707052811_bot`**
  (`https://max.ru/id4707052811_bot`). В `MaxSettings` хранится только
  `bot_token`, поэтому имя бота нужно вынести в конфиг/настройки (поле
  `bot_public_name` в `MaxSettings` или `config/services.php` → `max`), чтобы
  `buildLinks` брал его оттуда, а не хардкодил.
- При старте бот получает событие `bot_started` с полем deeplink-параметра →
  обрабатываем в `MaxService::handleBotStarted()` (сейчас заглушка).

**VK** — ссылка на диалог с сообществом с параметром `ref`:
```
https://vk.me/<screen_name>?ref=<TOKEN>
https://vk.com/im?sel=-<community_id>&ref=<TOKEN>
```
- `community_id` хранится в `VKSettings.community_id`; `screen_name` (если задан)
  красивее, но `public<community_id>` тоже работает.
- **Важно про VK:** `ref` (и `ref_source`) надёжно приходит в `message_new`
  **только для первого сообщения** пользователя, который раньше не писал
  сообществу. Значение `ref` ограничено (буквы/цифры/`_`/`-`, до ~64 символов) —
  наш hex-токен подходит. Если пользователь уже писал сообществу, `ref` может не
  прийти → уходим в fallback-матчинг по клиенту.

**Итог по эндпоинту:** бэкенд формирует все три ссылки в
`ChatBindingService::buildLinks($token)`, подставляя токен и имена ботов/сообществ
из настроек интеграций. Витрина только отображает то, что вернул API.

### 3. Разбор токена во входящих вебхуках

- **Telegram** — `TelegramWebhookHandler::start(string $parameter = null)`:
  принять параметр (Telegraph его уже передаёт), если это валидный токен —
  `resolveBinding($parameter)`; сохранить `telegram_user_id`/`telegram_chat_id` в
  `user_profiles` найденного клиента; привязать/создать `Conversation` с
  `client_id`. Оставить текущий сценарий ручного email как fallback при отсутствии
  токена.
- **MAX** — реализовать `MaxService::handleBotStarted()` (сейчас TODO):
  распарсить `start`-payload, `resolveBinding`, сохранить `max_user_id` в профиль,
  привязать диалог.
- **VK** — в `VKService::handleMessageNew()` читать `ref`/`payload` первого
  сообщения (VK передаёт `ref` при переходе по `vk.me/...?ref=`), `resolveBinding`,
  сохранить `vk_user_id`, привязать.
- **Единый сервис** `ChatBindingService::resolveBinding(string $token, string $source, string $externalId): ?Client`
  — находит токен, достаёт `client_id`/`order_id`, сохраняет messenger-id в
  профиль, дозаполняет `Conversation.client_id`, возвращает клиента. Идемпотентно.

### 4. Привязка заказа к сообщениям

При наличии `order_id` в токене — первое (и последующие в рамках сессии)
входящее сообщение помечать `order_id` через `messages.source_data.order_id`
(без новой FK) либо новым nullable-полем `messages.order_id`. Тогда в карточке
заказа можно показать имено сообщения по этому заказу, не привязывая весь диалог.
Согласуется с решением #3 связанной задачи (зеркалирование отбивок с `order_id`).

### 5. Витрина (again_front)

- `Chatsociallinks.vue`: убрать хардкод, запрашивать ссылки через новый эндпоинт
  (п.2), передавая `external_id` из `useChatFunctions().getExternalIdClient()` и
  (если залогинен) client-контекст. Починить бота (`againdev_test_bot`),
  **удалить кнопку WhatsApp**, синхронизировать VK-сообщество.
- Задействовать `constants.ts`/типы вместо дублирующего хардкода в шаблоне.
- Добавить `max` в типы `source` (`useChatApi.ts`, `types/chat.ts`) — сейчас его
  нет, хотя канал используется.

---

## Затрагиваемые сущности (с путями)

**Бэкенд `lara_admin`:**
- Новое: `app/Models/ChatBindingToken.php`,
  `database/migrations/xxxx_create_chat_binding_tokens_table.php`,
  `app/Services/Messaging/ChatBindingService.php`, публичный контроллер выдачи
  ссылок (`app/Http/Controllers/Api/Public/Chat/...`), роут в `routes/api.php`
  (блок `/public`, рядом со строками 199-207).
- Правки чтения токена:
  `app/Telegraph/Handlers/TelegramWebhookHandler.php:39` (`start($parameter)`),
  `app/Services/Max/MaxService.php:741` (`handleBotStarted`),
  `app/Services/Vk/VKService.php:82` (`handleMessageNew` — читать `ref`/`payload`).
- Генерация ссылок вместо хардкода: заменить одиночную строку
  `OrderController.php:894-900` вызовом сервиса; имена ботов — из настроек
  интеграций (`MaxSettings`, `VKSettings`, Telegraph bot).
- Модели: `Conversation.php`, `Message.php` (возможно `order_id`/`source_data`),
  `UserProfile.php` (сохранение messenger-id), `Order.php` (`view_token`).
- Fallback-матчинг: `Api/Admin/ConversationController.php:113` (`byClient`) —
  без изменений логики (остаётся как fallback), но токенная привязка снижает
  зависимость от хрупкого матчинга по телефону.

**Витрина `again_front`:**
- `features/LiveChat/components/Chatsociallinks.vue` (хардкод ссылок, боты),
  `features/LiveChat/composables/useChatApi.ts` + `useChatFunctions.ts`
  (запрос ссылок, `external_id`), `features/LiveChat/constants.ts`,
  `features/LiveChat/types/chat.ts` (добавить `max`).

**Дашборд `again_dashboard`:** прямых изменений не требует; выигрывает от более
точной привязки (диалоги корректно связаны с клиентом/заказом). См.
`order-card-chats.md` по отображению.

---

## Открытые вопросы

1. **`messages.order_id` — новое поле или `source_data`?** Рекомендация:
   `source_data.order_id` (без миграции), если не нужен индекс/выборка по заказу
   на уровне БД; иначе — nullable FK `messages.order_id`.
2. **TTL токена** и поведение при протухании: игнорировать молча и уводить в
   fallback-матчинг, или перевыдавать. Рекомендация: 72 ч, при протухании —
   fallback.
3. **VK `ref`:** подтвердить, что текущая конфигурация сообщества/бота отдаёт
   `ref` в `message_new` (зависит от типа перехода `vk.me` vs виджет). Если VK не
   передаёт `ref` надёжно — для VK опираться на fallback-матчинг.
4. **Один токен на сессию или на заказ:** для сценария «чат по конкретному
   заказу» токен создаётся с `order_id`; для общего чата с витрины — без него.
   Витрина решает, какой контекст передать.
5. **Гость без client_id:** токен может нести только `order_id`/`external_id`
   (гостевой заказ). Привязка к `Client` произойдёт позже (при регистрации/входе)
   штатным мержем — согласовать с логикой `CartMerger`/гостевого чекаута.
6. **Публичное имя MAX-бота вынести в настройки.** Имя известно —
   `id4707052811_bot`. В `MaxSettings` сейчас только `bot_token`; добавить поле
   `bot_public_name` (или задать в `config/services.php` → `max`) со значением
   `id4707052811_bot`, чтобы `buildLinks` строил `max.ru/id4707052811_bot?start=`
   из настроек, а не из хардкода.
7. **Telegram bot_username вынесен в конфиг** (сделано): `againdev_test_bot` в
   `config/services.php` → `messenger_deeplinks.telegram_bot`. `buildLinks` и
   `OrderController` берут оттуда; на витрине рассинхрон (`againChilla_bot`)
   устраняется в шаге по витрине.

---

## План реализации

1. Миграция + модель `ChatBindingToken`; `ChatBindingService`
   (`createToken`, `resolveBinding`, `buildLinks`).
2. Публичный эндпоинт выдачи токена и диплинков; имена ботов/сообществ — из
   настроек интеграций (убрать хардкод).
3. Витрина: `Chatsociallinks.vue` запрашивает ссылки, чинит бота/VK, удаляет
   WhatsApp, задействует `constants.ts`/типы, добавляет `max` в `source`.
4. Чтение токена во входящих: Telegram (`start($parameter)`), MAX
   (`handleBotStarted`), VK (`ref`/`payload`). Сохранение messenger-id в
   `user_profiles`, привязка `Conversation.client_id`. Fallback по клиенту
   сохраняется.
5. Привязка заказа к сообщениям (`source_data.order_id` или `messages.order_id`).
6. Тесты (`tests/Feature/...`): переход по каждому каналу с валидным токеном →
   диалог привязан к нужному клиенту/заказу; messenger-id сохранён в профиль;
   протухший/невалидный токен → fallback без падения; идемпотентность (повторные
   сообщения не плодят диалоги/дубли); гостевой заказ (токен без client_id).
7. Смоук на dev: из виджета витрины перейти в Telegram/MAX/VK, написать боту,
   убедиться, что переписка привязалась к клиенту/заказу и видна в карточке
   заказа в админке.

---

## Статус реализации (2026-07-12)

Сделано:
- **Конфиг имён ботов** — `config/services.php` → `messenger_deeplinks`
  (`telegram_bot=againdev_test_bot`, `max_bot=id4707052811_bot`, `vk_screen_name`).
- **Смена TG-бота на `againdev_test_bot`** во всех местах витрины
  (`success`, `orders/[token]`, `contacts`, `Header/Socials`, `Footer/Socials`,
  `LiveChat/Chatsociallinks`) и в `OrderController` (теперь из конфига).
- **Миграция + модель** `chat_binding_tokens` / `App\Models\ChatBindingToken`.
- **`App\Services\Messaging\ChatBindingService`** — `createToken` (переиспользует
  живой токен сессии по `external_id`), `buildLinks` (TG/MAX/VK), `resolveBinding`
  (сохраняет messenger-id в `user_profiles`, дозаполняет `Conversation.client_id`),
  `resolveOrderId`.
- **Публичный эндпоинт** `GET /api/public/chat/messenger-links`
  (`Api\Public\Chat\ChatBindingController`) — отдаёт токен + 3 ссылки, клиент по
  Bearer, заказ по `order_token` (view_token).
- **Чтение токена во входящих:** Telegram (`TelegramWebhookHandler::start($parameter)`),
  MAX (`MaxService::handleBotStarted` — payload/start_payload), VK
  (`VKService::handleMessageNew` — `ref`/`payload`). Заодно исправлен баг VK
  `firstOrCreate` (client_id убран из атрибутов поиска, добавлено дозаполнение).
- **Витрина:** `Chatsociallinks.vue` тянет ссылки через
  `useGetMessengerLinks` (`useChatApi.ts`) в `onMounted`, с fallback-ссылками;
  **кнопка WhatsApp удалена**; бот исправлен на `againdev_test_bot`.

Проверено: `php -l` (без ошибок), `route:list` (эндпоинт зарегистрирован),
DI всех сервисов резолвится (`artisan tinker`), Pint на новых файлах — PASS.

Задеплоено и проверено на dev (`againdev3.ru`, 2026-07-12):
- Миграция `chat_binding_tokens` применена (`migrate:status` pending=0).
- Эндпоинт `GET /api/public/chat/messenger-links` отдаёт токен + 3 ссылки с
  верными ботами: `t.me/againdev_test_bot?start=`, `max.ru/id4707052811_bot?start=`,
  `vk.me/public221071922?ref=` (community_id из `VKSettings` сервера).
- Идемпотентность: повторный запрос с тем же `external_id` → тот же токен, одна
  строка в БД.
- `order_token` → токен привязан к `order_id`; `resolveBinding` находит клиента
  заказа, сохраняет messenger-id в `user_profiles`, `resolveOrderId` возвращает
  заказ. Тестовые данные после проверки откачены.
- Витрина пересобрана: в бандле есть вызов `chat/messenger-links`, TG-бот
  `againdev_test_bot`; `again8help_bot`/`againChilla_bot`/`wa.me` из виджета убраны.

Осталось:
- Живой смоук через реальные боты: из виджета витрины перейти в Telegram/MAX/VK,
  написать боту, убедиться, что переписка привязалась к клиенту/заказу и видна в
  карточке заказа в админке. Проверить, что мессенджеры реально присылают
  `start`/`ref` в первом апдейте (особенно VK `ref`).
- Автотесты (`tests/Feature/...`) — п.6 плана.
- Опционально: пометка `messages.source_data.order_id` при первом входящем по
  токену с заказом (сейчас `resolveOrderId` есть, но в запись сообщения ещё не
  проброшен — точка расширения в адаптерах входящих сообщений).
