#!/usr/bin/env bash
# Creates a LOCAL test API key for user #1 in the local dev container (never prod) and saves it to
# backups/local-api-key (gitignored). Same format as AzuraCast's own keys: "identifier:verifier",
# stored as (id=identifier, verifier=sha512(verifier)).
set -euo pipefail
CONTAINER=${TRACKER_CONTAINER:-$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /azuracast\/azuracast|\/azuracast:|^azuracast/ {print $1; exit}')}
STATION=${STATION:-store}
cd "$(dirname "$0")/../../.."
OUT=backups/local-api-key
C=$CONTAINER
docker inspect "$C" >/dev/null 2>&1 || { echo "Local container '$C' not running."; exit 1; }

if [ -s "$OUT" ]; then echo "Key already exists in $OUT"; exit 0; fi

ID=$(openssl rand -hex 8)
VER=$(openssl rand -hex 16)
HASH=$(printf '%s' "$VER" | sha512sum | cut -d' ' -f1)
docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' <<SQL
INSERT INTO api_keys (id, user_id, verifier, comment) VALUES ('$ID', 1, '$HASH', 'local dev tests (branch-tracker)');
SQL
umask 077
printf '%s:%s\n' "$ID" "$VER" > "$OUT"
echo "Saved local test API key to $OUT"
