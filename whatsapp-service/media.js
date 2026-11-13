// Вложения WhatsApp: скачать из входящего сообщения и переслать в Laravel файлом.
// Вынесено из index.js, чтобы проверять без подключения к WhatsApp (media.test.js).

const MAX_MEDIA_BYTES = 50 * 1024 * 1024; // как на стороне Laravel (media: max:51200)

function withTimeout(promise, ms) {
  let timer;
  const timeout = new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('timeout')), ms); });
  return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

// Скачивает вложение входящего сообщения.
// Возвращает { media } с файлом, { error } с коротким кодом причины, либо {} если вложения нет.
async function fetchMedia(message, { timeoutMs = 60000 } = {}) {
  if (!message || !message.hasMedia) return {};

  try {
    const downloaded = await withTimeout(message.downloadMedia(), timeoutMs);
    if (!downloaded || !downloaded.data) return { error: 'download_failed' };

    const buffer = Buffer.from(downloaded.data, 'base64');
    if (buffer.length > MAX_MEDIA_BYTES) return { error: 'too_large' };

    return {
      media: {
        buffer,
        mimetype: downloaded.mimetype || 'application/octet-stream',
        filename: downloaded.filename || null,
      },
    };
  } catch (err) {
    return { error: err && err.message === 'timeout' ? 'download_timeout' : 'download_error' };
  }
}

// Отправляет сообщение с вложением в Laravel как multipart: поля сообщения + файл `media`.
// Бросает ошибку, если Laravel ответил не 2xx.
async function postMediaWebhook(url, fields, media, mediaType, { timeoutMs = 120000 } = {}) {
  const form = new FormData();
  for (const [key, value] of Object.entries(fields)) {
    if (value !== null && value !== undefined) form.append(key, String(value));
  }
  if (mediaType) form.append('media_type', String(mediaType));
  form.append(
    'media',
    new Blob([media.buffer], { type: media.mimetype }),
    media.filename || 'attachment',
  );

  const response = await fetch(url, {
    method: 'POST',
    body: form,
    headers: { Accept: 'application/json' },
    signal: AbortSignal.timeout(timeoutMs),
  });

  const text = await response.text();
  let body = null;
  try { body = JSON.parse(text); } catch (e) { /* ответ не JSON */ }

  if (!response.ok) {
    throw new Error(`Laravel webhook HTTP ${response.status}: ${(body && body.error) || text.slice(0, 120)}`);
  }
  return body;
}

// Служебные сообщения WhatsApp (шифрование чата, уведомления, звонки, события групп): это не
// переписка с клиентом, в диалоге они превращались в пустые заглушки. Намеренно НЕ пропускаем
// 'ciphertext' (сообщение ещё не расшифровано) и 'unknown': это может быть настоящее сообщение.
const SYSTEM_MESSAGE_TYPES = new Set([
  'e2e_notification', 'notification_template', 'notification', 'gp2', 'group_notification',
  'protocol', 'call_log', 'broadcast_notification', 'debug',
]);

function isSystemMessage(message) {
  return Boolean(message) && SYSTEM_MESSAGE_TYPES.has(message.type);
}

module.exports = { fetchMedia, postMediaWebhook, isSystemMessage, SYSTEM_MESSAGE_TYPES, MAX_MEDIA_BYTES };
