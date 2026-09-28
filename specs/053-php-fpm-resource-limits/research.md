# PHP-FPM resource limits: research and spike

Reference: https://demarchi.xyz/blog/limit-php-fpm-with-cgroup/#multi-tenant-one-cgroup-per-pool
(cgroup v2 via systemd; one service per tenant, tenants grouped in slices).

## How ISPConfig runs PHP-FPM today

- One master per PHP version (`php8.3-fpm.service`, and one unit per additional
  `server_php` version), loading every pool from `pool.d/*.conf`. All tenants share
  that master's cgroup, so any limit on the distribution unit is cumulative for
  every website on that PHP version.
- The web plugin writes one pool per vhost: `pool.d/web{domain_id}.conf`, for
  primary websites and vhost subdomains/aliases alike. The pool's `group` is the
  website's system group `client{client_id}`, so a pool maps to its account from
  the file alone.
- After writing or removing a pool, `php_fpm_pool_update()`/`php_fpm_pool_delete()`
  call `restartService('php-fpm', "reload|restart:<init script>")` directly (not
  delayed). `restartPHP_FPM()` resolves the init script through
  `system->getinitcommand()`, which issues `systemctl reload|restart <unit>` on
  systemd hosts, including `server_php` rows that still store
  `/etc/init.d/php7.4-fpm`. A PHP version change removes the pool from the old
  version's directory, reloads that version, then reloads the new one.
- `php_fpm_incron_reload` is a legacy setting; the 3.3 plugin never reads it.
- Distribution units (Debian/Ubuntu, isp-test Ubuntu 24.04 / systemd 255):
  `Type=notify`, `ExecStart=/usr/sbin/php-fpmX.Y --nodaemonize --fpm-config
  /etc/php/X.Y/fpm/php-fpm.conf`, `ExecReload=/bin/kill -USR2 $MAINPID`,
  hardening (`ProtectSystem=full`, `PrivateDevices=true`, ...).

A per-pool cgroup therefore needs its own master per pool. Moving worker PIDs of
the shared master into per-pool cgroups was rejected: workers are forked inside
the shared cgroup, the move races with request handling, memory charged before the
move stays with the master, and the systemd unit would need `Delegate=yes` plus a
process-moving daemon. A dedicated master is contained by the kernel from fork.

## Spike on isp-test (2026-09-28, disposable unit, removed afterwards)

Transient `cgspike-web1.service` running `php-fpm8.3` with one ondemand pool in
`cgspike-c1.slice` (slice: MemoryMax 300M, TasksMax 40, CPUQuota 100%; unit:
MemoryMax 160M, MemorySwapMax 0, CPUQuota 50%, TasksMax 16, OOMPolicy=continue).

| Check | Result |
| --- | --- |
| Slice nesting | `cgspike.slice/cgspike-c1.slice/cgspike-web1.service`; slice and unit limits both written to cgroupfs |
| Master + worker placement | both in the unit cgroup; worker runs as the pool user |
| CPU 50% quota, 3 s busy loop | `nr_throttled 31`, `throttled_usec 1.54 s` |
| MemoryHigh 140M + MemoryMax 160M, no swap, 400 MB request | **worker stalled**: `memory.events high 5923, max 0, oom_kill 0`; the request timed out and the next request to the same pool also hung, because every allocation in the cgroup was throttled |
| MemoryMax 160M only, 400 MB request | worker SIGKILLed (`oom_kill` +1, master log "exited on signal 9"); unit stayed active; next request served in 34 ms |
| Second master on the same socket | refused: "Another FPM instance seems to already listen" |
| Master memory | ~2.8 MB private dirty per master (PSS 10–12 MB, mostly shared libraries) |

Consequences:

- Use a hard `MemoryMax` with `MemorySwapMax=0`. Do not set `MemoryHigh`: without
  swap, anonymous PHP memory cannot be reclaimed and throttling turns a runaway
  request into a stalled tenant instead of a single killed worker.
  `max_execution_time` counts CPU time, so it does not end a throttled worker.
- `OOMPolicy=continue` is required. The service default (`stop`) would stop the
  whole pool when the kernel kills one worker.
- A pool must leave the shared master before its own master starts, and the
  shared master must never load a pool that has its own master: a reload of the
  shared master would then fail for every website on that PHP version.
- One extra master costs roughly 3 MB private memory plus the OPcache pages that
  site actually touches, now charged to its own cgroup. Isolation is opt-in per
  account, not server-wide.
- The isp-test root cgroup offers `cpuset cpu io memory hugetlb pids`; systemd
  enables `cpu` in `system.slice` only when a unit requests it.
