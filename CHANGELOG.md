# Changelog

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
