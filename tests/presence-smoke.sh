#!/usr/bin/env bash
# Phase 3 presence test against the LOCAL stack, with real Icecast connections (observe mode):
#   P-PLAY   logs in and streams the whole time            -> "playing" (server-observed), time accrues
#   P-IDLE   logs in, reports "paused", never streams      -> "online"
#   P-GONE   streams ~20 s, then the page "closes"         -> "online", listened capped at ~20 s
# Each stream uses its own user agent, like separate real devices (core merges same IP+agent+mount).
# Then simulated time: +7 min -> P-IDLE and P-GONE time out, P-PLAY (still connected) stays;
# next 00:30 -> daily reset ends P-PLAY. Takes ~2 min. Leaves stream auth OFF.
# NOTE: simulated time applies to the whole station, so any other branch signed in on this LOCAL
# copy at the time is also timed out / reset. Never run against prod.
set -uo pipefail
CONTAINER=${TRACKER_CONTAINER:-$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /azuracast\/azuracast|\/azuracast:|^azuracast/ {print $1; exit}')}
STATION=${STATION:-store}
STATION_TZ=$(curl -s "${BASE:-http://localhost:8080}/api/station/$STATION" | jq -r ".timezone // \"UTC\"")
cd "$(dirname "$0")/../../.."
BASE=${BASE:-http://localhost:8080}
ADMIN="$BASE/api/station/$STATION/branches"; PUB="$BASE/api/station/$STATION/branch"; STREAM="$BASE/listen/$STATION/radio.mp3"
KEY=$(cat backups/local-api-key 2>/dev/null) || { echo "Run tests/make-local-api-key.sh first"; exit 1; }
CLI="docker exec -u azuracast $CONTAINER azuracast_cli"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s  %s\n' "$1" "${2:-}"; }
expect() { # name jq-expr json
  if jq -e "$2" >/dev/null 2>&1 <<<"$3"; then ok "$1"; else bad "$1" "$(head -c 300 <<<"$3")"; fi
}
admin() { curl -s -H "X-API-Key: $KEY" -H 'Content-Type: application/json' "$@"; }
login() { curl -s -X POST "$PUB/login" -H 'Content-Type: application/json' -d "{\"pin\":\"$1\"}" | jq -r .token; }
beat()  { curl -s -X POST "$PUB/heartbeat" -H 'Content-Type: application/json' -d "{\"token\":\"$1\",\"state\":\"$2\",\"volume\":${3:-70}}"; }
presence() { $CLI branch-tracker:presence $STATION --json "$@" 2>/dev/null; }
status_of() { jq -c --arg c "$1" '.branches[] | select(.code==$c) | {status, src: .status_source, today: .listened_today_seconds}' <<<"$2"; }
cleanup() {
  for c in P-PLAY P-IDLE P-GONE; do
    id=$(admin "$ADMIN" | jq -r ".[] | select(.code==\"$c\") | .id"); [ -n "$id" ] && admin -o /dev/null -X DELETE "$ADMIN/$id"
  done
}

echo "Branch Tracker presence test (about 2 minutes)"
cleanup
declare -A PIN TOK
for c in P-PLAY P-IDLE P-GONE; do
  PIN[$c]=$(admin -X POST "$ADMIN" -d "{\"code\":\"$c\",\"name\":\"Presence $c\"}" | jq -r .pin)
done
$CLI branch-tracker:stream-auth $STATION observe >/dev/null 2>&1; sleep 6

for c in P-PLAY P-IDLE P-GONE; do TOK[$c]=$(login "${PIN[$c]}"); done
[[ ${TOK[P-PLAY]} =~ ^[a-f0-9]{64}$ ]] && ok "3 branches logged in (4-digit PINs)" || bad "login" "${TOK[*]}"

T0=$(date +%s)
curl -s -m 110 -A "BranchPlayer/P-PLAY" -o /dev/null "$STREAM?bt=${TOK[P-PLAY]}" & PLAY_PID=$!
curl -s -m 20 -A "BranchPlayer/P-GONE" -o /dev/null "$STREAM?bt=${TOK[P-GONE]}" &
beat "${TOK[P-PLAY]}" playing >/dev/null; beat "${TOK[P-IDLE]}" paused 40 >/dev/null; beat "${TOK[P-GONE]}" playing >/dev/null

sleep 50   # > one now-playing cycle after P-GONE disconnected at ~20 s
beat "${TOK[P-PLAY]}" playing >/dev/null; beat "${TOK[P-IDLE]}" paused 40 >/dev/null   # P-GONE's page is "closed": no more beats
sleep 30
beat "${TOK[P-PLAY]}" playing >/dev/null
P=$(presence)
ELAPSED=$(( $(date +%s) - T0 ))
expect "P-PLAY: playing, seen by the server" '.branches[] | select(.code=="P-PLAY") | .status=="playing" and .status_source=="stream"' "$P"
expect "P-PLAY: listening time ~ elapsed (${ELAPSED}s ±20)" ".branches[] | select(.code==\"P-PLAY\") | (.listened_today_seconds - $ELAPSED | fabs) <= 20" "$P"
expect "P-IDLE: online (signed in, not playing), volume 40" '.branches[] | select(.code=="P-IDLE") | .status=="online" and .session.volume==40' "$P"
expect "P-GONE: online, stream gone" '.branches[] | select(.code=="P-GONE") | .status=="online"' "$P"
# Core closes a listener row on its next now-playing cycle, so a drop can overcount by up to ~15 s.
expect "P-GONE: listened ~20 s (+1 NP cycle), not inflated" '.branches[] | select(.code=="P-GONE") | .listened_today_seconds >= 12 and .listened_today_seconds <= 40' "$P"
echo "        P-PLAY $(status_of P-PLAY "$P")  P-IDLE $(status_of P-IDLE "$P")  P-GONE $(status_of P-GONE "$P")"

# Simulated time: +7 minutes (station-local), while P-PLAY's stream is still connected.
LOCAL_PLUS7=$(TZ=$STATION_TZ date -d '+7 minutes' '+%Y-%m-%d %H:%M:%S')
P=$(presence --now="$LOCAL_PLUS7")
expect "+7 min: at least the 2 idle test sessions timed out" '.sync.timed_out >= 2' "$P"
expect "+7 min: P-IDLE and P-GONE offline" '[.branches[] | select(.code=="P-IDLE" or .code=="P-GONE") | .status] == ["offline","offline"]' "$P"
R=$(beat "${TOK[P-IDLE]}" paused)
expect "P-IDLE's player is told: timeout" '.reason == "timeout"' "$R"
R=$(beat "${TOK[P-PLAY]}" playing)
expect "P-PLAY survives (stream still connected)" '.success == true' "$R"

# Simulated time: next 00:30 + 1 min, station time.
NEXT_RESET=$(TZ=$STATION_TZ date -d 'tomorrow 00:31' '+%Y-%m-%d %H:%M:%S')
P=$(presence --now="$NEXT_RESET")
expect "00:31: daily reset ended P-PLAY" '.sync.daily_reset >= 1 and (.branches[] | select(.code=="P-PLAY") | .status=="offline")' "$P"
R=$(beat "${TOK[P-PLAY]}" playing)
expect "P-PLAY's player is told: daily_reset" '.reason == "daily_reset"' "$R"

kill $PLAY_PID 2>/dev/null; wait 2>/dev/null

# History + report
TODAY=$(TZ=$STATION_TZ date +%F)
S=$(admin "$ADMIN/sessions?from=$TODAY&to=$TODAY")
expect "history keeps P-GONE's ~20 s after its timeout" '.sessions[] | select(.branch.code=="P-GONE") | .listened_seconds >= 12 and .listened_seconds <= 40 and .session_seconds >= 12' "$S"
expect "sessions API: 3 sessions with end reasons" '[.sessions[] | select(.branch.code|startswith("P-")) | .end_reason] | sort == ["daily_reset","timeout","timeout"]' "$S"
R=$(admin "$ADMIN/report?from=$TODAY&to=$TODAY")
expect "report: P-PLAY has today's listening" ".rows[] | select(.branch.code==\"P-PLAY\") | .days[\"$TODAY\"].listened_seconds >= 60 and .total_sessions == 1" "$R"
CSV=$(admin "$ADMIN/sessions?from=$TODAY&to=$TODAY&format=csv")
grep -q "P-PLAY" <<<"$CSV" && grep -q "daily_reset" <<<"$CSV" && ok "sessions CSV export" || bad "sessions CSV export"

$CLI branch-tracker:stream-auth $STATION off >/dev/null 2>&1
cleanup
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
