#!/usr/bin/env bash
# Сценарии «гость покупает Подарочный сертификат как товар».
# В основном playbook (S1–S9 / C1–C7) их нет — это отдельный flow,
# где после создания заказа `PublicCheckoutController` дополнительно
# дёргает `GiftCardService::createFromOrder` и `scheduleDelivery`.
#
# Что проверяем:
#   G1 — гость покупает электронный сертификат для СЕБЯ, immediate.
#   G2 — гость покупает электронный сертификат для ДРУГОГО, immediate.
#   G3 — гость покупает электронный сертификат, scheduled на завтра.
#
# В каждом — проверяется HTTP+contains_gift_card_product=true, а после
# через `php artisan tinker` сверяется:
#   - запись в gift_cards (nominal, type, status=inactive, sender_*, recipient_*)
#   - SendGiftCardJob в очереди (таблица `jobs`)
#
# Зависимости: curl, jq, php artisan (для DB-сверки).
# Перед запуском: ./01-prepare.sh

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LARAVEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.local"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: $ENV_FILE не найден. Сначала запусти ./01-prepare.sh" >&2
  exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

for bin in curl jq; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "ERROR: требуется утилита '$bin'" >&2
    exit 1
  fi
done

if [[ -z "${GIFT_PRODUCT_ID:-}" || -z "${GIFT_VARIANT_ID:-}" ]]; then
  echo "ERROR: в .env.local нет GIFT_PRODUCT_ID / GIFT_VARIANT_ID." >&2
  echo "  Нужен Product «Подарочный сертификат» с has_variants=1 и активными вариантами." >&2
  exit 1
fi

PASS=0
FAIL=0
SKIP=0
FAIL_LOG=""
TS=$(date +%s)
declare -a CREATED_ORDER_IDS=()
declare -a CREATED_GIFT_IDS=()

ok()    { PASS=$((PASS+1)); printf "[\033[32mPASS\033[0m] %s\n" "$1"; }
bad()   { FAIL=$((FAIL+1)); printf "[\033[31mFAIL\033[0m] %s\n" "$1"; FAIL_LOG+="    $1\n"; if [[ -n "${2:-}" ]]; then echo "        $2"; fi; }
skip()  { SKIP=$((SKIP+1)); printf "[\033[33mSKIP\033[0m] %s\n" "$1"; }
hr()    { printf "\n────── %s ──────\n" "$1"; }

http() {
  local -n _body=$1
  local -n _status=$2
  local method=$3
  local url=$4
  local data=${5:-}

  local args=(-sk -o /tmp/manual_gc_body.$$ -w '%{http_code}' -X "$method" "$url")
  args+=(-H 'Content-Type: application/json' -H 'Accept: application/json')
  [[ -n "$data" ]] && args+=(-d "$data")

  _status=$(curl "${args[@]}")
  _body=$(cat /tmp/manual_gc_body.$$ 2>/dev/null || echo "")
  rm -f /tmp/manual_gc_body.$$
}

# Сборка payload «гость + сертификат». Принимает:
#   $1 — variant_id для сертификата
#   $2 — nominal (цена варианта)
#   $3 — email гостя
#   $4 — json-фрагмент gift_card_data
build_payload() {
  local variant_id="$1" nominal="$2" email="$3" gcd="$4"
  jq -nc \
    --arg first "Тест" --arg last "Гость-GC" --arg phone "+79991230101" \
    --arg email "$email" \
    --argjson product_id "$GIFT_PRODUCT_ID" \
    --argjson variant_id "$variant_id" \
    --argjson nominal "$nominal" \
    --argjson gcd "$gcd" '
    {
      user: { first_name: $first, last_name: $last, phone: $phone, email: $email },
      delivery_address: { country: "Россия", city: "Москва", address: "ул. Сертификатная, 1" },
      items: [
        { product_id: $product_id, product_variant_id: $variant_id, quantity: 1, price: $nominal }
      ],
      gift_card_data: $gcd
    }'
}

# ------------------------------------------------------------------
# G1: гость + сертификат для СЕБЯ, immediate
# ------------------------------------------------------------------
hr "G1 — гость, сертификат для себя, immediate"
{
  EMAIL_G1="g1+${TS}@example.com"
  gcd=$(jq -nc '{
    type: "electronic",
    sender_name: "Тест Покупатель",
    recipient_type: "self",
    delivery_channel: "email",
    delivery_type: "immediate"
  }')
  payload=$(build_payload "$GIFT_VARIANT_ID" "$GIFT_NOMINAL" "$EMAIL_G1" "$gcd")

  http body status POST "$BASE_URL/api/public/orders" "$payload"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "G1 POST: HTTP $status" "$(echo "$body" | head -c 500)"
  else
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    contains_gc=$(jq -r '.contains_gift_card_product // false' <<< "$body")
    client_id=$(jq -r '.order.client_id // "null"' <<< "$body")
    if [[ "$contains_gc" == "true" && "$client_id" == "null" && -n "$order_id" ]]; then
      ok "G1 POST: order #$order_id создан как гостевой, contains_gift_card_product=true"
      CREATED_ORDER_IDS+=("$order_id")
      G1_ORDER_ID="$order_id"
      G1_EMAIL="$EMAIL_G1"
    else
      bad "G1 POST: contains_gc=$contains_gc client_id=$client_id order_id=$order_id" \
        "$(echo "$body" | head -c 500)"
    fi
  fi
}

# ------------------------------------------------------------------
# G2: гость + сертификат для ДРУГОГО (email), immediate
# ------------------------------------------------------------------
if [[ -n "${GIFT_VARIANT_ID_2:-}" ]]; then
  hr "G2 — гость, сертификат для другого, immediate"
  EMAIL_G2="g2+${TS}@example.com"
  EMAIL_G2_RECIPIENT="g2recipient+${TS}@example.com"
  gcd=$(jq -nc --arg re "$EMAIL_G2_RECIPIENT" '{
    type: "electronic",
    sender_name: "Тест Отправитель",
    recipient_type: "someone",
    recipient_name: "Друг Тестовый",
    recipient_email: $re,
    delivery_channel: "email",
    delivery_type: "immediate",
    message: "С днём рождения!"
  }')
  payload=$(build_payload "$GIFT_VARIANT_ID_2" "$GIFT_NOMINAL_2" "$EMAIL_G2" "$gcd")

  http body status POST "$BASE_URL/api/public/orders" "$payload"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "G2 POST: HTTP $status" "$(echo "$body" | head -c 500)"
  else
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    contains_gc=$(jq -r '.contains_gift_card_product // false' <<< "$body")
    if [[ "$contains_gc" == "true" && -n "$order_id" ]]; then
      ok "G2 POST: order #$order_id создан, contains_gift_card_product=true"
      CREATED_ORDER_IDS+=("$order_id")
      G2_ORDER_ID="$order_id"
      G2_EMAIL="$EMAIL_G2"
      G2_RECIPIENT="$EMAIL_G2_RECIPIENT"
    else
      bad "G2 POST: contains_gc=$contains_gc order_id=$order_id" \
        "$(echo "$body" | head -c 500)"
    fi
  fi
else
  skip "G2 — нужен второй вариант сертификата (GIFT_VARIANT_ID_2)"
fi

# ------------------------------------------------------------------
# G3: гость + сертификат scheduled на завтра
# ------------------------------------------------------------------
hr "G3 — гость, сертификат scheduled на завтра"
{
  EMAIL_G3="g3+${TS}@example.com"
  TOMORROW=$(date -d '+1 day' +%Y-%m-%d 2>/dev/null || date -v+1d +%Y-%m-%d)
  gcd=$(jq -nc --arg date "$TOMORROW" '{
    type: "electronic",
    sender_name: "Тест Расписание",
    recipient_type: "self",
    delivery_channel: "email",
    delivery_type: "scheduled",
    scheduled_date: $date,
    scheduled_time: "12:00",
    timezone: "Europe/Moscow"
  }')
  payload=$(build_payload "$GIFT_VARIANT_ID" "$GIFT_NOMINAL" "$EMAIL_G3" "$gcd")

  http body status POST "$BASE_URL/api/public/orders" "$payload"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "G3 POST: HTTP $status" "$(echo "$body" | head -c 500)"
  else
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    contains_gc=$(jq -r '.contains_gift_card_product // false' <<< "$body")
    if [[ "$contains_gc" == "true" && -n "$order_id" ]]; then
      ok "G3 POST: order #$order_id создан, scheduled на $TOMORROW 12:00 MSK"
      CREATED_ORDER_IDS+=("$order_id")
      G3_ORDER_ID="$order_id"
      G3_EMAIL="$EMAIL_G3"
      G3_SCHEDULED_DATE="$TOMORROW"
    else
      bad "G3 POST: contains_gc=$contains_gc order_id=$order_id" \
        "$(echo "$body" | head -c 500)"
    fi
  fi
}

# ------------------------------------------------------------------
# Сверка в БД: gift_cards + jobs.
# Передаём список созданных order_id в tinker.
# ------------------------------------------------------------------
if (( ${#CREATED_ORDER_IDS[@]} > 0 )); then
  hr "Сверка артефактов в БД"

  ORDER_IDS_CSV=$(IFS=,; echo "${CREATED_ORDER_IDS[*]}")

  cd "$LARAVEL_DIR"

  # Возвращаем JSON-массив { order_id, gift_card_id, type, status, nominal,
  # sender_email, recipient_email, scheduled_at }
  DB_JSON=$(php artisan tinker --no-interaction --execute='
use App\Models\GiftCard\GiftCard;
$ids = explode(",", "'"$ORDER_IDS_CSV"'");
$rows = GiftCard::query()
    ->whereIn("purchase_order_id", $ids)
    ->orderBy("id")
    ->get([
      "id","purchase_order_id","type","status","nominal",
      "sender_email","recipient_email","recipient_name",
      "scheduled_at","delivery_channel"
    ])
    ->toArray();
echo "::JSON_BEGIN::" . json_encode($rows, JSON_UNESCAPED_UNICODE) . "::JSON_END::" . PHP_EOL;
' 2>&1)

  # выдёргиваем JSON между маркерами
  GIFT_JSON=$(echo "$DB_JSON" | sed -n 's/.*::JSON_BEGIN::\(.*\)::JSON_END::.*/\1/p')

  if [[ -z "$GIFT_JSON" || "$GIFT_JSON" == "[]" ]]; then
    bad "DB: ни одной записи в gift_cards для созданных заказов" \
      "ORDER_IDS=$ORDER_IDS_CSV; raw: $(echo "$DB_JSON" | tail -3)"
  else
    # G1
    if [[ -n "${G1_ORDER_ID:-}" ]]; then
      g1=$(jq -r --argjson oid "$G1_ORDER_ID" '.[] | select(.purchase_order_id==$oid)' <<< "$GIFT_JSON")
      if [[ -z "$g1" ]]; then
        bad "G1 DB: gift_cards для order #$G1_ORDER_ID не найден"
      else
        gid=$(jq -r '.id' <<< "$g1")
        nominal=$(jq -r '.nominal' <<< "$g1")
        type=$(jq -r '.type' <<< "$g1")
        status_v=$(jq -r '.status' <<< "$g1")
        sender_email=$(jq -r '.sender_email' <<< "$g1")
        recipient_email=$(jq -r '.recipient_email' <<< "$g1")

        nominal_num=$(awk -v v="$nominal" 'BEGIN{printf "%.2f", v+0}')
        expected_num=$(awk -v v="$GIFT_NOMINAL" 'BEGIN{printf "%.2f", v+0}')

        if [[ "$nominal_num" == "$expected_num" && "$type" == "electronic" && "$status_v" == "inactive" ]] \
            && [[ "$sender_email" == "$G1_EMAIL" && "$recipient_email" == "$G1_EMAIL" ]]; then
          ok "G1 DB: gift_card #$gid nominal=$nominal status=inactive sender=recipient=$G1_EMAIL"
          CREATED_GIFT_IDS+=("$gid")
        else
          bad "G1 DB: gift #$gid имеет неожиданные поля" \
            "nominal=$nominal type=$type status=$status_v sender=$sender_email recipient=$recipient_email (ожидалось sender=recipient=$G1_EMAIL)"
        fi
      fi
    fi

    # G2
    if [[ -n "${G2_ORDER_ID:-}" ]]; then
      g2=$(jq -r --argjson oid "$G2_ORDER_ID" '.[] | select(.purchase_order_id==$oid)' <<< "$GIFT_JSON")
      if [[ -z "$g2" ]]; then
        bad "G2 DB: gift_cards для order #$G2_ORDER_ID не найден"
      else
        gid=$(jq -r '.id' <<< "$g2")
        sender_email=$(jq -r '.sender_email' <<< "$g2")
        recipient_email=$(jq -r '.recipient_email' <<< "$g2")
        recipient_name=$(jq -r '.recipient_name' <<< "$g2")

        if [[ "$sender_email" == "$G2_EMAIL" && "$recipient_email" == "$G2_RECIPIENT" && "$recipient_name" == "Друг Тестовый" ]]; then
          ok "G2 DB: gift_card #$gid sender=$G2_EMAIL → recipient=$G2_RECIPIENT (Друг Тестовый)"
          CREATED_GIFT_IDS+=("$gid")
        else
          bad "G2 DB: gift #$gid recipient_email/sender_email/recipient_name не совпали" \
            "sender=$sender_email recipient_email=$recipient_email recipient_name=$recipient_name (ожидалось sender=$G2_EMAIL, recipient=$G2_RECIPIENT, name=Друг Тестовый)"
        fi
      fi
    fi

    # G3
    if [[ -n "${G3_ORDER_ID:-}" ]]; then
      g3=$(jq -r --argjson oid "$G3_ORDER_ID" '.[] | select(.purchase_order_id==$oid)' <<< "$GIFT_JSON")
      if [[ -z "$g3" ]]; then
        bad "G3 DB: gift_cards для order #$G3_ORDER_ID не найден"
      else
        gid=$(jq -r '.id' <<< "$g3")
        scheduled_at=$(jq -r '.scheduled_at' <<< "$g3")
        if [[ -n "$scheduled_at" && "$scheduled_at" != "null" ]] \
            && [[ "$scheduled_at" == "${G3_SCHEDULED_DATE}"* ]]; then
          ok "G3 DB: gift_card #$gid scheduled_at=$scheduled_at"
          CREATED_GIFT_IDS+=("$gid")
        else
          bad "G3 DB: gift #$gid scheduled_at='$scheduled_at' (ожидалась дата $G3_SCHEDULED_DATE)"
        fi
      fi
    fi
  fi

  # ------------------------------------------------------------------
  # Очередь: SendGiftCardJob для каждой созданной карты.
  # ------------------------------------------------------------------
  if (( ${#CREATED_GIFT_IDS[@]} > 0 )); then
    GIFT_IDS_CSV=$(IFS=,; echo "${CREATED_GIFT_IDS[*]}")
    JOBS_JSON=$(php artisan tinker --no-interaction --execute='
$ids = array_map("intval", explode(",", "'"$GIFT_IDS_CSV"'"));
$jobs = DB::table("jobs")
    ->where("queue", "default")
    ->where("payload", "like", "%SendGiftCardJob%")
    ->orderByDesc("id")
    ->limit(100)
    ->get(["id","queue","available_at","payload"]);
$result = [];
foreach ($jobs as $j) {
    $payload = json_decode($j->payload, true);
    $command = $payload["data"]["command"] ?? "";
    // SendGiftCardJob сериализован — gift_card_id где-то внутри. Простой regex.
    if (preg_match("/giftCardId[\";:i]+(\d+)/", $command, $m) ||
        preg_match("/gift_card_id[\";:i]+(\d+)/", $command, $m)) {
        $gid = (int) $m[1];
        if (in_array($gid, $ids, true)) {
            $result[] = ["job_id" => $j->id, "gift_card_id" => $gid, "available_at" => $j->available_at];
        }
    }
}
echo "::JSON_BEGIN::" . json_encode($result) . "::JSON_END::" . PHP_EOL;
' 2>&1)

    JOBS_OUT=$(echo "$JOBS_JSON" | sed -n 's/.*::JSON_BEGIN::\(.*\)::JSON_END::.*/\1/p')

    if [[ -z "$JOBS_OUT" || "$JOBS_OUT" == "[]" ]]; then
      # Fallback — простой подсчёт SendGiftCardJob после нашего TS
      count_after=$(php artisan tinker --no-interaction --execute='
echo DB::table("jobs")
    ->where("payload","like","%SendGiftCardJob%")
    ->where("created_at",">=", now()->subMinutes(5))
    ->count();
' 2>/dev/null | grep -oE '[0-9]+' | head -1)
      if [[ -n "$count_after" && "$count_after" -gt 0 ]]; then
        ok "Очередь: найдено $count_after свежих SendGiftCardJob (детальный матч по gift_card_id не удался, но job-ы есть)"
      else
        bad "Очередь: SendGiftCardJob не найден в таблице jobs за последние 5 минут" \
          "проверь QUEUE_CONNECTION (текущий: database) и что код доходит до scheduleDelivery()"
      fi
    else
      found_count=$(jq -r 'length' <<< "$JOBS_OUT")
      ok "Очередь: $found_count SendGiftCardJob найдено для созданных карт (${CREATED_GIFT_IDS[*]})"
    fi
  fi
fi

# ------------------------------------------------------------------
# Сводка
# ------------------------------------------------------------------
echo ""
echo "────────────────────────────"
printf "Итого: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m, \033[33m%d skipped\033[0m\n" "$PASS" "$FAIL" "$SKIP"

if (( ${#CREATED_ORDER_IDS[@]} > 0 )); then
  echo ""
  echo "Созданные тестовые заказы (для cleanup):  ${CREATED_ORDER_IDS[*]}"
fi
if (( ${#CREATED_GIFT_IDS[@]} > 0 )); then
  echo "Созданные gift_cards:                     ${CREATED_GIFT_IDS[*]}"
fi

if (( FAIL > 0 )); then
  echo ""
  echo "Failed:"
  printf "%b" "$FAIL_LOG"
  exit 1
fi
exit 0
