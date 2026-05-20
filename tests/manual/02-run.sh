#!/usr/bin/env bash
# Раннер ручного playbook'а: S1–S9 (гость) + C1–C7 (клиент).
# Гоняется против живого Laravel (BASE_URL из .env.local).
#
# Зависимости: curl, jq.
# Перед запуском: ./01-prepare.sh

set -u
# Не -e: одна failed проверка не должна валить весь прогон.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env.local"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: $ENV_FILE не найден. Сначала запусти ./01-prepare.sh" >&2
  exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

for bin in curl jq; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "ERROR: требуется утилита '$bin' (apt install $bin)" >&2
    exit 1
  fi
done

# ------------------------------------------------------------------
# Счётчики + утилиты вывода
# ------------------------------------------------------------------
PASS=0
FAIL=0
SKIP=0
FAIL_LOG=""

# Чтобы 99-cleanup при необходимости почистил всё, помечаем гостевые
# заказы предсказуемым префиксом email-а. Заодно используем суффикс
# времени, чтобы при повторных прогонах не было коллизий.
TS=$(date +%s)
GUEST_EMAIL="guest+${TS}@example.com"
PROMO_GUEST_EMAIL="promo+${TS}@example.com"

# Сюда складываем view_token / order_id созданных заказов, потом
# понадобится для S8 и для логов.
declare -a CREATED_TOKENS=()
declare -a CREATED_ORDER_IDS=()

ok()    { PASS=$((PASS+1)); printf "[\033[32mPASS\033[0m] %s\n" "$1"; }
bad()   { FAIL=$((FAIL+1)); printf "[\033[31mFAIL\033[0m] %s\n" "$1"; FAIL_LOG+="    $1\n"; if [[ -n "${2:-}" ]]; then echo "        $2"; fi; }
skip()  { SKIP=$((SKIP+1)); printf "[\033[33mSKIP\033[0m] %s\n" "$1"; }
note()  { printf "       %s\n" "$1"; }
hr()    { printf "\n────── %s ──────\n" "$1"; }

# Чистая обёртка: делает POST/GET и возвращает body+статус в две
# переменных через nameref. Работает с bash 4+.
http() {
  local -n _body=$1
  local -n _status=$2
  local method=$3
  local url=$4
  local data=${5:-}
  local auth=${6:-}

  local args=(-sk -o /tmp/manual_body.$$ -w '%{http_code}' -X "$method" "$url")
  args+=(-H 'Content-Type: application/json' -H 'Accept: application/json')
  if [[ -n "$auth" ]]; then args+=(-H "Authorization: Bearer $auth"); fi
  if [[ -n "$data" ]]; then args+=(-d "$data"); fi

  _status=$(curl "${args[@]}")
  _body=$(cat /tmp/manual_body.$$ 2>/dev/null || echo "")
  rm -f /tmp/manual_body.$$
}

# Базовый payload для S* и C* (без promo, без auth — каждый тест
# что-то добавит сверху через jq).
base_payload() {
  local first="$1" last="$2" phone="$3" email="${4:-}" extra="${5:-}"
  local payload
  payload=$(jq -nc \
    --arg first "$first" --arg last "$last" --arg phone "$phone" \
    --argjson product_id "$PRODUCT_ID" \
    --argjson price "$PRODUCT_PRICE" '
    {
      user: { first_name: $first, last_name: $last, phone: $phone },
      delivery_address: { country: "Россия", city: "Москва", address: "ул. Тестовая, 1" },
      items: [ { product_id: $product_id, quantity: 1, price: $price } ]
    }')
  if [[ -n "$email" ]]; then
    payload=$(jq -c --arg e "$email" '.user.email = $e' <<< "$payload")
  fi
  if [[ -n "$extra" ]]; then
    payload=$(jq -c ". + $extra" <<< "$payload")
  fi
  echo "$payload"
}

# ------------------------------------------------------------------
# ГОСТЬ — S1..S8 (S9 запустим в самом конце, чтобы не съесть лимит)
# ------------------------------------------------------------------
hr "Гость (S1–S8)"

# ---------------- S1: минимальный гостевой заказ -------------------
{
  payload=$(base_payload "Тест" "Гость" "+79991234501")
  http body status POST "$BASE_URL/api/public/orders" "$payload"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "S1 guest min payload: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    client_id=$(jq -r '.order.client_id // "null"' <<< "$body")
    email=$(jq -r '.order.email // "null"' <<< "$body")
    view_token=$(jq -r '.order.view_token // ""' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    if [[ "$client_id" == "null" && "$email" == "null" && "$view_token" =~ ^[a-f0-9]{32}$ ]]; then
      ok "S1 guest min payload: client_id=null, view_token=${view_token:0:8}…"
      CREATED_TOKENS+=("$view_token")
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id")
      S1_TOKEN="$view_token"
    else
      bad "S1 guest min payload: client_id=$client_id email=$email view_token='$view_token'" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
}

# ---------------- S2: гость с email --------------------------------
{
  payload=$(base_payload "Тест" "Гость" "+79991234502" "$GUEST_EMAIL")
  http body status POST "$BASE_URL/api/public/orders" "$payload"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "S2 guest with email: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    email=$(jq -r '.order.email // ""' <<< "$body")
    view_token=$(jq -r '.order.view_token // ""' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    if [[ "$email" == "$GUEST_EMAIL" ]]; then
      ok "S2 guest with email: order.email=$email"
      CREATED_TOKENS+=("$view_token")
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id")
      S2_TOKEN="$view_token"
    else
      bad "S2 guest with email: expected $GUEST_EMAIL, got '$email'" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
}

# ---------------- S3: гость + публичный промокод -------------------
if [[ -n "${PUBLIC_PROMO_CODE:-}" ]]; then
  extra=$(jq -nc --arg c "$PUBLIC_PROMO_CODE" '{promo_code: $c}')
  payload=$(base_payload "Промо" "Гость" "+79991234503" "$PROMO_GUEST_EMAIL" "$extra")
  http body status POST "$BASE_URL/api/public/orders" "$payload"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "S3 guest + public promo: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    discount=$(jq -r '.order.discount_amount // .summary.discount_amount // 0' <<< "$body")
    promo_id=$(jq -r '.order.promo_code_id // ""' <<< "$body")
    view_token=$(jq -r '.order.view_token // ""' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    discount_num=$(awk -v v="$discount" 'BEGIN{printf "%.2f", v+0}')
    if [[ -n "$promo_id" && "$promo_id" != "null" ]] || awk -v v="$discount_num" 'BEGIN{exit !(v>0)}'; then
      ok "S3 guest + public promo '$PUBLIC_PROMO_CODE': discount=${discount_num}, promo_code_id=${promo_id:-null}"
      CREATED_TOKENS+=("$view_token")
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id")
    else
      bad "S3 guest + public promo: ни discount>0, ни promo_code_id не пришли" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
else
  skip "S3 guest + public promo: PUBLIC_PROMO_CODE не задан"
fi

# ---------------- S4: гость + персональный → отказ -----------------
if [[ -n "${PERSONAL_PROMO_CODE:-}" ]]; then
  extra=$(jq -nc --arg c "$PERSONAL_PROMO_CODE" '{promo_code: $c}')
  payload=$(base_payload "X" "Y" "+79991234504" "" "$extra")
  http body status POST "$BASE_URL/api/public/orders" "$payload"

  code=$(jq -r '.code // ""' <<< "$body")
  if [[ "$status" == "422" && "$code" == "PROMO_REQUIRES_AUTH" ]]; then
    ok "S4 personal promo for guest → PROMO_REQUIRES_AUTH"
  else
    bad "S4 personal promo for guest: HTTP $status code='$code', expected 422+PROMO_REQUIRES_AUTH" \
      "$(echo "$body" | head -c 400)"
  fi
else
  skip "S4 personal promo for guest: PERSONAL_PROMO_CODE не задан"
fi

# ---------------- S5: пустой payload → 422 c ошибками --------------
{
  http body status POST "$BASE_URL/api/public/orders" '{}'

  if [[ "$status" == "422" ]]; then
    # Laravel-формат: {message, errors: {field: [msg]}}
    err_keys=$(jq -r '.errors // {} | keys[]?' <<< "$body" 2>/dev/null | tr '\n' ',' | sed 's/,$//')
    # Хотя бы какие-то ключи должны быть про items/delivery_address/recipient/user
    if [[ "$err_keys" =~ items || "$err_keys" =~ delivery_address || "$err_keys" =~ recipient || "$err_keys" =~ user ]]; then
      ok "S5 empty payload → 422 (errors: $err_keys)"
    else
      bad "S5 empty payload: 422, но в errors нет ожидаемых полей" \
        "$(echo "$body" | head -c 400)"
    fi
  else
    bad "S5 empty payload: HTTP $status, ожидался 422" \
      "$(echo "$body" | head -c 400)"
  fi
}

# ---------------- S6: тот же endpoint, но с Bearer-токеном клиента -
{
  payload=$(base_payload "Тест" "Клиент" "+79991234506")
  http body status POST "$BASE_URL/api/public/orders" "$payload" "$CLIENT_TOKEN"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "S6 client via public endpoint: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    client_id=$(jq -r '.order.client_id // "null"' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    if [[ "$client_id" == "$CLIENT_ID" ]]; then
      ok "S6 client via public endpoint: order.client_id=$client_id"
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id")
    else
      bad "S6 client via public endpoint: expected client_id=$CLIENT_ID, got $client_id" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
}

# ---------------- S7: админский endpoint без токена должен быть закрыт
{
  http body status POST "$BASE_URL/api/orders" '{}'
  # Главное — заказ НЕ создался. Поэтому 2xx — это FAIL.
  if [[ "$status" =~ ^2 ]]; then
    bad "S7 admin endpoint без токена: HTTP $status (заказ создался)" \
      "$(echo "$body" | head -c 400)"
  else
    ok "S7 admin /api/orders без токена: HTTP $status (не создаёт заказ)"
  fi
}

# ---------------- S8: публичный просмотр заказа по view_token ------
if [[ -n "${S2_TOKEN:-}" ]]; then
  http body status GET "$BASE_URL/api/public/orders/$S2_TOKEN"

  if [[ "$status" != "200" ]]; then
    bad "S8 GET /api/public/orders/{token}: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    email=$(jq -r '.order.recipient.email // ""' <<< "$body")
    success=$(jq -r '.success // false' <<< "$body")
    if [[ "$success" == "true" && "$email" == "$GUEST_EMAIL" ]]; then
      ok "S8 public view of guest order: recipient.email=$email"
    else
      bad "S8 public view of guest order: success=$success, email='$email' (ожидался $GUEST_EMAIL)" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
else
  skip "S8 public view: нет view_token из S2"
fi

# ------------------------------------------------------------------
# КЛИЕНТ — C1..C7 (используем токен из .env.local)
# ------------------------------------------------------------------
hr "Клиент (C1–C7)"

# ---------------- C1: базовый клиентский заказ через public endpoint
{
  payload=$(base_payload "Тест" "Клиент-base" "+79991230001")
  http body status POST "$BASE_URL/api/public/orders" "$payload" "$CLIENT_TOKEN"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "C1 client base via public: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    client_id=$(jq -r '.order.client_id // "null"' <<< "$body")
    email=$(jq -r '.order.email // "null"' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    # Для авторизованного клиента email в самом заказе должен быть null
    # (берётся из связанного clients.email).
    if [[ "$client_id" == "$CLIENT_ID" && "$email" == "null" ]]; then
      ok "C1 client base via public: client_id=$client_id, order.email=null"
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id") && C1_ORDER_ID="$order_id"
    else
      bad "C1 client base via public: client_id=$client_id email=$email (ожидалось $CLIENT_ID/null)" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
}

# ---------------- C2: клиент + публичный промо ---------------------
if [[ -n "${PUBLIC_PROMO_CODE:-}" ]]; then
  extra=$(jq -nc --arg c "$PUBLIC_PROMO_CODE" '{promo_code: $c}')
  payload=$(base_payload "Тест" "Клиент-promo" "+79991230002" "" "$extra")
  http body status POST "$BASE_URL/api/public/orders" "$payload" "$CLIENT_TOKEN"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    # Может прилететь PROMO_ALREADY_USED, если этот клиент уже когда-то
    # использовал этот код. Сообщаем как FAIL, чтобы видеть в логе.
    bad "C2 client + public promo: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    discount=$(jq -r '.order.discount_amount // .summary.discount_amount // 0' <<< "$body")
    promo_id=$(jq -r '.order.promo_code_id // ""' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    discount_num=$(awk -v v="$discount" 'BEGIN{printf "%.2f", v+0}')
    if [[ -n "$promo_id" && "$promo_id" != "null" ]] || awk -v v="$discount_num" 'BEGIN{exit !(v>0)}'; then
      ok "C2 client + public promo '$PUBLIC_PROMO_CODE': discount=${discount_num}"
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id") && C2_ORDER_ID="$order_id"
    else
      bad "C2 client + public promo: discount не применился" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
else
  skip "C2 client + public promo: PUBLIC_PROMO_CODE не задан"
fi

# ---------------- C3: клиент + персональный, выданный ему ----------
if [[ -n "${OWN_PERSONAL_CODE:-}" ]]; then
  extra=$(jq -nc --arg c "$OWN_PERSONAL_CODE" '{promo_code: $c}')
  payload=$(base_payload "Тест" "Клиент-own" "+79991230003" "" "$extra")
  http body status POST "$BASE_URL/api/public/orders" "$payload" "$CLIENT_TOKEN"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "C3 client + own personal promo: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    ok "C3 client + own personal promo '$OWN_PERSONAL_CODE' → success"
    [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id")
  fi
else
  skip "C3 client + own personal promo: OWN_PERSONAL_CODE не задан (нужен персональный промо, привязанный к CLIENT_ID=$CLIENT_ID)"
fi

# ---------------- C4: клиент + чужой персональный → PROMO_NOT_FOR_CLIENT
if [[ -n "${FOREIGN_PERSONAL_CODE:-}" ]]; then
  extra=$(jq -nc --arg c "$FOREIGN_PERSONAL_CODE" '{promo_code: $c}')
  payload=$(base_payload "Тест" "Клиент-foreign" "+79991230004" "" "$extra")
  http body status POST "$BASE_URL/api/public/orders" "$payload" "$CLIENT_TOKEN"

  code=$(jq -r '.code // ""' <<< "$body")
  if [[ "$status" == "422" && "$code" == "PROMO_NOT_FOR_CLIENT" ]]; then
    ok "C4 client + foreign personal promo → PROMO_NOT_FOR_CLIENT"
  else
    bad "C4 client + foreign personal promo: HTTP $status code='$code', expected 422+PROMO_NOT_FOR_CLIENT" \
      "$(echo "$body" | head -c 400)"
  fi
else
  skip "C4 client + foreign personal promo: FOREIGN_PERSONAL_CODE не задан"
fi

# ---------------- C5: клиент использует промо повторно -------------
if [[ -n "${PUBLIC_PROMO_CODE:-}" ]]; then
  extra=$(jq -nc --arg c "$PUBLIC_PROMO_CODE" '{promo_code: $c}')
  payload=$(base_payload "Тест" "Клиент-dup" "+79991230005" "" "$extra")
  http body status POST "$BASE_URL/api/public/orders" "$payload" "$CLIENT_TOKEN"

  code=$(jq -r '.code // ""' <<< "$body")
  # Сценарий валиден только если в БД у промокода действительно стоит
  # лимит «one_per_client» или есть запись об использовании от C2.
  # Если у кода `max_uses_per_client` нет → проверка пропускается на
  # бэке и заказ создастся.
  if [[ "$status" == "422" && "$code" == "PROMO_ALREADY_USED" ]]; then
    ok "C5 client uses public promo twice → PROMO_ALREADY_USED"
  elif [[ "$status" =~ ^2 ]]; then
    skip "C5 client uses public promo twice: '$PUBLIC_PROMO_CODE' допускает повторное использование (нет max_uses_per_client)"
  else
    bad "C5 client uses public promo twice: HTTP $status code='$code', expected 422+PROMO_ALREADY_USED" \
      "$(echo "$body" | head -c 400)"
  fi
fi

# ---------------- C6: клиент через старый /api/orders --------------
{
  payload=$(base_payload "Тест" "Клиент-old" "+79991230006")
  http body status POST "$BASE_URL/api/orders" "$payload" "$CLIENT_TOKEN"

  if [[ "$status" != "201" && "$status" != "200" ]]; then
    bad "C6 client via old /api/orders: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    client_id=$(jq -r '.order.client_id // "null"' <<< "$body")
    order_id=$(jq -r '.order.id // ""' <<< "$body")
    if [[ "$client_id" == "$CLIENT_ID" ]]; then
      ok "C6 client via old /api/orders: client_id=$client_id (регресс прошёл)"
      [[ -n "$order_id" ]] && CREATED_ORDER_IDS+=("$order_id")
    else
      bad "C6 client via old /api/orders: client_id=$client_id (ожидался $CLIENT_ID)" \
        "$(echo "$body" | head -c 400)"
    fi
  fi
}

# ---------------- C7: история заказов клиента ----------------------
{
  http body status GET "$BASE_URL/api/orders/user" "" "$CLIENT_TOKEN"

  if [[ "$status" != "200" ]]; then
    bad "C7 GET /api/orders/user: HTTP $status" "$(echo "$body" | head -c 400)"
  else
    total=$(jq -r '.orders | length // 0' <<< "$body")
    # Проверяем, что хотя бы один из созданных в C1/C2/C6 ID присутствует
    found=0
    for oid in "${CREATED_ORDER_IDS[@]}"; do
      if jq -e --argjson id "$oid" '.orders[] | select(.id==$id)' <<< "$body" >/dev/null 2>&1; then
        found=$((found+1))
      fi
    done
    if (( found > 0 )); then
      ok "C7 /api/orders/user: вернул $total заказов, найдено $found из созданных"
    else
      bad "C7 /api/orders/user: вернул $total заказов, но ни одного из созданных в этом прогоне не нашёл" \
        "ожидались id: ${CREATED_ORDER_IDS[*]}"
    fi
  fi
}

# ------------------------------------------------------------------
# S9: rate-limit. Запускаем последним, потому что съест ~30 req
# из лимита `throttle:30,1` и заблокирует endpoint на минуту.
# ------------------------------------------------------------------
hr "S9 rate-limit (последний, съест лимит на минуту)"

{
  # Отправляем 35 пустых payload'ов и считаем коды. Ожидание:
  # большая часть = 422 (валидация), остальные = 429 (throttle).
  declare -A codes=()
  for _ in $(seq 1 35); do
    code=$(curl -sk -o /dev/null -w '%{http_code}' \
      -X POST "$BASE_URL/api/public/orders" \
      -H 'Content-Type: application/json' -d '{}')
    codes[$code]=$(( ${codes[$code]:-0} + 1 ))
  done

  summary=""
  for c in "${!codes[@]}"; do summary+="$c×${codes[$c]} "; done
  summary=${summary% }

  if [[ -n "${codes[429]:-}" ]] && (( ${codes[429]:-0} >= 1 )); then
    ok "S9 rate-limit hit: $summary"
  else
    bad "S9 rate-limit: ни одного 429 не получено ($summary)" \
      "проверь, что middleware throttle:30,1 навешен на /api/public/orders"
  fi
}

# ------------------------------------------------------------------
# Сводка
# ------------------------------------------------------------------
echo ""
echo "────────────────────────────"
printf "Итого: \033[32m%d passed\033[0m, \033[31m%d failed\033[0m, \033[33m%d skipped\033[0m\n" "$PASS" "$FAIL" "$SKIP"

if (( ${#CREATED_TOKENS[@]} > 0 )); then
  echo ""
  echo "View-токены гостевых заказов (для ручной перепроверки):"
  for t in "${CREATED_TOKENS[@]}"; do echo "  $BASE_URL/orders/$t"; done
fi

if (( FAIL > 0 )); then
  echo ""
  echo "Failed:"
  printf "%b" "$FAIL_LOG"
  exit 1
fi
exit 0
