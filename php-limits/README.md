# PHP-FPM resource limits (cgroups)

For automatic installation/upgrades from the REST host, use
`sudo ispconfig-rest php-limits:install` (the same as
`server-tools install --components php-limits`). It is not part of the default
component selection because installation restarts each PHP-FPM service once.
See the [server tools CLI guide](../server-tools/README.md) for SSH setup and dry runs.

Limits are set per account by administrator keys (`web_resource_limits` on
`POST/PUT /clients`, for example from the WHMCS product's resource profile). This
worker, installed on **each ISPConfig webserver**, applies them with cgroup v2:

- every limited account is a systemd slice `ispconfig-client{ID}.slice` holding the
  account limits (CPU, memory, tasks);
- each PHP-FPM pool of that account runs its own php-fpm master as
  `ispconfig-php-web{DOMAIN_ID}.service` inside the slice, with the optional
  per-website limits.

Accounts without limits are not changed: their pools keep running in the
distribution `phpX.Y-fpm` service. Design and spike results:
[spec 053](../specs/053-php-fpm-resource-limits/spec.md).

## Requirements

Debian/Ubuntu with systemd 243+, the cgroup v2 unified hierarchy
(`stat -fc %T /sys/fs/cgroup` prints `cgroup2fs`) with the cpu, memory and pids
controllers, distribution `php*-fpm` packages, and PHP CLI 8.3+ with pdo_mysql and
posix. Only websites using PHP-FPM are contained; other PHP modes are reported as
`not_fpm`. Enable **Force PHP-FPM** in the product PHP policy to guarantee it.

Run the API migration `2026_09_28_000004_create_php_resource_limit_tables.php` first.
The worker uses the server's existing ISPConfig master SQL account. It needs
SELECT on `api_client_resource_limits`, SELECT/INSERT/UPDATE on
`api_php_limits_workers` and SELECT/INSERT/UPDATE/DELETE on `api_php_limits_usage`
on the master; it reads no native ISPConfig table and never writes one.

## Manual installation

Stage a reviewed release in a root-owned directory with no group/other-writable
ancestors, keeping `php-limits/` and `app/Support/PhpLimits.php` together:

```sh
bash /root/ispconfig-rest-release/php-limits/install.sh            # restarts PHP-FPM once
bash /root/ispconfig-rest-release/php-limits/install.sh --no-restart
ispconfig-php-limits status
```

With `--no-restart`, accounts on a PHP version are isolated only after that version's
service was restarted by the administrator (reported as `service_restart_required`).

## How it works

Only distribution units whose `ExecStart` is exactly
`/usr/sbin/php-fpmX.Y --nodaemonize --fpm-config /etc/php/X.Y/fpm/php-fpm.conf` and
whose configuration includes `pool.d/*.conf` are managed. For each, a drop-in
(`/etc/systemd/system/phpX.Y-fpm.service.d/50-ispconfig-rest-php-limits.conf`) starts
the master with a generated copy of the distribution configuration,
`/etc/ispconfig-rest-php-limits/phpX.Y-fpm.conf`, whose pool glob is replaced by one
explicit include per pool that is not isolated. Distribution files and ISPConfig
templates are never edited; ISPConfig keeps writing `pool.d/web{ID}.conf` and reloading
`phpX.Y-fpm` as usual. The drop-in runs `ispconfig-php-limits hook` before and after each
start and reload, so every ISPConfig change moves pools in or out of their own
service before the shared master reloads. Hook failures never fail the distribution
service, and a pool is never loaded by two masters.

The cron worker (`/etc/cron.d/ispconfig-rest-php-limits`, every minute) copies the
account limits from the master database into
`/var/lib/ispconfig-rest-php-limits/policies.json`, applies changed limits with
`systemctl daemon-reload` (running PHP is not restarted), reloads a PHP-FPM service
when pool membership changes, samples cgroup usage and reports it to the API.

Limits are hard: `MemoryMax` with swap disabled ends the largest PHP worker of the
group when it is exceeded (the pool keeps serving, `OOMPolicy=continue`),
`CPUQuota` throttles, and `TasksMax` refuses further processes. `MemoryHigh` is not
used: without swap it stalls a whole account instead of ending one request.
Size `memory_mb` for `pm.max_children` × typical request memory.

Each dedicated master costs about 3 MB of private memory plus the OPcache pages
that website uses, charged to its own account. Pool messages go to the journal
(`journalctl -t ispconfig-php-web34`).

A pool whose own service fails although its configuration is valid falls back to
the shared master (`fallback`) and is retried after 30 minutes, when its pool file
changes or when the limits change. A pool with an invalid configuration stays out of
the shared master (`failed`), because it would stop every website on that version.

Administrators can cap all hosting on a server with a drop-in on the parent slice,
for example `/etc/systemd/system/ispconfig.slice.d/limits.conf` with
`[Slice]` / `MemoryMax=80%`.

Cron jobs, SSH/SFTP sessions and WordPress Tools commands run outside PHP-FPM and are
not contained yet. Block I/O is not limited.

## Removal

```sh
ispconfig-php-limits uninstall
rm -rf /usr/local/lib/ispconfig-rest-php-limits /usr/local/sbin/ispconfig-php-limits /var/lib/ispconfig-rest-php-limits
```

Uninstall stops the dedicated pool services, removes the drop-ins, slices and generated
files, and restarts each managed PHP-FPM service with its distribution configuration.

## Verification

```sh
php vendor/bin/phpunit --filter 'PhpLimitsTest|ClientResourceLimitsTest|ResourceUsageApiTest'
```

Live behaviour (systemd, cgroups, ISPConfig reloads) is verified on a development
ISPConfig server; see the spec for the recorded results.
