#!/usr/bin/env bash
# Creates a LOCAL-ONLY test admin (branch-tracker-test@localhost, Super Administrator) in the dev container,
# with a generated password saved to backups/local-test-admin (gitignored, mode 600). Used to look at the
# dashboard in a browser on localhost. Never run against prod.
set -euo pipefail
CONTAINER=${TRACKER_CONTAINER:-$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /azuracast\/azuracast|\/azuracast:|^azuracast/ {print $1; exit}')}
STATION=${STATION:-store}
cd "$(dirname "$0")/../../.."
C=$CONTAINER
EMAIL=branch-tracker-test@localhost
OUT=backups/local-test-admin
docker inspect "$C" >/dev/null 2>&1 || { echo "Local container '$C' not running."; exit 1; }
SQL() { docker exec -i "$C" sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -N'; }

PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)
HASH=$(docker exec "$C" php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$PASS")

if [ -z "$(echo "SELECT id FROM users WHERE email='$EMAIL';" | SQL)" ]; then
  echo "INSERT INTO users (email, auth_password, name, created_at, updated_at)
        VALUES ('$EMAIL', '$HASH', 'Branch Tracker Test', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
        INSERT INTO user_has_role (user_id, role_id)
        SELECT u.id, r.id FROM users u, role r WHERE u.email='$EMAIL' AND r.name='Super Administrator';" | SQL
  echo "Created $EMAIL (Super Administrator)."
else
  echo "UPDATE users SET auth_password='$HASH' WHERE email='$EMAIL';" | SQL
  echo "Reset password for $EMAIL."
fi
umask 077
printf 'email=%s\npassword=%s\nurl=http://localhost:8080/login\n' "$EMAIL" "$PASS" > "$OUT"
echo "Credentials saved to $OUT"
