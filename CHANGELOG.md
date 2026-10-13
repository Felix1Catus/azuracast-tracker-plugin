# Changelog

## 1.6.0 (2026-10-13)

- **Company per branch** (Tradeline or XPRS, `Branch::COMPANIES`): a dropdown per branch under Manage (and when
  adding one), shown as a tag on the board. A **company filter** on the board (All / Tradeline / XPRS / No company
  set) narrows the table and the summary counts, and the Manage list; it's remembered in the browser.
  New column `branch_branch.company` (migration); set through `PUT /branches/{id}` with `company`.

## 1.5.0 (2026-10-13)

- **PIN column on the board** with a Copy button, in the live table and under Manage, for users who can manage
  branches (and administrators) only; viewers and the public board never get PINs. They come from a separate
  endpoint, `GET /api/station/{id}/branches/pins` ("manage branches").
- PINs are now also kept encrypted (`branch_branch.pin_encrypted`, libsodium secretbox with a key derived from the
  PIN key, never stored), next to the HMAC that login uses. Creating a branch or setting a PIN stores it.
- `branch-tracker:recover-pins` fills the column once for branches created before this: each PIN is found from
  its HMAC by trying every PIN of 4 to 6 digits (`--max-digits`). PINs and logins don't change.

## 1.4.0 (2026-10-13)

- **One-time "what's new" notice in the branch player**, shown once per branch (not per device) in Egyptian Arabic,
  right to left, over the player while the music keeps playing. Branches that are already signed in get it with
  their next heartbeat. Dismissing it is recorded on the branch (`branch_branch.notice_seen`, new migration), so
  it doesn't come back on another device. The text and its id live in `src/Service/BranchNotice.php`; changing
  the id shows a new notice to every branch once.


## 1.3.2 (2026-10-11)

- On **AzuraCast Retail**, which has a native "Branch Board" item in the station menu and ships Tracker built in,
  the floating "Branch Board" button is no longer injected. Stock AzuraCast installs are unchanged.
- Tests: the direct-Icecast check runs inside the container, so it also works where port 8000 isn't published.

## 1.3.1 (2026-10-10)

- **Source code link** on the branch player and the board when the server runs AzuraCast Retail, which serves
  its source at `/source` (AGPL-3.0). Nothing changes on a stock AzuraCast install.

## 1.3.0 (2026-10-10)

- **Public read-only board:** a switch in the board's Manage tab creates a private link
  (`/branch-tracker/<station>/public/<key>`) that shows Live, Sessions and Report without logging in.
  It hides IP addresses and devices, has no CSV export and no write actions. Turning the switch off kills the
  link, and "New link" replaces it. Settings API: `public_board`, `regenerate_public_key`.
- New migration: `branch_station_settings.public_board_key`.

## 1.2.1 (2026-10-10)

- **Favicon** on the branch player and the board, using AzuraCast's own browser icon (your uploaded one, or AzuraCast's default).

## 1.2.0 (2026-10-10)

- **"Log out all / Log in again"** button on the board (Live tab, needs Manage Branches) and
  `POST /api/station/{station}/branches/logout-all`. It ends every open session; each player shows the PIN
  screen on its next check-in (60 s at most). Sessions show it as "Logged out by admin (all)" (end reason
  `logout_all`).

## 1.1.0 (2026-10-10)

- **New branch player design:** record-style album art that spins while playing, a real progress ring,
  blurred album-art backdrop, LIVE / PAUSED pill, "Up next", and the instance name as a wordmark.
- **On-screen PIN keypad** for touch screens (physical keyboard still works). The PIN auto-submits at
  full length, and a wrong PIN shakes the card.
- **Fix:** the play/pause icon now switches correctly. SVG elements ignore `.hidden`; it now uses
  `toggleAttribute`.

## 1.0.0 (2026-10-10): first public release

Developed and tested on **AzuraCast 0.23.8** (Docker; PHP 8.5.11, MariaDB 11.8.3, Icecast-KH 2.4.0-kh22,
Liquidsoap 2.4.5).

- **Branches:** add, rename, enable/disable, delete. PINs (4 digits by default, configurable 4–8) are stored
  as a keyed hash, unique per station, with weak PINs refused. CSV import.
- **Branch player:** PIN login, one device per branch, auto-reconnect, volume and keyboard shortcuts. It can
  replace the station's own public page (`public-page on`), or run at `/public/<station>/branch`.
- **Stream access modes:** `off` / `observe` / `enforce`, using Icecast URL authentication through
  AzuraCast's per-mount custom configuration.
- **Server-side presence:** links Icecast connections to AzuraCast listener records. Inactivity sign-out
  after 5/15 min, daily sign-out at 00:30 station time.
- **Board inside AzuraCast:** a "Branch Board" button on station pages, with Live, Sessions, Report
  (heat map) and Manage tabs, CSV exports and 10 s auto-refresh.
- **Permissions:** "View Branches" and "Manage Branches".
- **Installer:** `install.sh` for Docker installs (install, update, uninstall), plus a Dockerfile for
  custom images.
