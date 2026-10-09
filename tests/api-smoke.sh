#!/usr/bin/env bash
# Admin API smoke test against a LOCAL development stack (env: TRACKER_CONTAINER, STATION, BASE). Creates and deletes its own
# throwaway branch (code SMOKE-TEST); does not touch imported branches.
#   plugins/branch-tracker/tests/api-smoke.sh
set -uo pipefail
CONTAINER=${TRACKER_CONTAINER:-$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /azuracast\/azuracast|\/azuracast:|^azuracast/ {print $1; exit}')}
STATION=${STATION:-store}
cd "$(dirname "$0")/../../.."
BASE=${BASE:-http://localhost:8080}
API="$BASE/api/station/$STATION/branches"
KEY=$(cat backups/local-api-key 2>/dev/null) || { echo "Run tests/make-local-api-key.sh first"; exit 1; }
PASS=0; FAIL=0

# req METHOD URL [json-body] -> sets $CODE and $BODY
req() {
  local out
  out=$(curl -s -w '\n%{http_code}' -X "$1" "$2" -H "X-API-Key: $KEY" -H 'Content-Type: application/json' ${3:+-d "$3"})
  BODY=$(head -n -1 <<<"$out"); CODE=$(tail -n 1 <<<"$out")
}
check() { # name expected-code [jq-expression-that-must-be-true]
  local ok=1
  [ "$CODE" = "$2" ] || ok=0
  if [ $ok = 1 ] && [ -n "${3:-}" ]; then jq -e "$3" >/dev/null 2>&1 <<<"$BODY" || ok=0; fi
  if [ $ok = 1 ]; then PASS=$((PASS+1)); printf '  ok    %s\n' "$1"
  else FAIL=$((FAIL+1)); printf '  FAIL  %s (HTTP %s) %s\n' "$1" "$CODE" "$(head -c 200 <<<"$BODY")"; fi
}

echo "Branch Tracker admin API smoke test ($API)"

CODE=$(curl -s -o /dev/null -w '%{http_code}' "$API"); BODY=''
check "no credentials -> rejected" 403

req GET "$API"
check "list branches" 200 'length > 0 and all(.[]; has("code") and (has("pin") | not) and (has("pin_hmac") | not))'

# Clean up leftovers from an interrupted run.
for code in SMOKE-TEST SMOKE-TEST-2; do
  id=$(curl -s -H "X-API-Key: $KEY" "$API" | jq -r ".[] | select(.code==\"$code\") | .id" | head -1)
  [ -n "$id" ] && curl -s -o /dev/null -X DELETE -H "X-API-Key: $KEY" "$API/$id"
done

FIRST_CODE=$(jq -r '.[0].code' <<<"$BODY")
req POST "$API" "{\"code\":\"$FIRST_CODE\",\"name\":\"dup code\"}"
check "duplicate code -> 409" 409

req POST "$API" '{"code":"SMOKE-TEST","name":"x","pin":"1234"}'
check "weak PIN -> 400" 400

req POST "$API" '{"code":"SMOKE-TEST","name":"x","pin":"12"}'
check "too-short PIN -> 400" 400

req POST "$API" '{"code":"SMOKE-TEST","name":"x","pin":"582013"}'
check "wrong length (6 digits; default is 4) -> 400" 400

req POST "$API" '{"code":"bad code!","name":"x"}'
check "invalid code -> 400" 400

req POST "$API" '{"code":"SMOKE-TEST","name":"  Smoke   Test  "}'
check "create with generated PIN -> 201" 201 '(.pin | test("^[0-9]{4}$")) and .name == "Smoke Test"'
ID=$(jq -r .id <<<"$BODY"); PIN1=$(jq -r .pin <<<"$BODY")

req POST "$API" "{\"code\":\"SMOKE-TEST-2\",\"name\":\"y\",\"pin\":\"$PIN1\"}"
check "PIN already used by another branch -> 409" 409

req GET "$API/$ID"
check "get one (no PIN returned)" 200 '.code == "SMOKE-TEST" and (has("pin") | not)'

req PUT "$API/$ID" '{"name":"Smoke Renamed","is_enabled":false}'
check "update name + disable" 200 '.name == "Smoke Renamed" and .is_enabled == false'

# A fresh request reads from the DB: catches updates that were returned but never saved
# (AzuraCast's explicit change tracking drops un-persist()ed changes silently).
req GET "$API/$ID"
check "update was persisted (fresh GET)" 200 '.name == "Smoke Renamed" and .is_enabled == false'

req PUT "$API/$ID" "{\"code\":\"$FIRST_CODE\"}"
check "rename to taken code -> 409" 409

req POST "$API/$ID/pin" '{}'
check "reset PIN (generated 4 digits, different)" 200 "(.pin | test(\"^[0-9]{4}\$\")) and .pin != \"$PIN1\""
PIN2=$(jq -r .pin <<<"$BODY")

req POST "$API" '{"code":"SMOKE-TEST-2","name":"y"}'
ID2=$(jq -r .id <<<"$BODY")
req POST "$API/$ID2/pin" "{\"pin\":\"$PIN2\"}"
check "set PIN to another branch's PIN -> 409" 409
req DELETE "$API/$ID2"

req GET "$API/999999"
check "unknown branch -> 404" 404

req DELETE "$API/$ID"
check "delete" 200 '.success == true'

req GET "$API/$ID"
check "deleted branch is gone -> 404" 404

# --- public page takeover (branch PIN login on /public/$STATION) ---
PUBLIC="${API%/api/station/$STATION/branches}/public/$STATION"
req GET "$API/settings"
check "settings readable" 200 'has("public_page_login") and has("stream_auth")'
ORIG=$(jq -r .public_page_login <<<"$BODY")
req PUT "$API/settings" '{"public_page_login":true}'
check "public page login ON" 200 '.public_page_login == true and .public_page_login_effective == true'
CODE=$(curl -s "$PUBLIC" | grep -c 'azuracast-tracker-plugin'); BODY='{}'
check "/public/$STATION serves the branch PIN login" 1
req PUT "$API/settings" '{"public_page_login":false}'
check "public page login OFF" 200 '.public_page_login == false'
if [ "$(jq -r .stream_auth <<<"$BODY")" != "enforce" ]; then
  CODE=$(curl -s "$PUBLIC" | grep -c 'azuracast-tracker-plugin'); BODY='{}'
  check "/public/$STATION back to the stock page" 0
fi
req PUT "$API/settings" "{\"public_page_login\":$ORIG}"

echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
