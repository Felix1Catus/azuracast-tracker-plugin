# Tracker: branch tracking for AzuraCast

**Tracker** turns an [AzuraCast](https://www.azuracast.com/) station into an in-store radio for a chain of
branches (shops, restaurants, offices). Each branch signs in to the station's player with a PIN. You see
live who is playing, how long each branch listened, and full history, all inside AzuraCast.

It's an AzuraCast **plugin**: no fork, no changes to AzuraCast's code, and it runs on the official Docker image.

| | |
|---|---|
| **Developed and tested on** | **AzuraCast 0.23.8** (Docker, Oct 2026 build) · PHP 8.5 · MariaDB 11.8 · Icecast-KH 2.4.0-kh22 · Liquidsoap 2.4.5 |
| **Works with** | AzuraCast **0.23.x** on Docker. Older versions are not supported |
| **Also runs on** | [AzuraCast Retail](https://github.com/Felix1Catus/azuracast-retail), our AzuraCast fork for in-store radio. There, the player and board also link the server's source code (`/source`) |
| **Station requirements** | Icecast frontend (AzuraCast's default) |
| **License** | Apache-2.0 |

## Features

- **PIN login for branches** on the station's own public page, with the same URL and a clean player:
  album art, progress ring, volume, keyboard shortcuts, automatic reconnect.
- **One device per branch:** a login on another device signs the first one out.
- **Live board inside AzuraCast:** a green **Branch Board** button on every station page (on AzuraCast Retail: a
  **Branch Board** item in the station menu), showing each
  branch as *Playing*, *Signed in · not playing* or *Offline*, with listening time today, volume, last seen
  and IP. It refreshes itself every 10 s.
- **Sessions and reports:** login history (who, when, how it ended, how long they listened) and a per-day
  listening heat map, with CSV export for both.
- **Log out all / Log in again:** one button signs every branch out; each player shows the PIN screen
  within a minute.
- **Public read-only board:** an optional private link that shows the board without a login (no IPs, no changes),
  switched on and off in the Manage tab.
- **Manage branches:** add, rename, enable/disable, set or generate PINs, delete, and set the **company** each
  branch belongs to (Tradeline or XPRS).
- **PIN column with Copy** for branch managers and administrators, on the board and under Manage. PINs are
  stored as a hash for login plus an encrypted copy for this column; viewers and the public board never get them.
- **Company filter** on the board (All / Tradeline / XPRS / No company set): narrows the table, the summary
  counts and the Manage list; each browser remembers its choice.
- **One-time "what's new" notice** in the branch player, in Egyptian Arabic, once per branch (not per device);
  players already signed in get it with their next heartbeat. Text and id: `src/Service/BranchNotice.php`.
- **Optional stream lock:** only signed-in branches can play the stream, both through `/listen/…` and
  directly on Icecast.
- **Server-side presence:** listening time comes from the actual Icecast connections, not the browser.
- **Daily sign-out** at 00:30 station time, plus automatic sign-out after inactivity.

## Install (Docker, about 2 minutes)

On the AzuraCast server:

```bash
curl -fsSL https://raw.githubusercontent.com/Felix1Catus/azuracast-tracker-plugin/main/install.sh -o install-tracker.sh
sudo bash install-tracker.sh
```

The installer:
- finds your AzuraCast install and checks its version
- downloads the plugin and mounts it into the container through `docker-compose.override.yml` (merging with
  an existing file if you have one)
- restarts AzuraCast and runs the plugin's migrations
- verifies everything

Streams and public pages stay as they are until you switch them on.

Manual installation, a custom Docker image, development checkouts, non-Docker setups, updating,
uninstalling and troubleshooting are all covered in **[INSTALL.md](INSTALL.md)**.

## First steps

```bash
CLI="docker exec -u azuracast azuracast azuracast_cli"      # "azuracast" = your container name

$CLI branch-tracker:stream-auth <station> observe    # start tracking branch streams (stream stays open to everyone)
$CLI branch-tracker:public-page <station> on         # the station's public page shows the PIN login + player
```

1. In AzuraCast, open the station and click **Branch Board**, then **Manage → Add branch**. Note the PIN.
2. Open `https://<your-server>/public/<station>` on the branch's device and enter the PIN.
3. Within about 30 s the board shows the branch as **Playing**.

When every branch uses the new player, lock the stream:
`$CLI branch-tracker:stream-auth <station> enforce`

## Rules at a glance

| Rule | Value |
|---|---|
| Who signs in | A **branch** (not a person, not an AzuraCast account), with its own PIN |
| Devices | **One device per branch.** A new login elsewhere signs the first device out within 60 s ("signed in on another device") |
| PINs | **4 digits** by default (`BRANCH_TRACKER_PIN_LENGTH`, 4–8), unique per station, no repeated or sequential digits. Visible to branch managers in the board's PIN column. The key is `BRANCH_TRACKER_PIN_KEY` if set, else the install's unique id; keep it when moving servers, or the PINs stop working |
| Wrong PINs | 5 attempts per IP per minute; 20 failures per station per 5 min, then logins pause (HTTP 429) |
| Player check-in | Every **60 s**, plus right after play / pause / volume changes |
| Presence | AzuraCast scans Icecast's listeners every **~10–15 s**; Tracker's job runs **every minute** |
| Inactivity sign-out | No stream and no check-in for **5 min**, or no check-in at all for **15 min** |
| Daily sign-out | **00:30 station time**, every day |
| Closing the tab | Does **not** sign out (presence comes from the stream) |
| Stream access | `off` = open (stock) · `observe` = open, branch streams recorded · `enforce` = signed-in branches only |
| Public page | `branch-tracker:public-page <station> on`, or the board's Manage tab: `/public/<station>` shows the PIN login + player. Always on with `enforce`. `/public/<station>/branch` always works |
| Board | AzuraCast users with **View Branches** (and **Manage Branches** to change things); "administer all" has both |

## Reference

### Pages

| URL | What |
|---|---|
| `/public/<station>` | The station's public page: PIN login + branch player when switched on |
| `/public/<station>/branch` | The branch player, always |
| `/branch-tracker/<station>` | The board: Live · Sessions · Report · Manage (AzuraCast login required) |
| `/branch-tracker/<station>/public/<key>` | Public read-only board, when switched on (link shown in Manage) |

### CLI (`azuracast_cli …` inside the container)

| Command | Purpose |
|---|---|
| `branch-tracker:stream-auth <station> [off\|observe\|enforce]` | Show or set stream access (restarts the station) |
| `branch-tracker:public-page <station> [on\|off]` | Show or set the PIN login on the public page |
| `branch-tracker:import <station> <file.csv> [--dry-run] [--new-pins] [--legacy-pins]` | Import branches from a CSV (`PIN, Branch Name, Branch ID`), upserting by branch ID |
| `branch-tracker:presence <station> [--now="Y-m-d H:i"] [--json]` | Run the presence job now and print live status |
| `branch-tracker:recover-pins [--max-digits=6]` | Fill the PIN column once for branches whose PIN was set before PINs were kept (found from their hash; PINs don't change) |

### API

Board/admin endpoints need an AzuraCast session or an `X-API-Key`. Player endpoints are public and rate-limited.

```
GET    /api/station/{station}/branches                    list branches (no PINs)
GET    /api/station/{station}/branches/pins               [{id, pin}] for the PIN column ("manage branches" only)
POST   /api/station/{station}/branches                    {code, name, pin?} -> 201, includes the PIN once
GET    /api/station/{station}/branches/{id}
PUT    /api/station/{station}/branches/{id}               {code?, name?, company?, is_enabled?}
DELETE /api/station/{station}/branches/{id}
POST   /api/station/{station}/branches/{id}/pin           {pin?} -> set or generate a PIN; signs the branch out
POST   /api/station/{station}/branches/logout-all         sign out every branch; players ask for the PIN again
GET    /api/station/{station}/branches/live               live status of every branch
GET    /api/station/{station}/branches/sessions           ?from=&to=[&branch_id=][&format=csv]
GET    /api/station/{station}/branches/report             ?from=&to=[&format=csv]
GET    /api/station/{station}/branches/settings           public page login, stream auth mode
PUT    /api/station/{station}/branches/settings           {public_page_login: bool}

POST   /api/station/{station}/branch/login                {pin} -> {token, branch, heartbeat_seconds, notice}
POST   /api/station/{station}/branch/heartbeat            {token, volume?, state?} -> {..., notice}
POST   /api/station/{station}/branch/notice-seen          {token, id}: the branch has read the notice
POST   /api/station/{station}/branch/logout               {token}
GET    /api/branch-tracker/ping                           health check
```

### How presence works

- **Stream links:** when a branch's player connects, Icecast asks Tracker (URL authentication) whether the
  token is valid, and Tracker records which Icecast connection belongs to which branch.
- **Listening time:** every minute Tracker matches those connections to AzuraCast's own listener records.
  Listening time is connect to disconnect, capped by the player's last check-in, so a stalled worker or a
  closed page can't inflate it.
- **Requirements:** presence needs AzuraCast **analytics** set to `all` or `no_ip` (the installer does this)
  and stream auth `observe` or `enforce`.

## Development

```
events.php / services.php   plugin wiring (routes, migrations, permissions, CLI, sync task, middleware)
src/                        entities, migrations, services, controllers, CLI commands, middleware
templates/                  player.html, dashboard.html, nav.js (self-contained HTML/CSS/JS)
reload.sh                   apply changes to a running container: lint, route cache, PHP restart, migrate, health
tests/                      shell smoke tests (API, sessions, presence) for a local development stack
```

After changing files, run `./reload.sh <container>`. The tests in `tests/` expect a local development copy
(see their headers). They create and delete their own test branches, and `session-smoke.sh` restarts the
station.

## License

Copyright 2026 Felix1Catus. Licensed under the [Apache License 2.0](LICENSE).
Tracker is an independent plugin (Apache-2.0). AzuraCast itself is AGPL-3.0-or-later; see NOTICE. It is not affiliated with or endorsed by the AzuraCast project.
"AzuraCast" is used only to describe compatibility.
