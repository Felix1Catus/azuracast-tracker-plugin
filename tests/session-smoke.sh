#!/usr/bin/env bash
# Phase 2 end-to-end test against the LOCAL stack: login / heartbeat / one-device rule / logout /
# disabled branch, and Icecast stream auth in observe + enforce mode with real stream connections.
# Uses its own throwaway branch. Leaves stream auth OFF at the end. Restarts the station 3 times.
#   plugins/branch-tracker/tests/session-smoke.sh [--with-throttle]   (throttle test blocks logins for 5 min)
set -uo pipefail
CONTAINER=${TRACKER_CONTAINER:-$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /azuracast\/azuracast|\/azuracast:|^azuracast/ {print $1; exit}')}
STATION=${STATION:-store}
cd "$(dirname "$0")/../../.."
BASE=${BASE:-http://localhost:8080}
ADMIN="$BASE/api/station/$STATION/branches"
PUB="$BASE/api/station/$STATION/branch"
STREAM="$BASE/listen/$STATION/radio.mp3"
KEY=$(cat backups/local-api-key 2>/dev/null) || { echo "Run tests/make-local-api-key.sh first"; exit 1; }
CLI="docker exec -u azuracast $CONTAINER azuracast_cli"
SQL() { docker exec -i $CONTAINER sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -N'; }
PASS=0; FAIL=0

req() { # METHOD URL [body] [extra curl args...]
  local m=$1 u=$2 b=${3:-}; shift 3 2>/dev/null || shift $#
  local out; out=$(curl -s -w '\n%{http_code}' -X "$m" "$u" -H 'Content-Type: application/json' ${b:+-d "$b"} "$@")
  BODY=$(head -n -1 <<<"$out"); CODE=$(tail -n 1 <<<"$out")
}
check() {
  local ok=1
  [ "$CODE" = "$2" ] || ok=0
  if [ $ok = 1 ] && [ -n "${3:-}" ]; then jq -e "$3" >/dev/null 2>&1 <<<"$BODY" || ok=0; fi
  if [ $ok = 1 ]; then PASS=$((PASS+1)); printf '  ok    %s\n' "$1"
  else FAIL=$((FAIL+1)); printf '  FAIL  %s (HTTP %s) %s\n' "$1" "$CODE" "$(head -c 200 <<<"$BODY")"; fi
}
stream() { # url -> sets CODE (HTTP status) and BYTES for a 3 s connection
  local r; r=$(curl -s -m 3 -o /dev/null -w '%{http_code} %{size_download}' "$1"); CODE=${r% *}; BYTES=${r#* }; BODY='{}'
}
auth_mode() { $CLI branch-tracker:stream-auth $STATION "$1" >/dev/null 2>&1; sleep 6; }

echo "Branch Tracker session + stream test"

# --- setup: throwaway branch ---
old=$(curl -s -H "X-API-Key: $KEY" "$ADMIN" | jq -r '.[] | select(.code=="SMOKE-SESSION") | .id')
[ -n "$old" ] && curl -s -o /dev/null -X DELETE -H "X-API-Key: $KEY" "$ADMIN/$old"
req POST "$ADMIN" '{"code":"SMOKE-SESSION","name":"Smoke Session"}' -H "X-API-Key: $KEY"
BID=$(jq -r .id <<<"$BODY"); PIN=$(jq -r .pin <<<"$BODY")
[ "$CODE" = 201 ] || { echo "setup failed: $BODY"; exit 1; }
WRONG=$(printf %04d $(( (10#$PIN + 1) % 10000 )))

# --- login ---
req POST "$PUB/login" "{\"pin\":\"$WRONG\"}"
check "wrong PIN -> 401 invalid_pin" 401 '.reason == "invalid_pin" and (has("token") | not)'

req POST "$PUB/login" '{}'
check "missing PIN -> 401" 401

req POST "$PUB/login" "{\"pin\":\"$PIN\"}"
check "login -> token + branch" 200 '(.token | test("^[a-f0-9]{64}$")) and .branch.code == "SMOKE-SESSION" and .heartbeat_seconds == 60'
T1=$(jq -r .token <<<"$BODY")

req POST "$PUB/heartbeat" "{\"token\":\"$T1\",\"volume\":55,\"state\":\"playing\"}"
check "heartbeat" 200 '.success == true'
check_db=$(echo "SELECT CONCAT(volume,'/',client_state) FROM branch_session WHERE token_hash=SHA2('$T1',256);" | SQL)
CODE=200; BODY="{\"v\":\"$check_db\"}"; check "heartbeat persisted volume/state" 200 '.v == "55/playing"'

req POST "$PUB/heartbeat" '{"token":"not-a-token"}'
check "heartbeat with bad token -> 401" 401 '.reason == "invalid_token"'

# --- one device per branch ---
req POST "$PUB/login" "{\"pin\":\"$PIN\"}"
T2=$(jq -r .token <<<"$BODY")
check "second device logs in" 200

req POST "$PUB/heartbeat" "{\"token\":\"$T1\"}"
check "first device is now rejected: replaced" 401 '.reason == "replaced"'

req POST "$PUB/heartbeat" "{\"token\":\"$T2\"}"
check "second device heartbeat ok" 200

# --- stream auth: observe ---
auth_mode observe
stream "$STREAM?bt=$T2";   check "observe: valid token streams" 200; [ "${BYTES:-0}" -gt 10000 ] || echo "        (only $BYTES bytes)"
stream "$STREAM";          check "observe: no token still streams (pilot mode)" 200
LINKS=$(echo "SELECT COUNT(*) FROM branch_session_listener l JOIN branch_session s ON s.id=l.session_id WHERE s.token_hash=SHA2('$T2',256);" | SQL)
CODE=200; BODY="{\"n\":$LINKS}"; check "observe: Icecast client linked to the session" 200 '.n >= 1'

# --- stream auth: enforce ---
auth_mode enforce
stream "$STREAM?bt=$T2";   check "enforce: valid token streams" 200
PP=$(curl -s "$BASE/public/$STATION" | grep -c 'azuracast-tracker-plugin'); CODE=200; BODY="{\"n\":$PP}"
check "enforce: /public/$STATION always shows the branch PIN login" 200 '.n == 1'
stream "$STREAM";          check "enforce: no token -> 401" 401
stream "$STREAM?bt=$T1";   check "enforce: replaced device's token -> 401" 401
# Icecast's own port, from inside the container: deployments may not publish 8000 at all (AzuraCast Retail doesn't).
r=$(docker exec "$CONTAINER" curl -s -m 3 -o /dev/null -w '%{http_code} %{size_download}' "http://localhost:8000/radio.mp3")
CODE=${r% *}; BYTES=${r#* }; BODY='{}'
check "enforce: direct :8000 without token -> 401" 401

# --- logout (sendBeacon style: text/plain body) ---
out=$(curl -s -w '\n%{http_code}' -X POST "$PUB/logout" -H 'Content-Type: text/plain;charset=UTF-8' -d "{\"token\":\"$T2\"}")
BODY=$(head -n -1 <<<"$out"); CODE=$(tail -n 1 <<<"$out")
check "logout via text/plain beacon body" 200
req POST "$PUB/heartbeat" "{\"token\":\"$T2\"}"
check "after logout: heartbeat -> 401 logout" 401 '.reason == "logout"'
stream "$STREAM?bt=$T2";   check "enforce: logged-out token -> 401" 401

auth_mode off
stream "$STREAM";          check "off: open stream again" 200

# --- disabled branch ---
curl -s -o /dev/null -X PUT "$ADMIN/$BID" -H "X-API-Key: $KEY" -H 'Content-Type: application/json' -d '{"is_enabled":false}'
req POST "$PUB/login" "{\"pin\":\"$PIN\"}"
check "disabled branch cannot log in" 401

# --- optional: station-wide failed-login throttle ---
if [ "${1:-}" = "--with-throttle" ]; then
  for i in $(seq 1 25); do curl -s -o /dev/null -X POST "$PUB/login" -H 'Content-Type: application/json' -d "{\"pin\":\"$WRONG\"}"; done
  req POST "$PUB/login" "{\"pin\":\"$WRONG\"}"
  check "many failures -> 429 (logins blocked for 5 min)" 429
fi

curl -s -o /dev/null -X DELETE -H "X-API-Key: $KEY" "$ADMIN/$BID"
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
