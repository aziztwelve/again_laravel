// Запуск: node --test whatsapp-service/
const test = require('node:test');
const assert = require('node:assert');
const http = require('node:http');
const { Readable } = require('node:stream');
const { fetchMedia, postMediaWebhook, isSystemMessage } = require('./media');

function startServer(handler) {
  return new Promise((resolve) => {
    const server = http.createServer(handler);
    server.listen(0, '127.0.0.1', () => resolve({ server, url: `http://127.0.0.1:${server.address().port}/hook` }));
  });
}

test('fetchMedia: сообщение без вложения', async () => {
  assert.deepStrictEqual(await fetchMedia({ hasMedia: false }), {});
});

test('fetchMedia: голосовое сообщение скачивается в буфер', async () => {
  const bytes = Buffer.from('OggS-voice');
  const result = await fetchMedia({
    hasMedia: true,
    downloadMedia: async () => ({ mimetype: 'audio/ogg; codecs=opus', data: bytes.toString('base64') }),
  });
  assert.strictEqual(result.media.mimetype, 'audio/ogg; codecs=opus');
  assert.strictEqual(result.media.filename, null);
  assert.ok(result.media.buffer.equals(bytes));
});

test('fetchMedia: WhatsApp не отдал файл', async () => {
  assert.deepStrictEqual(await fetchMedia({ hasMedia: true, downloadMedia: async () => undefined }), { error: 'download_failed' });
});

test('fetchMedia: ошибка и таймаут скачивания не роняют обработку', async () => {
  assert.deepStrictEqual(
    await fetchMedia({ hasMedia: true, downloadMedia: async () => { throw new Error('boom'); } }),
    { error: 'download_error' },
  );
  assert.deepStrictEqual(
    await fetchMedia({ hasMedia: true, downloadMedia: () => new Promise(() => {}) }, { timeoutMs: 20 }),
    { error: 'download_timeout' },
  );
});

test('fetchMedia: слишком большой файл не пересылается', async () => {
  const big = Buffer.alloc(51 * 1024 * 1024).toString('base64');
  assert.deepStrictEqual(
    await fetchMedia({ hasMedia: true, downloadMedia: async () => ({ mimetype: 'video/mp4', data: big }) }),
    { error: 'too_large' },
  );
});

test('postMediaWebhook: поля и файл уходят одним multipart-запросом', async () => {
  let received = null;
  const { server, url } = await startServer(async (req, res) => {
    const form = await new Response(Readable.toWeb(req), { headers: req.headers }).formData();
    const file = form.get('media');
    received = {
      phone: form.get('phone_number'), text: form.get('message_text'), type: form.get('media_type'),
      skipped: form.has('sender_phone'), fileName: file.name, fileType: file.type, bytes: Buffer.from(await file.arrayBuffer()),
    };
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify({ success: true }));
  });

  try {
    const media = { buffer: Buffer.from('JPEGDATA'), mimetype: 'image/jpeg', filename: null };
    const body = await postMediaWebhook(url, { phone_number: '7999@c.us', message_text: 'подпись', sender_phone: null }, media, 'image');
    assert.deepStrictEqual(body, { success: true });
    assert.strictEqual(received.phone, '7999@c.us');
    assert.strictEqual(received.text, 'подпись');
    assert.strictEqual(received.type, 'image');
    assert.strictEqual(received.skipped, false, 'null-поля не отправляются');
    assert.strictEqual(received.fileName, 'attachment');
    assert.strictEqual(received.fileType, 'image/jpeg');
    assert.ok(received.bytes.equals(Buffer.from('JPEGDATA')));
  } finally {
    server.close();
  }
});

test('postMediaWebhook: ответ Laravel не 2xx — ошибка', async () => {
  const { server, url } = await startServer((req, res) => {
    req.resume();
    res.statusCode = 400;
    res.end(JSON.stringify({ error: 'bad' }));
  });
  try {
    await assert.rejects(
      postMediaWebhook(url, { phone_number: 'x' }, { buffer: Buffer.from('a'), mimetype: 'image/png', filename: 'a.png' }, 'image'),
      /HTTP 400: bad/,
    );
  } finally {
    server.close();
  }
});

test('isSystemMessage: служебные сообщения пропускаются, настоящие нет', () => {
  for (const type of ['e2e_notification', 'notification_template', 'gp2', 'call_log', 'protocol']) {
    assert.strictEqual(isSystemMessage({ type }), true, type);
  }
  for (const type of ['chat', 'image', 'video', 'ptt', 'audio', 'document', 'sticker', 'location', 'ciphertext', 'unknown']) {
    assert.strictEqual(isSystemMessage({ type }), false, type);
  }
  assert.strictEqual(isSystemMessage(null), false);
});
