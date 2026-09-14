# Contract: API timezone alignment (FR-015)

## Application config

`config/app.php`: `'timezone' => env('APP_TIMEZONE', 'UTC')` (today the value is hard-coded to `'UTC'`, so the
installer's `APP_TIMEZONE` has no effect).

## install.sh

| Input | Behaviour |
|-------|-----------|
| `--timezone TZ` or `ISPC_REST_TIMEZONE=TZ` | Validate `TZ` against PHP `timezone_identifiers_list()`; invalid → abort with error. Write `APP_TIMEZONE=TZ`, record `TIMEZONE_MODE="explicit"`. |
| neither given | Detect: `timedatectl show -p Timezone --value` → `/etc/timezone` → `readlink -f /etc/localtime` without `/usr/share/zoneinfo/`. Valid → `APP_TIMEZONE=<detected>`, `TIMEZONE_MODE="auto"`. Nothing valid → `APP_TIMEZONE=UTC`, `TIMEZONE_MODE="auto"`, warning printed. |

`/etc/ispconfig-rest/install.conf` gains:

```sh
TIMEZONE="Europe/Prague"
TIMEZONE_MODE="auto"   # or "explicit"
```

`--help` documents `--timezone TZ  API timezone (default: the server's system timezone)`.

## ispconfig-rest update

1. After `git reset` and `composer install`, before `config:cache`:
   - `TIMEZONE_MODE` is `auto` or unset → detect as above; if valid and different from the `.env` value, replace
     only the `APP_TIMEZONE=` line (append it when missing), update `TIMEZONE`/`TIMEZONE_MODE="auto"` in
     `install.conf`, print `Timezone set to <TZ>`.
   - `TIMEZONE_MODE="explicit"` → leave `.env` unchanged.
2. Existing installations (no `TIMEZONE_MODE`) are treated as `auto`: the installer previously always wrote `UTC`
   without asking, so no explicit choice exists.

## ispconfig-rest status

Adds one line: `timezone: <APP_TIMEZONE> (system: <detected>, mode: auto|explicit)` and a warning when the two
differ.

## Verification

See quickstart.md §4.
