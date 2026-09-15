# Quickstart: Hosting Capabilities for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MeCapabilitiesApiTest|UsageSummaryApiTest|ClientLimitCronTest|ClientLimitDatabaseUserTest|CronScheduleIntervalTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'` (only commits already on
`origin/main`).

Rules for this server: TEMPORARY clients only — never clients 1, 2, 19 or any `customer_no` starting `WHMCS-`; never
delete keys, clients or data another session created (check names first); never change system settings. isp-test
prefixes are `c[CLIENTID]` (database, database user) and `[CLIENTNAME]` (FTP, shell, WebDAV);
`ssh_authentication` is empty and `min_password_length`/`min_password_strength` are 8/3.

1. Admin key: `POST /clients` `qa035…` with `limit_database_user = 1`, `limit_cron = 5`,
   `limit_cron_type = url`, `limit_cron_frequency = 60`, `limit_shell_user = 1`, `ssh_chroot = no,jailkit`,
   `limit_database_quota = 2048`, `template_master = 0` (limits set through `PUT /clients` only take effect with
   `template_master: 0` — WHMCS module finding, 2026-09-15). Create a client-scoped key for it.
2. Client key `GET /me/capabilities`:
   - `sites.prefixes.database` = `c<id>_`-style value of the installation pattern, `sites.prefixes.ftp_user` the
     normalized group name;
   - `sites.databases` = `{quota_limit_mb: 2048, remote_access: true}`;
   - `sites.shell` = `{available: true, chroot_options: ["no", "jailkit"]}`;
   - `sites.cron` = `{types: ["url"], min_interval_minutes: 60}`.
3. Admin key creates a website for the client (needed as the cron/database parent).
4. Client key `POST /sites/database-users` (first) → 201; `GET /usage/summary` → `counts.database_users`
   `{used: 1, limit: 1}`; second `POST` → 403 `limit-reached` with `limit.name = limit_database_user`, and
   `SELECT COUNT(*) FROM sys_datalog WHERE dbtable = 'web_database_user'` unchanged.
5. Client key `POST /sites/cron-jobs` with `run_min = */5`, command `https://example.com/cron` → 403
   `limit-reached`, `limit {name: limit_cron_frequency, max: 60, used: 5}`, nothing journaled.
6. Same call with `run_min = 0`, `run_hour = *` → 201 (hourly URL task).
7. Client key `POST /sites/cron-jobs` with a shell command (`/usr/bin/php -v`) → 403 `feature-not-allowed`,
   `feature: limit_cron_type`, nothing journaled.
8. Client key `PUT` the job from step 6 to `run_min = */10` → 403 `limit-reached`; re-read the job and confirm the
   stored schedule is unchanged.
9. Admin key repeats steps 5 and 7 for the same client → 201 both times (admin keys are not limited); delete the
   jobs afterwards.
10. Prefix proof: client key `POST /sites/databases` with name `shop` → the created `database_name` equals
    `sites.prefixes.database` + `shop`.

## 4. Results on isp-test (2026-09-16, deployed `1af0e06`)

Temporary client 35 (`qa035temp`, group 36) with `limit_database_user = 1`, `limit_cron = 5`,
`limit_cron_type = url`, `limit_cron_frequency = 60`, `limit_shell_user = 1`, `ssh_chroot = no,jailkit`,
`limit_database_quota = 2048`, `template_master = 0`; website 23 as the parent.

| Check | Result |
|---|---|
| `GET /me/capabilities` `sites.prefixes` | `{database: c35, database_user: c35, ftp_user: qa035temp, shell_user: qa035temp, webdav_user: qa035temp}` |
| `sites.databases` | `{quota_limit_mb: 2048, remote_access: true}` |
| `sites.shell` | `{available: true, chroot_options: [no, jailkit]}` |
| `sites.cron` | `{types: [url], min_interval_minutes: 60}` |
| First database user / second | 201 / 403 `limit-reached` `{limit_database_user, client, max 1, used 1}` |
| `GET /usage/summary` | `counts.database_users = {used: 1, limit: 1}` |
| Task every 5 minutes | 403 `limit-reached` `{limit_cron_frequency, client, max 60, used 5}` |
| Task hourly | 201 |
| Shell command under `limit_cron_type = url` | 403 `feature-not-allowed`, `feature: limit_cron_type` |
| Update the hourly task to `*/10` | 403 `limit-reached` (`used: 10`); stored `run_min` still `0` |
| Journal during the refusals | exactly 1 new row — the accepted hourly job |
| Admin key: `*/5` shell task for the same client | 201 (type `chrooted`), unrestricted |
| Prefix proof | `POST /sites/databases` with `shop` stored `database_name = c35shop`, prefix `c35`; the database user stored `c35one` — both equal to what `sites.prefixes` reported (the API returns the unprefixed display name, the full name carries the prefix) |

Cleanup: database, database user, both tasks, the website and the client deleted through the API;
`server.updated` reached the last journal id (934); QA keys 78–81 removed by SQL. No `qa035` client, group,
user, website, database, database user, cron row, client directory or vhost file remains, and nothing is pending.
Keys 1, 2, 20, 27 and 50 and clients 1, 2 and 19 were never touched.

## 3. Cleanup

1. Delete everything created for the check through the API (databases, database users, cron jobs, website), then the
   client.
2. Wait until the server processed the journal: `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa035%'` and the ids you created) after checking the names.
4. Verify no leftovers: no `qa035` client, `sys_group`, `sys_user`, website directory, vhost link, database, database
   user or cron row; `api_keys` back to the pre-run set.
