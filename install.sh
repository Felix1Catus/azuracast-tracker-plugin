#!/usr/bin/env bash
# Tracker plugin installer for AzuraCast (Docker installs, e.g. made with AzuraCast's docker.sh).
#
#   curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-tracker-plugin/main/install.sh -o install-tracker.sh
#   sudo bash install-tracker.sh            # install or update
#   sudo bash install-tracker.sh --uninstall
#
# Options:
#   --dir PATH         AzuraCast directory, the one with docker-compose.yml (default: auto-detect)
#   --ref REF          Plugin version: a release tag such as v1.1.0 (default) or "main"
#   --analytics MODE   all | no_ip   AzuraCast listener analytics, which Tracker needs (default: all)
#   --yes, -y          Don't ask for confirmation
#   --force            Continue even if the AzuraCast version is not the tested series
#   --uninstall        Unlock every station's stream and remove the plugin mount (database tables are kept)
set -euo pipefail

REPO="Felix1Catus/azuracast-tracker-plugin"
DEFAULT_REF="v1.1.0"
TESTED_SERIES="0.23"            # developed and tested on AzuraCast 0.23.8
PLUGIN="branch-tracker"         # folder name is required: it defines the PHP namespace Plugin\BranchTracker
MARK="# managed-by: azuracast-tracker-plugin installer"

DIR=""; REF="$DEFAULT_REF"; ANALYTICS="all"; YES=0; FORCE=0; ACTION=install
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) DIR="$2"; shift 2 ;;
    --ref) REF="$2"; shift 2 ;;
    --analytics) ANALYTICS="$2"; shift 2 ;;
    -y|--yes) YES=1; shift ;;
    --force) FORCE=1; shift ;;
    --uninstall) ACTION=uninstall; shift ;;
    -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1 (see --help)"; exit 2 ;;
  esac
done
case "$ANALYTICS" in all|no_ip) ;; *) echo "--analytics must be all or no_ip"; exit 2 ;; esac

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }
confirm() {
  [ "$YES" = 1 ] && return 0
  [ -r /dev/tty ] || die "No terminal to confirm on; re-run with --yes."
  local a; read -r -p "$1 [y/N] " a </dev/tty; [[ "$a" =~ ^[Yy] ]]
}

# ---------------------------------------------------------------- environment
command -v docker >/dev/null 2>&1 || die "Docker not found. This installer is for Docker installs of AzuraCast; see INSTALL.md for other setups."
if docker compose version >/dev/null 2>&1; then DC=(docker compose); elif command -v docker-compose >/dev/null 2>&1; then DC=(docker-compose); else die "Docker Compose not found."; fi
docker info >/dev/null 2>&1 || die "Cannot talk to Docker. Run as root (sudo) or as a member of the docker group."

if [ -z "$DIR" ]; then
  for d in "$PWD" /var/azuracast /opt/azuracast /root /home/*/azuracast; do
    if [ -f "$d/docker-compose.yml" ] && grep -qiE 'image:.*azuracast' "$d/docker-compose.yml"; then DIR="$d"; break; fi
  done
fi
[ -n "$DIR" ] && [ -f "$DIR/docker-compose.yml" ] || die "AzuraCast not found. Pass --dir /path/to/azuracast (the folder containing docker-compose.yml)."
DIR=$(cd "$DIR" && pwd)
cd "$DIR"
grep -qiE 'image:.*azuracast' docker-compose.yml || die "$DIR/docker-compose.yml doesn't look like AzuraCast's."

container() {
  local id; id=$("${DC[@]}" ps -q web 2>/dev/null | head -1)
  [ -n "$id" ] && docker inspect -f '{{.Name}}' "$id" | sed 's#^/##'
}
C=$(container || true)
[ -n "$C" ] || die "AzuraCast's web container isn't running. Start it first: cd $DIR && ${DC[*]} up -d"
cli() { docker exec -u azuracast "$C" azuracast_cli "$@"; }
stations() { docker exec "$C" curl -fsS http://127.0.0.1:6010/api/stations 2>/dev/null | grep -oE '"shortcode":"[^"]+"' | cut -d'"' -f4; }

AZV=$(cli --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
say "AzuraCast ${AZV:-?} found in $DIR (container: $C)"

OV="$DIR/docker-compose.override.yml"
VOL_RO=":ro"
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce 2>/dev/null)" = "Enforcing" ]; then VOL_RO=":ro,z"; fi
VOLUME="./plugins/$PLUGIN:/var/azuracast/www/plugins/$PLUGIN$VOL_RO"

yq() { docker run --rm -v "$DIR:/workdir" -w /workdir mikefarah/yq:4 "$@"; }

wait_ready() {
  printf 'Waiting for AzuraCast'
  for _ in $(seq 1 120); do
    C=$(container || true)
    if [ -n "$C" ] && docker exec "$C" curl -fsS -o /dev/null http://127.0.0.1:6010/api/status 2>/dev/null; then echo " ready."; return 0; fi
    printf '.'; sleep 3
  done
  echo; die "AzuraCast didn't come back within 6 minutes. Check: ${DC[*]} logs -f web"
}

# ---------------------------------------------------------------- uninstall
if [ "$ACTION" = uninstall ]; then
  echo "This unlocks every station's stream, turns off the public-page PIN login, removes the plugin mount"
  echo "and recreates the AzuraCast container (about a minute of downtime). Branch data stays in the database."
  confirm "Uninstall Tracker?" || die "Cancelled."
  if docker exec "$C" test -f "/var/azuracast/www/plugins/$PLUGIN/events.php"; then
    for s in $(stations); do
      cli branch-tracker:stream-auth "$s" off >/dev/null 2>&1 && echo "  $s: stream auth off" || true
      cli branch-tracker:public-page "$s" off >/dev/null 2>&1 || true
    done
  fi
  if [ -f "$OV" ] && grep -q "plugins/$PLUGIN" "$OV"; then
    cp "$OV" "$OV.bak-$(date +%Y%m%d%H%M%S)"
    if head -1 "$OV" | grep -qF "$MARK" && [ "$(grep -cE '^\s*-\s' "$OV")" = 1 ]; then
      rm -f "$OV"
    else
      yq -i "del(.services.web.volumes[] | select(test(\"plugins/$PLUGIN\")))" docker-compose.override.yml \
        || die "Couldn't edit $OV automatically; remove the plugins/$PLUGIN volume line by hand."
    fi
  fi
  "${DC[@]}" up -d
  wait_ready
  docker exec "$C" rm -f /var/azuracast/www_tmp/app_routes.cache.php || true
  say "Tracker removed. Plugin files left in $DIR/plugins/$PLUGIN (delete them if you like)."
  exit 0
fi

# ---------------------------------------------------------------- install / update
if [ "${AZV%.*}" != "$TESTED_SERIES" ]; then
  warn "Tracker is developed and tested on AzuraCast 0.23.8; this server runs ${AZV:-an unknown version}."
  [ "$FORCE" = 1 ] || die "Re-run with --force to install anyway (at your own risk)."
fi

cat <<EOF

This will:
  1. put Tracker ($REF) in $DIR/plugins/$PLUGIN (an existing copy is kept as a .bak folder)
  2. mount it into AzuraCast via $(basename "$OV")
  3. recreate the AzuraCast container (stations restart; about a minute of downtime)
  4. run Tracker's database migrations and health check
  5. set AzuraCast analytics to "$ANALYTICS" (Tracker needs listener records)
Nothing else changes: streams stay open and public pages unchanged until you turn those on.
EOF
warn "If you haven't made a backup today: cd $DIR && ./docker.sh backup   (or Administration > Backups)"
confirm "Continue?" || die "Cancelled."

say "Fetching Tracker $REF"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
SRC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)
if [ -n "$SRC_DIR" ] && [ -f "$SRC_DIR/events.php" ] && [ -f "$SRC_DIR/services.php" ] && [ "${TRACKER_FROM_DOWNLOAD:-0}" != 1 ]; then
  echo "Using the plugin files next to this script ($SRC_DIR)."
  (cd "$SRC_DIR" && tar --exclude=.git -cf - .) | tar -xf - -C "$TMP"
else
  command -v curl >/dev/null 2>&1 || die "curl not found."
  URL="https://codeload.github.com/$REPO/tar.gz/$REF"
  curl -fsSL "$URL" | tar -xz -C "$TMP" --strip-components=1 || die "Download failed: $URL"
fi
[ -f "$TMP/events.php" ] && [ -f "$TMP/services.php" ] || die "The downloaded files don't look like the Tracker plugin."
NEWV=$(cat "$TMP/VERSION" 2>/dev/null || echo "?")

mkdir -p "$DIR/plugins"
DEST="$DIR/plugins/$PLUGIN"
if [ -d "$DEST" ]; then
  OLDV=$(cat "$DEST/VERSION" 2>/dev/null || echo "?")
  mv "$DEST" "$DEST.bak-$(date +%Y%m%d%H%M%S)"
  echo "Previous copy ($OLDV) moved aside."
fi
mkdir -p "$DEST"
(cd "$TMP" && tar -cf - .) | tar -xf - -C "$DEST"
chmod -R a+rX "$DEST"
chmod +x "$DEST"/*.sh 2>/dev/null || true
echo "Tracker $NEWV in $DEST"

say "Mounting the plugin into AzuraCast"
if [ ! -f "$OV" ]; then
  printf '%s\nservices:\n  web:\n    volumes:\n      - %s\n' "$MARK" "$VOLUME" > "$OV"
  echo "Created $(basename "$OV")."
elif grep -q "plugins/$PLUGIN" "$OV"; then
  echo "$(basename "$OV") already mounts the plugin."
else
  cp "$OV" "$OV.bak-$(date +%Y%m%d%H%M%S)"
  echo "Merging into your existing $(basename "$OV") (backup saved next to it)."
  yq -i ".services.web.volumes += [\"$VOLUME\"]" docker-compose.override.yml \
    || die "Couldn't merge automatically. Add this under services: > web: > volumes: in $OV, then re-run:   - $VOLUME"
fi
"${DC[@]}" config -q || die "docker compose rejects the configuration; check $OV (a backup of the previous file is next to it)."

say "Recreating the AzuraCast container"
"${DC[@]}" up -d
wait_ready
docker exec "$C" test -f "/var/azuracast/www/plugins/$PLUGIN/events.php" || die "The plugin isn't visible inside the container; check the volume in $OV."

say "Activating (migrations, caches, health check)"
"$DEST/reload.sh" "$C"

say "Enabling AzuraCast analytics ($ANALYTICS)"
cli azuracast:settings:set analytics "$ANALYTICS" >/dev/null && echo "done"

say "Tracker $NEWV is installed"
ST=$(stations | tr '\n' ' ')
cat <<EOF

Next steps (per station; your stations: ${ST:-none yet}):

  Open the board:       log in to AzuraCast, open a station, click the green "Branch Board" button
                        (or go to /branch-tracker/<station>)
  Add branches:         board > Manage > Add branch   (each gets a PIN, shown once)
  Track branch streams: docker exec -u azuracast $C azuracast_cli branch-tracker:stream-auth <station> observe
  PIN login on the station's public page:
                        docker exec -u azuracast $C azuracast_cli branch-tracker:public-page <station> on
  Lock the stream to signed-in branches (when everyone uses the new player):
                        docker exec -u azuracast $C azuracast_cli branch-tracker:stream-auth <station> enforce

Update later by re-running this script with --ref <new tag>. Remove with --uninstall.
EOF
