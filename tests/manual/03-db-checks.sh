#!/usr/bin/env bash
# Сверяет в БД артефакты, оставленные 02-run.sh:
#   - гостевые заказы за сегодня (S1, S2, S3)
#   - связанные order_addresses (recipient_*)
#   - promo_code_usages с client_id IS NULL (артефакт S3)
#
# Запускать после ./02-run.sh.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$LARAVEL_DIR"

echo "→ Гостевые заказы за сегодня (client_id IS NULL):"
php artisan tinker --no-interaction --execute='
use App\Models\Order;
$rows = Order::query()
    ->whereNull("client_id")
    ->whereDate("created_at", today())
    ->orderByDesc("id")
    ->limit(20)
    ->get(["id","order_number","client_id","email","total_amount","view_token","created_at"]);
foreach ($rows as $r) {
    echo sprintf("  #%-6d %-22s email=%-30s total=%-10s view=%s%s\n",
        $r->id, $r->order_number, $r->email ?? "—",
        (string)$r->total_amount, substr($r->view_token ?? "", 0, 8) . "…",
        ""
    );
}
echo "  total: " . $rows->count() . PHP_EOL;
'

echo ""
echo "→ Связанные order_addresses (recipient_*):"
php artisan tinker --no-interaction --execute='
use App\Models\Order;
use App\Models\OrderAddress;
$orderIds = Order::query()
    ->whereNull("client_id")
    ->whereDate("created_at", today())
    ->pluck("id");
$rows = OrderAddress::query()
    ->whereIn("order_id", $orderIds)
    ->orderByDesc("id")
    ->limit(20)
    ->get(["order_id","recipient_first_name","recipient_last_name","recipient_phone","city","address"]);
foreach ($rows as $r) {
    echo sprintf("  order=#%-6d name=%-20s phone=%-15s city=%s\n",
        $r->order_id,
        trim(($r->recipient_first_name ?? "") . " " . ($r->recipient_last_name ?? "")),
        $r->recipient_phone ?? "—",
        $r->city ?? "—"
    );
}
echo "  total: " . $rows->count() . PHP_EOL;
'

echo ""
echo "→ promo_code_usages с client_id IS NULL (артефакт S3):"
php artisan tinker --no-interaction --execute='
use App\Models\PromoCodeUsage;
$rows = PromoCodeUsage::query()
    ->whereNull("client_id")
    ->orderByDesc("id")
    ->limit(20)
    ->get(["id","promo_code_id","client_id","order_id","discount_amount"]);
foreach ($rows as $r) {
    echo sprintf("  usage=#%-5d promo=#%-4d order=#%-6d discount=%s\n",
        $r->id, $r->promo_code_id, $r->order_id, $r->discount_amount
    );
}
echo "  total: " . $rows->count() . PHP_EOL;
'

echo ""
echo "→ Заказы клиента из .env.local за сегодня (для C-сценариев):"
if [[ -f "$SCRIPT_DIR/.env.local" ]]; then
  # shellcheck disable=SC1090
  source "$SCRIPT_DIR/.env.local"
  if [[ -n "${CLIENT_ID:-}" ]]; then
    php artisan tinker --no-interaction --execute="
use App\Models\Order;
\$rows = Order::query()
    ->where(\"client_id\", $CLIENT_ID)
    ->whereDate(\"created_at\", today())
    ->orderByDesc(\"id\")
    ->limit(10)
    ->get([\"id\",\"order_number\",\"total_amount\",\"discount_amount\",\"promo_code_id\"]);
foreach (\$rows as \$r) {
    echo sprintf(\"  #%-6d %-22s total=%-8s discount=%-8s promo=%s\n\",
        \$r->id, \$r->order_number, (string)\$r->total_amount,
        (string)\$r->discount_amount, \$r->promo_code_id ?? \"—\"
    );
}
echo \"  total: \" . \$rows->count() . PHP_EOL;
"
  fi
fi
