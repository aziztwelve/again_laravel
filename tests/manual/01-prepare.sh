#!/usr/bin/env bash
# Собирает тестовые данные из локальной БД через `php artisan tinker`
# и кладёт их в tests/manual/.env.local для последующего использования
# раннером 02-run.sh.
#
# Запрещён на production (см. APP_ENV-guard ниже).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.local"

cd "$LARAVEL_DIR"

# ------------------------------------------------------------------
# Guard: не запускать на production. На проде скрипт может вызвать
# `syncWithoutDetaching` промокода к клиенту (если попросишь), а
# главное — создаёт persistent personal_access_token для теста.
# ------------------------------------------------------------------
APP_ENV_VAL="$(php -r 'echo getenv("APP_ENV") ?: (function_exists("env") ? env("APP_ENV") : "");' 2>/dev/null || true)"
if [[ -z "$APP_ENV_VAL" ]]; then
  APP_ENV_VAL="$(grep -E '^APP_ENV=' .env 2>/dev/null | head -1 | cut -d= -f2 | tr -d '"' || true)"
fi
if [[ "$APP_ENV_VAL" == "production" ]]; then
  echo "ERROR: APP_ENV=production. Отказываюсь готовить тестовые данные на проде." >&2
  exit 1
fi

# ------------------------------------------------------------------
# Базовый URL — берём APP_URL из .env, можно переопределить переменной
# окружения BASE_URL до запуска скрипта.
# ------------------------------------------------------------------
APP_URL_VAL="$(grep -E '^APP_URL=' .env 2>/dev/null | head -1 | cut -d= -f2 | tr -d '"' || true)"
BASE_URL="${BASE_URL:-$APP_URL_VAL}"
if [[ -z "$BASE_URL" ]]; then
  echo "ERROR: BASE_URL пустой. Задай BASE_URL=... в env или APP_URL в .env." >&2
  exit 1
fi

echo "→ Laravel:   $LARAVEL_DIR"
echo "→ BASE_URL:  $BASE_URL"
echo "→ APP_ENV:   ${APP_ENV_VAL:-?}"
echo ""

# ------------------------------------------------------------------
# Достаём из БД всё нужное одним вызовом tinker. Результат — KEY=VALUE
# строки, которые парсим в эти переменные.
# ------------------------------------------------------------------
echo "→ Собираю тестовые данные через php artisan tinker…"

TINKER_OUT=$(php artisan tinker --no-interaction --execute='
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Client;

// 1) активный товар без вариантов с ценой > 0 и положительным остатком
$product = Product::query()
    ->where("price", ">", 0)
    ->where(function ($q) {
        $q->where("has_variants", false)->orWhereNull("has_variants");
    })
    ->where(function ($q) {
        $q->where("stock_quantity", ">", 0)->orWhereNull("stock_quantity");
    })
    ->orderBy("id")
    ->first();

if ($product) {
    echo "PRODUCT_ID=" . $product->id . PHP_EOL;
    echo "PRODUCT_PRICE=" . number_format((float) $product->price, 2, ".", "") . PHP_EOL;
    echo "PRODUCT_STOCK=" . ($product->stock_quantity ?? "null") . PHP_EOL;
    echo "PRODUCT_NAME=" . preg_replace("/[^\\w\\sа-яё-]/iu", "", $product->name) . PHP_EOL;
} else {
    echo "PRODUCT_ID=" . PHP_EOL;
}

// 2) публичный активный промокод (applies_to_all_clients = true)
$public = PromoCode::query()
    ->where("is_active", true)
    ->where("applies_to_all_clients", true)
    ->orderBy("id")
    ->first();
echo "PUBLIC_PROMO_CODE=" . ($public->code ?? "") . PHP_EOL;

// 3) персональный активный промокод (applies_to_all_clients = false)
$personal = PromoCode::query()
    ->where("is_active", true)
    ->where("applies_to_all_clients", false)
    ->orderBy("id")
    ->first();
echo "PERSONAL_PROMO_CODE=" . ($personal->code ?? "") . PHP_EOL;

// 4) клиент + Sanctum-токен с предсказуемым именем (manual-test)
$client = Client::query()->whereNull("deleted_at")->orderBy("id")->first();
if ($client) {
    // выпиливаем старые тестовые токены этого имени, чтобы не плодились
    $client->tokens()->where("name", "manual-test")->delete();
    $token = $client->createToken("manual-test")->plainTextToken;
    echo "CLIENT_ID=" . $client->id . PHP_EOL;
    echo "CLIENT_EMAIL=" . ($client->email ?? "") . PHP_EOL;
    echo "CLIENT_TOKEN=" . $token . PHP_EOL;
} else {
    echo "CLIENT_ID=" . PHP_EOL;
}

// 5) персональный промокод, привязанный НЕ к этому клиенту,
//    нужен для C4 (PROMO_NOT_FOR_CLIENT). Может не быть — это ок.
if ($client && $personal) {
    $foreign = PromoCode::query()
        ->where("is_active", true)
        ->where("applies_to_all_clients", false)
        ->whereHas("clients", fn ($q) => $q->where("client_id", "!=", $client->id))
        ->whereDoesntHave("clients", fn ($q) => $q->where("client_id", $client->id))
        ->orderBy("id")
        ->first();
    echo "FOREIGN_PERSONAL_CODE=" . ($foreign->code ?? "") . PHP_EOL;
} else {
    echo "FOREIGN_PERSONAL_CODE=" . PHP_EOL;
}

// 6a) товар «Подарочный сертификат» с has_variants=1 + 2 любых активных
//     варианта (для G1/G2/G3 — гость покупает сертификат как товар).
//     В БД может быть несколько Product с этим именем; берём тот, у
//     которого есть варианты — так делает реальный фронт.
$giftProduct = Product::query()
    ->where("name", "Подарочный сертификат")
    ->where("has_variants", true)
    ->orderBy("id")
    ->first();

if ($giftProduct) {
    echo "GIFT_PRODUCT_ID=" . $giftProduct->id . PHP_EOL;
    $variants = \DB::table("product_variants")
        ->where("product_id", $giftProduct->id)
        ->where("is_active", true)
        ->whereNull("deleted_at")
        ->where(function ($q) { $q->where("stock_quantity", ">", 0)->orWhereNull("stock_quantity"); })
        ->orderBy("price")
        ->limit(2)
        ->get(["id","price"]);
    if ($variants->count() >= 1) {
        echo "GIFT_VARIANT_ID=" . $variants[0]->id . PHP_EOL;
        echo "GIFT_NOMINAL=" . number_format((float) $variants[0]->price, 2, ".", "") . PHP_EOL;
    }
    if ($variants->count() >= 2) {
        echo "GIFT_VARIANT_ID_2=" . $variants[1]->id . PHP_EOL;
        echo "GIFT_NOMINAL_2=" . number_format((float) $variants[1]->price, 2, ".", "") . PHP_EOL;
    }
} else {
    echo "GIFT_PRODUCT_ID=" . PHP_EOL;
}

// 6) для C3 нужен персональный промо, привязанный к нашему клиенту.
//    Если такого нет — НЕ привязываем автоматически (это побочный
//    эффект на чужие данные), а только сообщаем, что C3 будет skip.
if ($client && $personal) {
    $ownsPersonal = $personal->clients()->where("client_id", $client->id)->exists();
    echo "OWN_PERSONAL_CODE=" . ($ownsPersonal ? $personal->code : "") . PHP_EOL;
} else {
    echo "OWN_PERSONAL_CODE=" . PHP_EOL;
}
' 2>&1 | grep -E '^[A-Z_][A-Z0-9_]*=')

if [[ -z "$TINKER_OUT" ]]; then
  echo "ERROR: tinker не вернул данных. Проверь, что Laravel в рабочем состоянии (.env, БД)." >&2
  exit 1
fi

# ------------------------------------------------------------------
# Уточняем актуальную цену товара через публичный API каталога —
# именно эту цену видит фронт, и именно её бэк ожидает в payload.
# Если у товара активна скидка/акция, `products.price` в БД может
# отличаться. При расхождении заказ упадёт с PRICE_MISMATCH (422).
# Если API недоступен — оставляем DB-цену с предупреждением.
# ------------------------------------------------------------------
PRODUCT_ID_TMP=$(grep '^PRODUCT_ID=' <<< "$TINKER_OUT" | cut -d= -f2)
if [[ -n "$PRODUCT_ID_TMP" ]]; then
  CATALOG_URL="${BASE_URL%/}/api/public/catalog/products/$PRODUCT_ID_TMP"
  effective_price=$(curl -sk --max-time 10 "$CATALOG_URL" 2>/dev/null \
    | php -r '
        $j = stream_get_contents(STDIN);
        $d = json_decode($j, true) ?: [];
        $p = $d["product"]["price"] ?? $d["price"] ?? null;
        if ($p !== null && is_numeric($p)) {
            echo number_format((float) $p, 2, ".", "");
        }
      ' 2>/dev/null)

  if [[ -n "$effective_price" ]]; then
    # Подменяем PRODUCT_PRICE в выводе tinker
    TINKER_OUT=$(echo "$TINKER_OUT" | sed -E "s|^PRODUCT_PRICE=.*$|PRODUCT_PRICE=$effective_price|")
    echo "  catalog API: эффективная цена товара #${PRODUCT_ID_TMP} = ${effective_price}"
  else
    echo "  WARN: не удалось получить цену из $CATALOG_URL — оставляю цену из БД (возможен PRICE_MISMATCH)"
  fi
fi

# ------------------------------------------------------------------
# Пишем .env.local. Все значения экранируем в одинарных кавычках,
# чтобы случайные пробелы/спецсимволы не сломали парсинг в раннере.
# ------------------------------------------------------------------
{
  echo "# Сгенерировано 01-prepare.sh — не коммитить."
  echo "# APP_ENV=$APP_ENV_VAL"
  echo "BASE_URL='${BASE_URL%/}'"
  while IFS='=' read -r key val; do
    [[ -z "$key" ]] && continue
    # экранируем одиночные кавычки в значении
    safe="${val//\'/\'\\\'\'}"
    echo "${key}='${safe}'"
  done <<< "$TINKER_OUT"
} > "$ENV_FILE"

# ------------------------------------------------------------------
# Валидация — без чего раннер вообще не поедет.
# ------------------------------------------------------------------
# shellcheck disable=SC1090
source "$ENV_FILE"

missing=()
[[ -z "${PRODUCT_ID:-}" ]]    && missing+=("PRODUCT_ID (нужен активный товар с price>0 и stock>0)")
[[ -z "${PRODUCT_PRICE:-}" ]] && missing+=("PRODUCT_PRICE")
[[ -z "${CLIENT_ID:-}" ]]     && missing+=("CLIENT_ID (нет ни одного клиента в БД)")
[[ -z "${CLIENT_TOKEN:-}" ]]  && missing+=("CLIENT_TOKEN")

if (( ${#missing[@]} > 0 )); then
  echo ""
  echo "ERROR: не хватает обязательных тестовых данных:" >&2
  for m in "${missing[@]}"; do echo "  - $m" >&2; done
  exit 1
fi

warn=()
[[ -z "${PUBLIC_PROMO_CODE:-}" ]]    && warn+=("PUBLIC_PROMO_CODE → S3, C2, C5 будут SKIP")
[[ -z "${PERSONAL_PROMO_CODE:-}" ]]  && warn+=("PERSONAL_PROMO_CODE → S4 будет SKIP")
[[ -z "${OWN_PERSONAL_CODE:-}" ]]    && warn+=("OWN_PERSONAL_CODE → C3 будет SKIP (нужен персональный промо, привязанный к CLIENT_ID=$CLIENT_ID)")
[[ -z "${FOREIGN_PERSONAL_CODE:-}" ]] && warn+=("FOREIGN_PERSONAL_CODE → C4 будет SKIP")
[[ -z "${GIFT_PRODUCT_ID:-}" ]]      && warn+=("GIFT_PRODUCT_ID → G1/G2/G3 (гость + сертификат как товар) будут SKIP")
[[ -z "${GIFT_VARIANT_ID:-}" ]]      && warn+=("GIFT_VARIANT_ID → нет активных вариантов сертификата с положительным остатком")

echo ""
echo "✓ Тестовые данные собраны в: $ENV_FILE"
echo "  PRODUCT:        #${PRODUCT_ID} «${PRODUCT_NAME:-?}» — ${PRODUCT_PRICE} ₽ (stock=${PRODUCT_STOCK:-?})"
echo "  CLIENT:         #${CLIENT_ID} (${CLIENT_EMAIL:-?})"
echo "  PUBLIC PROMO:   ${PUBLIC_PROMO_CODE:-—}"
echo "  PERSONAL PROMO: ${PERSONAL_PROMO_CODE:-—}"
echo "  OWN PERSONAL:   ${OWN_PERSONAL_CODE:-—}"
echo "  FOREIGN PROMO:  ${FOREIGN_PERSONAL_CODE:-—}"
echo "  GIFT PRODUCT:   #${GIFT_PRODUCT_ID:-—} variant #${GIFT_VARIANT_ID:-—} (${GIFT_NOMINAL:-?} ₽) / variant2 #${GIFT_VARIANT_ID_2:-—} (${GIFT_NOMINAL_2:-?} ₽)"

if (( ${#warn[@]} > 0 )); then
  echo ""
  echo "Внимание (необязательные данные не найдены):"
  for w in "${warn[@]}"; do echo "  - $w"; done
fi

echo ""
echo "Готово. Запускай: ./tests/manual/02-run.sh"
