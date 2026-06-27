#!/bin/bash

# Max Webhook Registration Script
# Использование: ./register-max-webhook.sh [ADMIN_TOKEN]

echo "╔══════════════════════════════════════════════════════════════════════════╗"
echo "║              MAX WEBHOOK REGISTRATION SCRIPT                             ║"
echo "╚══════════════════════════════════════════════════════════════════════════╝"
echo ""

# Загружаем переменные из .env
if [ -f .env ]; then
    export $(cat .env | grep -E "^MAX_BOT_TOKEN=" | xargs)
    export $(cat .env | grep -E "^MAX_WEBHOOK_URL=" | xargs)
else
    echo "❌ Файл .env не найден!"
    exit 1
fi

# Проверяем наличие токена бота
if [ -z "$MAX_BOT_TOKEN" ]; then
    echo "❌ MAX_BOT_TOKEN не найден в .env"
    exit 1
fi

# Проверяем наличие webhook URL
if [ -z "$MAX_WEBHOOK_URL" ]; then
    echo "❌ MAX_WEBHOOK_URL не найден в .env"
    exit 1
fi

# Получаем admin token из аргумента или запрашиваем
if [ -z "$1" ]; then
    echo "📝 Введите Admin Token (Bearer token для API):"
    read -r ADMIN_TOKEN
else
    ADMIN_TOKEN="$1"
fi

if [ -z "$ADMIN_TOKEN" ]; then
    echo "❌ Admin token не указан!"
    exit 1
fi

echo ""
echo "📋 Конфигурация:"
echo "   Bot Token: ${MAX_BOT_TOKEN:0:20}..."
echo "   Webhook URL: $MAX_WEBHOOK_URL"
echo "   Full Webhook: $MAX_WEBHOOK_URL/api/max/webhook"
echo ""

# Регистрируем webhook
echo "🚀 Регистрация webhook..."
echo ""

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST http://localhost/api/third-party-integrations/max/settings \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{
    \"bot_token\": \"$MAX_BOT_TOKEN\",
    \"is_active\": true
  }")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | sed '$d')

echo "📊 Response Code: $HTTP_CODE"
echo ""

if [ "$HTTP_CODE" = "200" ]; then
    echo "✅ Webhook успешно зарегистрирован!"
    echo ""
    echo "📄 Response:"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "✨ Готово! Теперь можете отправить сообщение боту для тестирования."
    echo ""
    echo "🔍 Для проверки логов:"
    echo "   tail -f storage/logs/laravel.log | grep Max"
    echo ""
    echo "🌐 Для просмотра запросов ngrok:"
    echo "   http://127.0.0.1:4040"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
else
    echo "❌ Ошибка при регистрации webhook!"
    echo ""
    echo "📄 Response:"
    echo "$BODY" | jq '.' 2>/dev/null || echo "$BODY"
    echo ""
    echo "💡 Возможные причины:"
    echo "   • Неверный admin token"
    echo "   • База данных не запущена"
    echo "   • Миграции не выполнены (php artisan migrate)"
    echo "   • Laravel приложение не запущено"
    exit 1
fi
