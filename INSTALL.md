# Installing Tracker (AzuraCast plugin)

Tracker adds branch login, live presence, reports and an admin board to an AzuraCast station.
It's a **plugin**: AzuraCast's own code isn't changed, and it runs on the official image.

| | |
|---|---|
| Developed and tested on | **AzuraCast 0.23.8** (Docker, Oct 2026 build): PHP 8.5.11, MariaDB 11.8.3, Icecast-KH 2.4.0-kh22, Liquidsoap 2.4.5 |
| Supported | AzuraCast **0.23.x** on Docker. Older versions are not supported (the plugin uses current AzuraCast APIs) |
| Needs | An AzuraCast station using **Icecast** (the default). SHOUTcast and remote relays aren't supported |
| Folder name | Must be **`branch-tracker`** (the PHP namespace `Plugin\BranchTracker\` is derived from it) |
| Container name | `azuracast` on a standard install. Check yours with `docker ps` |

---

## 0. Quick install with the installer (Docker), recommended

On the AzuraCast server:

```bash
curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-tracker-plugin/main/install.sh -o install-tracker.sh
less install-tracker.sh                     # optional: read it first
sudo bash install-tracker.sh                # install, or update to the default release
```

What it does, step by step (it asks before changing anything):
1. Finds AzuraCast (the folder with `docker-compose.yml`: current dir, `/var/azuracast`, `/opt/azuracast`
   or `/root`; or use `--dir`). It also finds the running container and checks the version (0.23.x;
   `--force` to override).
2. Downloads the plugin release into `<azuracast>/plugins/branch-tracker`. An existing copy is moved
   aside as `.bak-<date>`.
3. Adds the mount to `docker-compose.override.yml`. It creates the file, or merges into yours with a
   backup, using the `mikefarah/yq` container. It handles SELinux and validates with `docker compose config`.
4. Recreates the AzuraCast container and waits until it answers (about a minute of downtime).
5. Runs `reload.sh`: lint, route cache, PHP restart, database migrations, health check.
6. Sets AzuraCast analytics to `all` (`--analytics no_ip` to avoid storing listener IPs).

Options: `--dir PATH`, `--ref vX.Y.Z|main`, `--analytics all|no_ip`, `--yes`, `--force`, `--uninstall`.
Re-run it with a newer `--ref` to update. Use `--uninstall` to unlock all streams, turn off the
public-page login and remove the mount (data is kept).

Then continue with **1.5 Verify** and **1.6 Add your branches** below.

---

## 1. Docker: standard install (`docker.sh`), manual

This is the normal AzuraCast install. Its files are in the directory where you ran `docker.sh`, usually
`/var/azuracast` (older installs: the directory holding `docker-compose.yml`).

### 1.1 Back up and pin the version

```bash
cd /var/azuracast
./docker.sh backup                       # or Administration → Backups in the web UI
grep AZURACAST_VERSION .env              # note the version you're on
```

Turn off automatic updates until you've confirmed Tracker works with a new release: Administration →
System Maintenance → Updates, or don't run the `updater` container.

### 1.2 Get the plugin

```bash
cd /var/azuracast
mkdir -p plugins
git clone https://github.com/Felix1Catus/azuracast-tracker-plugin.git plugins/branch-tracker
# no git? download the ZIP from GitHub and extract it so the files end up in plugins/branch-tracker/
ls plugins/branch-tracker/events.php     # must exist
```

### 1.3 Mount it into the container

Create **`docker-compose.override.yml`** next to `docker-compose.yml`. AzuraCast's updates replace
`docker-compose.yml` but **keep** the override file.

```yaml
services:
  web:
    volumes:
      - ./plugins/branch-tracker:/var/azuracast/www/plugins/branch-tracker:ro
```

- If an override file **already exists**, add only the `volumes` line under `services: web:`.
  Compose merges volume lists.
- On SELinux hosts (RHEL, Fedora, Rocky), use `:ro,z` instead of `:ro`.

Recreate the container. This briefly stops the station.

```bash
docker compose up -d          # older Docker: docker-compose up -d
```

### 1.4 Activate

```bash
plugins/branch-tracker/reload.sh azuracast
```

`reload.sh` lints the plugin, clears AzuraCast's route cache, restarts PHP, runs the plugin's database
migrations and checks health. **It must end with `==> ok`.** Then turn on what Tracker needs:

```bash
CLI="docker exec -u azuracast azuracast azuracast_cli"
$CLI azuracast:settings:set analytics all        # presence uses AzuraCast's listener records ("no_ip" also works)
$CLI branch-tracker:stream-auth <station> observe   # <station> = the station's short name, e.g. "store"
$CLI branch-tracker:public-page <station> on        # the station's own public page shows the PIN login + player
```

### 1.5 Verify (all must pass)

```bash
curl -s http://localhost/api/branch-tracker/ping                 # {"plugin":"branch-tracker",...,"ok":true}
docker exec azuracast supervisorctl status | grep -v RUNNING     # only "startup ... EXITED" may appear
$CLI branch-tracker:stream-auth <station>                        # /radio.mp3: observe
$CLI list branch-tracker                                         # import, presence, stream-auth
```

In the browser:
1. Log in to AzuraCast and open the station. A green **Branches** button appears bottom-right.
2. It opens the board at `/branch-tracker/<station>`.
3. Under **Manage**, add a test branch. Note its PIN.
4. Open the station's public page `/public/<station>` (with `public-page on`) or `/public/<station>/branch`, and enter that PIN. The board's **Live** tab shows the branch as
   "Signed in" and then "Playing" within about 30 s.

### 1.6 Add your branches

Either add them in the board (**Manage → Add branch**), or import a CSV with the columns
`PIN, Branch Name, Branch ID`:

```bash
docker cp branches.csv azuracast:/tmp/branches.csv
docker exec azuracast chown azuracast /tmp/branches.csv
$CLI branch-tracker:import <station> /tmp/branches.csv --dry-run      # check first
$CLI branch-tracker:import <station> /tmp/branches.csv                # keep the CSV's PINs
$CLI branch-tracker:import <station> /tmp/branches.csv --new-pins     # or generate new PINs (printed once)
docker exec azuracast rm /tmp/branches.csv
```

Branches use the station's normal public page, `https://<your-domain>/public/<station>`. With
`public-page on` (or the checkbox in the board's **Manage** tab) it shows the PIN screen and branch player
instead of AzuraCast's standard player. `/public/<station>/branch` always shows it, whatever the switch says.
Keep the switch **off** during a pilot if branches not yet migrated still use a custom player on that page.

### 1.7 Lock the stream (when every branch uses the new player)

```bash
$CLI branch-tracker:stream-auth <station> enforce
```

From now on, only a signed-in branch can play the stream, and `/public/<station>` always shows the PIN login. Changing the mode restarts the station
(a few seconds of silence); players reconnect on their own.

---

## 2. Docker: custom image (CI, Kubernetes, immutable hosts)

The repository includes a `Dockerfile`:

```bash
git clone https://github.com/Felix1Catus/azuracast-tracker-plugin.git
docker build -t my-azuracast:0.23.8-tracker --build-arg AZURACAST_VERSION=0.23.8 azuracast-tracker-plugin
```

Set `image: my-azuracast:0.23.8-tracker` for the `web` service, then run `docker compose up -d`. The plugin's
migrations run when the container starts. After that, follow steps 1.4–1.7 (`reload.sh` also works against a
container built this way).

## 3. Docker: AzuraCast development checkout

With a git checkout of AzuraCast and `docker-compose.dev.yml`: clone the plugin into the checkout's
`plugins/branch-tracker/`. The dev compose mounts the whole source tree. Then run `reload.sh`, using your
dev container's name.

---

## 4. Without Docker (best effort, unsupported)

AzuraCast itself only supports Docker. The old Ansible/"traditional" installer was retired in 0.17 and
can't run 0.23.x. If you maintain your own non-Docker build of AzuraCast **0.23.8** (PHP 8.4+, MariaDB,
Redis, nginx, Icecast-KH, supervisor), Tracker needs only what AzuraCast already uses:

```bash
cd /var/azuracast/www                          # your AzuraCast code root (where composer.json is)
git clone https://github.com/Felix1Catus/azuracast-tracker-plugin.git plugins/branch-tracker
chown -R azuracast:azuracast plugins/branch-tracker          # the user PHP runs as
sudo -u azuracast php backend/bin/console migrations:migrate --no-interaction
rm -f /var/azuracast/www_tmp/app_routes.cache.php             # your www_tmp path
systemctl restart php8.4-fpm                                   # and your now-playing / worker processes
sudo -u azuracast php backend/bin/console azuracast:settings:set analytics all
sudo -u azuracast php backend/bin/console branch-tracker:stream-auth <station> observe
```

Requirements:
- **cron** runs `azuracast:sync:run` every minute (Tracker's presence job rides on it).
- AzuraCast's **internal URL** (`http://127.0.0.1:6010` in Docker) must be reachable from Icecast.
  `stream-auth` asks AzuraCast for it. If your internal nginx listens elsewhere, fix that in AzuraCast, not here.
- **Icecast-KH** with URL authentication (`listener_add`), as AzuraCast ships it.

`reload.sh` is Docker-only. Without Docker, use the commands above after every update.

---

## Configuration (optional, in `azuracast.env`, then recreate the container)

| Variable | Default | Meaning |
|---|---|---|
| `BRANCH_TRACKER_PIN_LENGTH` | `4` | Length of new and changed PINs (4–8). Existing PINs keep working |
| `BRANCH_TRACKER_PIN_KEY` | the install's unique ID | Secret used to hash PINs. **Changing it invalidates every PIN**; set it before adding branches |

Permissions (Administration → Roles): **View Branches** opens the board; **Manage Branches** adds, edits,
deletes and sets PINs. Users with "administer all" have both.

## Updating Tracker

```bash
cd /var/azuracast/plugins/branch-tracker && git pull
/var/azuracast/plugins/branch-tracker/reload.sh azuracast       # must end with "==> ok"
```

## Updating AzuraCast

1. Update AzuraCast as usual. The override file keeps the plugin mounted.
2. Run `reload.sh` (clears caches, runs any new migrations).
3. Repeat the checks in 1.5. If something breaks, roll back AzuraCast with your backup and open an issue
   with the AzuraCast version.

## Uninstalling

```bash
$CLI branch-tracker:stream-auth <station> off      # unlock the stream FIRST
```

Remove the volume line from `docker-compose.override.yml`, then run `docker compose up -d`. The `branch_*`
tables stay and do no harm. To drop them, run
`DROP TABLE branch_session_listener, branch_session, branch_branch;`.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `reload.sh` says "NOT RUNNING" or the site is blank after install | A PHP error stopped AzuraCast's startup. Check `docker exec azuracast sh -c 'tail -50 /var/azuracast/www_tmp/app-*.log'`, fix it, then `docker compose restart` |
| Station "offline", "Record not found" | `sync_disabled` was left on (it happens after restores): `$CLI azuracast:settings:set sync_disabled false` |
| Plugin URLs return 405 / 404 | Stale route cache. Run `reload.sh` |
| Changes to plugin files don't apply | PHP opcache re-reads files every 60 s. Run `reload.sh` |
| No **Branches** button | The user lacks "View Branches", or the page was cached. Hard-reload the station page |
| Board shows "reported" and 0 listening time | Stream auth is `off`, or analytics is `none` (see 1.4) |
| Branch keeps being signed out "on another device" | Two devices use the same PIN. The rule is one device per branch |
| Logins return 429 | Too many wrong PINs: 5 per IP per minute, 20 per station per 5 minutes. Wait and retry |
