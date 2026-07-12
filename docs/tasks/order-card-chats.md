# Задача: привести в порядок чаты в карточке заказа + уведомления о событиях

**Статус:** Решения утверждены — в реализации
**Дата:** 2026-07-10 (решения согласованы 2026-07-10)
**Раздел админки:** Заказы → карточка заказа → кнопка «Чат» (`again_dashboard`)
**Бэкенд:** `lara_admin`
**Витрина:** `again_front` (live-чат)
**Связанная задача:** `docs/tasks/messenger-deeplink-binding.md` — привязка
переписки к клиенту/заказу через deeplink-токен (`start`/`ref`), по образцу
InSales. Она пересматривает решение #4 ниже: точная привязка теперь идёт по
токену, а матчинг по клиенту (email/телефон) остаётся как **fallback**.

---

## Описание (ТЗ)

Сценарий: **админка → заказ → Чат**.

Нужно две группы доработок:

1. **Транзакционные уведомления** — отбивать клиенту в **Telegram, MAX и Email**
   сообщения о событиях:
   - покупка товара (номер заказа + сумма);
   - оформление подарочной карты;
   - уведомление о доставке подарочной карты.
2. **Корректное отображение переписки по заказу** во всех каналах коммуникаций:
   Live-чат сайта, Telegram, MAX, VK.

**Факт на сейчас:** функционал частично есть, но работает некорректно.

### Эталонные тексты сообщений (из ТЗ)

Сообщение о доставке подарочной карты (покупателю):
```
Ваша подарочная карта успешно доставлена!
Получатель: Антон
Номинал: 10000.00 ₽
Код: D8JNWKA3K8DS
Доставлено: 19.02.2026 09:38
```

Сообщение о новом заказе (эталон из InSales-магазина, к которому приводим свой формат):
```
Новый заказ №35153
Магазин again8.ru
Клиент: Евгения (7(923)418-84-94)
Способ доставки: Электронная почта
Адрес доставки: Россия, г Тюмень, Тюменская обл.

Состав заказа:
- Подарочный сертификат (3000.00). 3 000 ₽ x 1 шт

Способ оплаты: Оплата картой РФ
Сумма: 3 000 ₽

Данный номер для связи с нашими покупателями, пожалуйста, не блокируйте его.
Если сообщение пришло к вам случайно, приносим извинения 🫶🏻
```

---

## Что реально в коде сейчас (проверено 2026-07-10)

### Две независимые подсистемы

В проекте есть **две параллельные и несвязанные** системы отправки сообщений —
это корень путаницы:

1. **Messaging / переписка (двусторонняя, с историей).**
   Модель `Conversation` (`app/Models/Conversation.php`) → `Message`
   (`app/Models/Message.php`) → `MessageAttachment`. Управляется
   `App\Services\Messaging\ConversationService` и адаптерами
   `app/Services/Messaging/Adapters/*` (`TelegramAdapter`, `MaxAdapter`,
   `VKAdapter`, `WhatsAppAdapter`, `EmailAdapter`). Входящие webhook'и
   (`MaxService`, `VKService`, `TelegramService`) создают `Conversation` +
   `Message(direction=incoming)`; ответ оператора идёт
   `ConversationService::addMessage(direction=outgoing)` → адаптер источника.
   Реалтайм: события `App\Events\MessageCreated`, `App\Events\ConversationUpdated`
   (Reverb).

2. **Notifications / односторонние уведомления.**
   `App\Services\Notifications\NotificationService` + каналы
   `app/Services/Notifications/Channels/*` (`Email`, `Telegram`, `WhatsApp`,
   `VK`, `WebChat`), доставка через `SendNotificationJob`. **Уведомления
   отправляются мимо `Conversation`/`Message`** — в истории переписки заказа их
   не видно.

### Модель данных чатов (факт)

- `conversations`: `source` enum, `external_id`, `client_id` (nullable FK),
  `assigned_to`, `status` (`new`/`active`/`closed`), `last_message_at`,
  `unread_messages_count`. Миграции постепенно расширяли `source`:
  базовые `telegram/whatsapp/web_chat`
  (`2024_xx_xx_create_conversations_table.php`), затем `vk`
  (`2025_11_01_111046`), `email` (`2025_11_11_144611`), `max`
  (`2026_04_09_140951`).
- **`conversations` НЕ имеет `order_id`.** Привязка «чат ↔ заказ» отсутствует;
  чат в карточке заказа строится **по клиенту** (`client_id`), а не по заказу.
- `messages`: `direction` (`incoming`/`outgoing`), `content` (nullable),
  `content_type`, `status`, `source_data` (json). Уникальный индекс
  `conversations(source, external_id)` (`2026_04_26_052709`).
- Идентификаторы клиента для каналов лежат в `user_profiles`:
  `telegram_user_id` / `telegram_chat_id` (`2025_05_11`, `2025_11_06`),
  `vk_user_id` (`2025_11_01_141343`), `max_user_id` (`2026_04_09_140932`).

### Как чат показывается в карточке заказа (факт)

- Дашборд: `again_dashboard/src/components/orders/view/partials/OrderChat.vue` —
  модалка «Чат с клиентом». Грузит диалоги через
  `useChatsFunctions().getConversationsByClient(clientId)` и рендерит вкладки по
  `source`. Кнопка задизейблена, если у заказа нет `clientId`.
- Бэк: `ConversationController::byClient(Client $client)`
  (`app/Http/Controllers/Api/Admin/ConversationController.php:113`). Собирает
  диалоги двумя путями: (1) прямые по `client_id`; (2) «анонимные»
  (`client_id IS NULL`) по совпадению `external_id` с email клиента
  (`source=email`) или с хвостом телефона (последние 10 цифр) для
  `whatsapp/vk/telegram/max`. Найденные анонимные **автопривязываются**
  (`UPDATE client_id`).

---

## Проблемы (почему «работает некорректно»)

### Уведомления

- **P1. Нет канала MAX в уведомлениях.** `NotificationService::registerChannels()`
  (`NotificationService.php:28`) регистрирует только `email/telegram/whatsapp/vk/web_chat`.
  **`MaxNotificationChannel` отсутствует** — отбить в MAX через
  `SendNotificationJob('max', ...)` сейчас невозможно (канал молча не найден,
  `sendViaChannel` вернёт `false` с warning).
- **P2. Уведомление о заказе не уходит в MAX и почти не уходит в Telegram.**
  `PublicCheckoutController::sendNotifications()`
  (`PublicCheckoutController.php:244`) шлёт только `email` (по `order->email`) и
  `telegram` — причём Telegram только если у клиента есть
  `profile->telegram_user_id`. MAX не отправляется вообще. Гостям (без client)
  идёт только email.
- **P3. Уведомления только для гостевого чекаута.** Отбивка о покупке висит в
  `PublicCheckoutController` (витрина). Заказы, созданные в **админке**
  (`OrderCreationService` / `Api/Admin/OrderController`), никаких уведомлений не
  шлют.
- **P4. Формат сообщения о заказе не соответствует ТЗ.** Сейчас:
  `"Ваш заказ #{id} принят! Сумма: {total} руб."` — нет магазина, клиента,
  способа доставки/оплаты, адреса и **состава заказа**. Нужен формат из эталона.
- **P5. Уведомления идут мимо истории переписки.** Отправленные через
  `NotificationService` сообщения **не создают `Message`**, поэтому в чате
  карточки заказа их не видно — оператор не понимает, что клиенту уже ушла
  отбивка.
- **P6. Подарочная карта — канал/термины рассинхронены.**
  `GiftCardDeliveryService::resolveChannel()` (`GiftCardDeliveryService.php:43`)
  поддерживает `email/whatsapp/sms`, а `SendGiftCardJob::isReadyToSend()`
  (`SendGiftCardJob.php:108`) проверяет `email/telegram/sms`. `sms`-канала в
  `NotificationService` нет вообще; MAX не поддержан. `sendDeliveryConfirmation()`
  шлёт покупателю только email + telegram (`GiftCardDeliveryService.php:123`).
- **P7. Текст подтверждения доставки почти совпадает с эталоном, но с лишней
  строкой** `Заказ #{id}` (`GiftCardDeliveryService.php:182`) — по ТЗ её быть не
  должно.

### Переписка / отображение

- **P8. Привязка только по клиенту, заказа не существует как якоря.** Если у
  заказа нет `client_id` (гость) — кнопка «Чат» задизейблена
  (`OrderChat.vue:13`), переписки не видно совсем, хотя гость мог писать в
  live-чат/мессенджер.
- **P9. Матчинг «анонимных» диалогов хрупкий.** `byClient` матчит email точным
  сравнением и телефон по `LIKE %хвост%` (`ConversationController.php:146`).
  Ложные срабатывания (чужой диалог с похожим хвостом номера) и промахи (формат
  `external_id` в MAX/VK — это `user_id`, а **не телефон** → по телефону не
  найдётся никогда).
- **P10. VK нет в исходящих адаптерах при некоторых конфигурациях, MAX — в
  уведомлениях.** `ConversationService` создаёт адаптеры в конструкторе через
  `try/catch` и молча зануляет недоступные (`ConversationService.php:33-59`);
  при отсутствии настроек ответ оператора в VK/MAX тихо не уходит.
- **P11. `source` рассогласован между слоями.** Диалоги используют `web_chat`,
  уведомления — тоже `web_chat`, но у `NotificationService` канал называется
  `web_chat`, а адаптеров переписки для `web_chat` нет (live-чат пишет `Message`
  напрямую, без исходящего адаптера). Нужен единый справочник каналов.

---

## Предлагаемая архитектура

Ключевая идея: **разделить, но связать** две подсистемы. Уведомления остаются
отдельным механизмом доставки, но **каждое отправленное клиенту транзакционное
сообщение зеркалируется в `Conversation`/`Message`** соответствующего канала —
тогда в карточке заказа видно всё: и переписку, и отбивки.

### 1. Единый справочник каналов

Ввести enum/конфиг `app/Enums/CommunicationChannel.php` (или `config/channels.php`)
с единым списком: `web_chat`, `telegram`, `max`, `vk`, `email`, `whatsapp`.
Использовать его и в `conversations.source`, и в `NotificationService`, и в
валидации контроллеров — убрать «магические строки», рассинхрон
`sms/telegram/email` (P6, P11).

### 2. Достроить канал MAX в уведомлениях (P1)

Добавить `app/Services/Notifications/Channels/MaxNotificationChannel.php`
(по образцу `VKNotificationChannel` — обёртка над `MaxAdapter`/`MaxService`),
зарегистрировать в `NotificationService::registerChannels()`. Recipient =
`user_profiles.max_user_id`.

### 3. Единый доменный сервис уведомлений о событиях

Ввести `app/Services/Notifications/OrderNotificationService.php` (или events +
listeners) с методами:
- `orderCreated(Order $order)` — формат из эталона ТЗ (магазин, клиент+телефон,
  способ доставки, адрес, **состав заказа построчно**, способ оплаты, сумма).
- `giftCardIssued(GiftCard $card)` — оформление карты.
- `giftCardDelivered(GiftCard $card)` — доставка карты (эталонный текст без
  строки `Заказ #`, P7).

Сервис:
- определяет доступные каналы клиента (email + все привязанные
  `telegram_user_id`/`max_user_id`/`vk_user_id`) и шлёт во **все** доступные
  (Telegram, MAX, Email — по ТЗ), а не только email/telegram (P2);
- дергается **из единой точки** после создания заказа — и в
  `PublicCheckoutController` (гость/витрина), и в
  `OrderCreationService`/`Api/Admin/OrderController` (админка), P3. Лучше через
  доменное событие `OrderCreated` + listener, чтобы не дублировать вызовы.

Форматирование текстов вынести в билдеры (`OrderMessageBuilder`,
`GiftCardMessageBuilder`), чтобы совпадали с эталоном (P4) и переиспользовались.

### 4. Зеркалирование уведомлений в переписку (P5)

После успешной отправки транзакционного уведомления по каналу, где у клиента
есть диалог (или можно его завести по `external_id`), писать `Message`
(`direction=outgoing`, `content_type=text`, `source_data.kind='notification'`) в
соответствующий `Conversation`. Тогда отбивки видны в чате карточки заказа.
Для каналов без диалога (email клиента без переписки) — решить: заводить
`Conversation(source=email)` или помечать флагом. **Открытый вопрос — см. ниже.**

### 5. Привязка переписки к заказу (P8, P9)

Варианты (нужно выбрать — см. открытые вопросы):
- **A. Оставить привязку по клиенту** (минимум изменений): усилить `byClient` —
  матчить мессенджеры по `user_profiles.max_user_id/vk_user_id/telegram_user_id`
  (а не по телефону в `external_id`), email — точно; для гостей матчить по
  `order->email`/`order->phone` даже при `client_id IS NULL`.
- **B. Добавить `conversations.order_id`** (nullable FK) и/или таблицу-связку
  `conversation_order` — привязывать диалог к конкретному заказу (напр., отбивки
  по заказу, ветка обсуждения заказа). Точнее для сценария «чат по заказу», но
  дороже: миграция + UI выбора заказа.

Рекомендация: **A сейчас** (чинит P8/P9 малой кровью) + `order_id` на `messages`
для транзакционных отбивок, чтобы связать конкретную отбивку с заказом без
жёсткой привязки всего диалога.

### 6. Надёжность исходящих адаптеров (P10)

Логировать причину недоступности адаптера при **отправке** (а не только в
конструкторе) и возвращать понятную ошибку в `ConversationController::reply`,
чтобы оператор видел «MAX не настроен», а не молчаливый неуспех.

---

## Затрагиваемые сущности (факт, с путями)

**Бэкенд `lara_admin`:**
- `app/Models/Conversation.php` (нет `order_id`), `Message.php`,
  `MessageAttachment.php`, `ConversationParticipant.php`.
- `database/migrations/2024_xx_xx_create_conversations_table.php` (+ миграции
  `add_vk/email/max_to_conversations_source`, `add_*_user_id_to_user_profiles`).
- `app/Services/Messaging/ConversationService.php` (создание/ответ/адаптеры),
  `AbstractMessageAdapter.php`, `Adapters/{Telegram,Max,VK,WhatsApp,Email}Adapter.php`.
- `app/Services/Notifications/NotificationService.php` (нет MAX-канала),
  `Channels/{Email,Telegram,WhatsApp,VK,WebChat}NotificationChannel.php`,
  `Jobs/SendNotificationJob.php`, `Contracts/NotificationChannelInterface.php`.
- `app/Http/Controllers/Api/Admin/ConversationController.php`
  (`byClient:113`, `index:32`, `reply:264`, `assign:442`, `close:432`).
- `app/Http/Controllers/Api/Public/Conversation/PublicConversationController.php`
  (live-чат: `getOrCreateForClient:33`, `reply:88`).
- Webhook'и: `.../Max/MaxWebhookController.php`, `.../Vk/VKWebhookController.php`,
  `.../Telegram/TelegramWebhookController.php`,
  `App\Services\{Max\MaxService, Vk\VKService, Telegram\TelegramService}`.
- Уведомления о заказе:
  `app/Http/Controllers/Api/Public/Order/PublicCheckoutController.php`
  (`sendNotifications:244`), `app/Services/Order/OrderCreationService.php`
  (сейчас не уведомляет), `Api/Admin/OrderController`.
- Подарочные карты: `app/Services/GiftCard/GiftCardDeliveryService.php`
  (`send:15`, `resolveChannel:43`, `sendDeliveryConfirmation:123`,
  `buildDeliveryConfirmationMessage:178`), `app/Jobs/GiftCard/SendGiftCardJob.php`
  (`isReadyToSend:97`), `app/Services/GiftCard/GiftCardService.php`.
- События: `app/Events/MessageCreated.php`, `ConversationUpdated.php`
  (Reverb, `routes/channels.php`).

**Дашборд `again_dashboard`:**
- `src/components/orders/view/partials/OrderChat.vue`,
  `src/components/orders/view/partials/OrderActions.vue`,
  `src/components/dialogs/chats/ChatWidget.vue`,
  `src/composables/useChatsFunctions.js`.

**Витрина `again_front`:**
- `features/LiveChat/**` (live-чат: `useChatApi.ts`,
  `usePublicConversationEvents.ts`, `stores/useLiveChatStore.ts`).

---

## Принятые решения (утверждены 2026-07-10)

1. **Каналы отбивок — во ВСЕ привязанные каналы клиента сразу** (Email +
   Telegram + MAX), где у клиента есть контакт/id. Соответствует ТЗ «в TG, MAX и
   Email». Отправка независимая: недоступность одного канала не блокирует
   остальные.
2. **Триггер «покупки товара» — создание заказа** (не оплата). Отбивка
   формируется в момент оформления заказа (эталон «Новый заказ №…»), из единой
   точки — и витрина, и админка.
3. **Подарочная карта — два события:**
   - **оформление** → уведомить **покупателя** (карта создана);
   - **доставка** → **получателю** сама карта + **покупателю** подтверждение
     доставки (эталонный текст).
4. **Привязка чата к заказу — Вариант A (по клиенту).** Без миграции
   `conversations.order_id`. Усиливаем `byClient`: матчинг мессенджеров по
   `user_profiles.max_user_id / vk_user_id / telegram_user_id`, email — точно,
   поддержка гостевых заказов по `order->email/phone`. `order_id` в БД не
   добавляем.
5. **Зеркалировать отбивки в переписку — да.** После успешной отправки
   транзакционного уведомления писать `Message(direction=outgoing,
   source_data.kind='notification')` в диалог соответствующего канала, чтобы
   оператор видел отбивку в чате карточки заказа. Для email-канала без диалога —
   заводить `Conversation(source=email)` по email клиента/заказа.
6. **Название магазина в тексте — хардкод `again8.ru`** (как в эталоне ТЗ).
7. **WhatsApp — вне текущей задачи.** В справочник каналов включаем для полноты,
   но отбивки и доработки переписки по WhatsApp в этой задаче не делаем
   (по ТЗ переписка: Live-чат, Telegram, MAX, VK). Существующее поведение
   WhatsApp не трогаем.

---

## План реализации

Решения #1–#7 утверждены, приступаем в этом порядке.

1. **Единый справочник каналов** `App\Enums\CommunicationChannel`
   (`web_chat`, `telegram`, `max`, `vk`, `email`, `whatsapp`), провести по слоям
   вместо магических строк (P11). WhatsApp в справочнике есть, но новых доработок
   по нему нет (решение #7).
2. **`MaxNotificationChannel`** (`app/Services/Notifications/Channels/`) +
   регистрация в `NotificationService::registerChannels()` (P1). Recipient =
   `user_profiles.max_user_id`.
3. **`OrderNotificationService` + билдеры текстов** (`OrderMessageBuilder`,
   `GiftCardMessageBuilder`) под эталонные форматы (P4), магазин `again8.ru`
   хардкодом (решение #6). Событие `OrderCreated` + listener, дергается при
   **создании** заказа из единой точки — витрина (`PublicCheckoutController`) и
   админка (`OrderCreationService`), решения #1, #2, P2, P3. Отбивка уходит во
   **все** привязанные каналы клиента (Email + Telegram + MAX), независимо.
4. **Подарочные карты — единый механизм** (P6, P7, решение #3): каналы
   `email/telegram/max`, убрать `sms`-рассинхрон между `resolveChannel` и
   `isReadyToSend`; событие «оформление» → покупателю; «доставка» → получателю +
   покупателю; текст доставки под эталон (без строки `Заказ #`).
5. **Зеркалирование отбивок в `Message`** (P5, решение #5): после успешной
   отправки писать `Message(direction=outgoing, source_data.kind='notification')`
   в диалог канала; для email без диалога — завести `Conversation(source=email)`.
6. **Починить `byClient`** (P8, P9, решение #4 — вариант A, без `order_id`):
   матчинг мессенджеров по `user_profiles.max_user_id/vk_user_id/telegram_user_id`
   вместо хвоста телефона; email — точно; поддержка гостевых заказов по
   `order->email/phone` при `client_id IS NULL`.
7. **Явные ошибки недоступных адаптеров** в `ConversationController::reply`
   (P10) — оператор видит «MAX/VK не настроен», а не молчаливый неуспех.
8. **Тесты** (`tests/Feature/...`): формат каждого сообщения соответствует
   эталону; отбивка уходит во все привязанные каналы; идемпотентность (одно
   событие — одна отбивка на канал); `byClient` собирает диалоги по клиенту и
   гостю без ложных матчей; зеркалирование пишет `Message`; события подарочной
   карты уведомляют нужных адресатов.
9. **Смоук на сервере (dev)**: создать заказ (гость и клиент) и подарочную карту,
   проверить приход в Email/TG/MAX и отображение в чате карточки заказа.
