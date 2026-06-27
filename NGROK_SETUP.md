# Max Integration с Ngrok - Быстрая настройка

## ✅ Текущая конфигурация

```env
MAX_BOT_TOKEN=f9LHodD0cOI8cooyaeiVpWI387M-cTn8HBqGLma7AV0fJmBhHaVHFh1UlJx195KYjIkNZt3z3AWng_Jnczwq
MAX_WEBHOOK_URL=https://7759-62-89-208-147.ngrok-free.app
```

Ngrok URL: `https://7759-62-89-208-147.ngrok-free.app`  
Webhook endpoint: `https://7759-62-89-208-147.ngrok-free.app/api/max/webhook`

---

## 🚀 Быстрый старт

### 1. Запустить базу данных

```bash
# Если используете Docker
docker-compose up -d mysql

# Или системный MySQL
sudo systemctl start mysql
```

### 2. Запустить миграции

```bash
php artisan migrate
```

### 3. Зарегистрировать webhook

**Вариант A - Автоматический скрипт:**

```bash
./register-max-webhook.sh YOUR_ADMIN_TOKEN
```

**Вариант B - Вручную через cURL:**

```bash
curl -X POST http://localhost/api/third-party-integrations/max/settings \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "bot_token": "f9LHodD0cOI8cooyaeiVpWI387M-cTn8HBqGLma7AV0fJmBhHaVHFh1UlJx195KYjIkNZt3z3AWng_Jnczwq",
    "is_active": true
  }'
```

### 4. Проверить регистрацию

```bash
curl http://localhost/api/third-party-integrations/max/webhook/subscriptions \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"
```

### 5. Тестирование

1. Отправьте сообщение боту в Max Messenger
2. Проверьте логи: `tail -f storage/logs/laravel.log | grep Max`
3. Откройте ngrok dashboard: http://127.0.0.1:4040
4. Проверьте Conversations в админке

---

## 🔄 При перезапуске ngrok

Когда ngrok перезапускается, URL меняется. Нужно:

1. Обновить `MAX_WEBHOOK_URL` в `.env`
2. Перерегистрировать webhook:
   ```bash
   ./register-max-webhook.sh YOUR_ADMIN_TOKEN
   ```

---

## 🔍 Отладка

### Проверить доступность endpoint

```bash
curl -I https://7759-62-89-208-147.ngrok-free.app/api/max/webhook
```

Должен вернуть `405 Method Not Allowed` - это нормально.

### Просмотр логов Laravel

```bash
# Все логи Max
tail -f storage/logs/laravel.log | grep Max

# Только ошибки
tail -f storage/logs/laravel.log | grep "MaxService: Exception"

# Входящие webhook
tail -f storage/logs/laravel.log | grep "Max webhook received"
```

### Ngrok Web Interface

Откройте http://127.0.0.1:4040 для просмотра всех HTTP запросов в реальном времени.

### Проверка webhook подписок

```bash
curl http://localhost/api/third-party-integrations/max/webhook/subscriptions \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" | jq
```

---

## 📝 Checklist

- [ ] База данных запущена
- [ ] Миграции выполнены
- [ ] Ngrok запущен
- [ ] Webhook зарегистрирован
- [ ] Отправлено тестовое сообщение
- [ ] Сообщение появилось в Conversations

---

## ⚠️ Важные замечания

1. **Бесплатный ngrok меняет URL** при каждом перезапуске
2. **Для постоянного URL** нужна платная версия ngrok
3. **Webhook нужно перерегистрировать** после смены URL
4. **Ngrok dashboard** (http://127.0.0.1:4040) показывает все запросы

---

## 💡 Полезные команды

```bash
# Проверить routes
php artisan route:list --path=max

# Очистить кэш
php artisan config:clear && php artisan route:clear

# Проверить настройки Max
php artisan tinker
>>> App\Models\MaxSettings::first()

# Удалить webhook
curl -X POST http://localhost/api/third-party-integrations/max/webhook/unregister \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"

# Перерегистрировать webhook
curl -X POST http://localhost/api/third-party-integrations/max/webhook/reregister \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"
```

---

## 📚 Документация

- **MAX_INTEGRATION_README.md** - Полное руководство
- **MAX_INTEGRATION_FRONTEND_DOCS.md** - Для фронтенда
- **MAX_INTEGRATION_QUICK_REFERENCE.md** - Быстрая справка

---

**Дата создания:** 2026-04-09  
**Ngrok URL:** https://7759-62-89-208-147.ngrok-free.app  
**Статус:** Ready for testing
