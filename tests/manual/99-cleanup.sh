#!/usr/bin/env bash
# Удаляет тестовые заказы, созданные 02-run.sh:
#   - гостевые заказы с email guest+%/promo+%/%@example.com
#   - заказы клиента из .env.local, созданные сегодня, с пометкой
#     по адресу (recipient_first_name='Тест') — это узкий фильтр,
#     чтобы случайно не удалить реальные заказы клиента
#   - personal_access_tokens с name='manual-test'
#
# По умолчанию работает в dry-run. Реально удаляет только с флагом
# --force. Запрещён на APP_ENV=production.

set -u

FORCE=0
for arg in "$@"; do
  case "$arg" in
    --force|-f) FORCE=1 ;;
    -h|--help)
      echo "Использование: $0 [--force]"
      echo "  без --force — dry-run (только показывает что удалит)"
      echo "  с --force   — реально удаляет"
      exit 0
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.local"
cd "$LARAVEL_DIR"

# Guard: production
APP_ENV_VAL="$(grep -E '^APP_ENV=' .env 2>/dev/null | head -1 | cut -d= -f2 | tr -d '"' || true)"
if [[ "$APP_ENV_VAL" == "production" ]]; then
  echo "ERROR: APP_ENV=production. Отказываюсь чистить заказы на проде." >&2
  exit 1
fi

CLIENT_ID_VAL=""
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  CLIENT_ID_VAL="${CLIENT_ID:-}"
fi

mode="DRY-RUN"
(( FORCE == 1 )) && mode="DELETE"
echo "→ Режим: $mode (APP_ENV=$APP_ENV_VAL)"
echo "→ CLIENT_ID для очистки клиентских тест-заказов: ${CLIENT_ID_VAL:-—}"
echo ""

php artisan tinker --no-interaction --execute='
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderAddress;
use App\Models\OrderHistory;
use App\Models\PromoCodeUsage;
use App\Models\GiftCard\GiftCard;
use App\Models\GiftCard\GiftCardTransaction;

$force = '"$FORCE"';
$clientId = '"${CLIENT_ID_VAL:-0}"';

// 1. Гостевые тестовые заказы — по email-маске.
$guestIds = Order::query()
    ->whereNull("client_id")
    ->where(function ($q) {
        $q->where("email", "like", "guest+%@example.com")
          ->orWhere("email", "like", "promo+%@example.com")
          ->orWhere("email", "like", "%@example.com");
    })
    ->pluck("id");

echo "Гостевые тест-заказы: " . $guestIds->count() . " (" . $guestIds->implode(",") . ")" . PHP_EOL;

// 2. Клиентские тест-заказы за сегодня с recipient_first_name "Тест"
//    и last_name начинается на "Клиент" — это специфично для 02-run.sh.
$clientIds = collect();
if ($clientId > 0) {
    $clientIds = Order::query()
        ->where("client_id", $clientId)
        ->whereDate("created_at", today())
        ->whereHas("address", function ($q) {
            $q->where("recipient_first_name", "Тест")
              ->where("recipient_last_name", "like", "Клиент%");
        })
        ->pluck("id");
}
echo "Клиентские тест-заказы (client #{$clientId}, сегодня): " . $clientIds->count() . " (" . $clientIds->implode(",") . ")" . PHP_EOL;

$allIds = $guestIds->merge($clientIds)->unique()->values();
echo "Всего к удалению: " . $allIds->count() . PHP_EOL;

// Подсчитываем связанные gift_cards (артефакт G1/G2/G3) — отдельно
// от заказов, потому что они также чистят jobs-очередь.
$giftIds = collect();
if (! $allIds->isEmpty()) {
    $giftIds = GiftCard::whereIn("purchase_order_id", $allIds)->pluck("id");
    echo "Связанные gift_cards: " . $giftIds->count() . " (" . $giftIds->implode(",") . ")" . PHP_EOL;
}

if (! $force) {
    echo PHP_EOL . "(dry-run, ничего не удалено. Повтори с --force чтобы реально удалить.)" . PHP_EOL;
    return;
}

if ($allIds->isEmpty()) {
    echo "Нечего удалять." . PHP_EOL;
} else {
    // gift-card transactions → gift_cards → promo_code_usages → items → addresses → history → orders
    if ($giftIds->isNotEmpty()) {
        GiftCardTransaction::whereIn("gift_card_id", $giftIds)->delete();
        $deletedGifts = GiftCard::whereIn("id", $giftIds)->delete();
        echo "Удалено gift_cards: $deletedGifts" . PHP_EOL;

        // Заодно — pending SendGiftCardJob-ы для этих карт.
        // Идентифицируем по сериализованному payload (там лежит giftCardId).
        $queueDeleted = 0;
        $candidates = DB::table("jobs")
            ->where("payload","like","%SendGiftCardJob%")
            ->get(["id","payload"]);
        foreach ($candidates as $j) {
            $payload = json_decode($j->payload, true);
            $cmd = $payload["data"]["command"] ?? "";
            if (preg_match("/giftCardId[\";:i]+(\d+)/", $cmd, $m)
                || preg_match("/gift_card_id[\";:i]+(\d+)/", $cmd, $m)) {
                if ($giftIds->contains((int) $m[1])) {
                    DB::table("jobs")->where("id", $j->id)->delete();
                    $queueDeleted++;
                }
            }
        }
        echo "Удалено SendGiftCardJob из очереди: $queueDeleted" . PHP_EOL;
    }

    PromoCodeUsage::whereIn("order_id", $allIds)->delete();
    OrderItem::whereIn("order_id", $allIds)->delete();
    OrderAddress::whereIn("order_id", $allIds)->delete();
    OrderHistory::whereIn("order_id", $allIds)->delete();
    $deleted = Order::whereIn("id", $allIds)->forceDelete();
    echo "Удалено заказов: $deleted" . PHP_EOL;
}

// 3. Sanctum-токены с именем manual-test (созданы 01-prepare.sh).
$tokensDeleted = DB::table("personal_access_tokens")
    ->where("name", "manual-test")
    ->delete();
echo "Удалено токенов manual-test: $tokensDeleted" . PHP_EOL;
'

if (( FORCE == 0 )); then
  echo ""
  echo "Это был dry-run. Чтобы реально удалить — повтори:  $0 --force"
fi
