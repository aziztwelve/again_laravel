# WhatsApp

Диалоги с WhatsApp идут через отдельный сервис `whatsapp-service` (Node.js, библиотека
whatsapp-web.js, процесс pm2 `whatsapp-service`): он держит сессию WhatsApp Web и обменивается с
Laravel по HTTP. Секреты (сессия, прокси) в документацию не записываем.

## Входящие сообщения

`whatsapp-service` → `POST /api/public/whatsapp/webhook` (`WhatsAppWebhookController`).

- Текст приходит JSON-ом. Сообщение без текста и без вложения сохраняется с пометкой.
- **Вложения (фото, голосовые, видео, стикеры, файлы)** сервис скачивает сразу при получении и
  присылает в том же запросе файлом (`multipart/form-data`, поле `media`, тип сообщения в
  `media_type`). Подпись к файлу попадает в текст сообщения. Файл сохраняет `WhatsAppMediaStore`
  в `chat-attachments/ГГГГ/ММ`, тип (image / video / audio / file) определяется по MIME содержимого.
  Голосовые приходят как Ogg/Opus: Chrome их проигрывает, Safari — нет.
- Если скачать файл не удалось (`media_error`: `download_failed`, `download_timeout`,
  `download_error`, `too_large`), оператор видит пометку «Не удалось получить вложение из WhatsApp».
- Лимит файла — 50 МБ (`media: max:51200` в Laravel, `MAX_MEDIA_BYTES` в сервисе).

## Исходящие сообщения

`WhatsAppAdapter::sendMessage` → сервис:

- только текст — `POST /send-message`;
- с вложениями — `POST /send-media` на каждый файл (содержимое в base64, до 50 МБ): фото, видео и
  аудио уходят как медиа, документы — файлом. Текст идёт подписью к первому файлу; если он длиннее
  1000 символов, отправляется отдельным сообщением перед файлами.
- Голосовым сообщением (с волной) файл не отправляется: аудио уходит обычным аудиофайлом.

Админка принимает до 5 файлов (jpg, jpeg, png, mp3, wav, ogg, m4a, mp4) до 10 МБ каждый.

## Проверка

```bash
node --test whatsapp-service/media.test.js        # приём вложений в сервисе
php artisan test --filter=WhatsApp                # Laravel: приём и отправка
```

Живую проверку делают с телефона: отправить на подключённый номер фото, голосовое и видео и
открыть диалог в «Диалоги → WhatsApp».
