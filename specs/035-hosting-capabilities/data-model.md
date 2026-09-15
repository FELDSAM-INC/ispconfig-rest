# Data Model: Hosting Capabilities for Scoped Keys

No migrations. The capabilities are a read-only view derived per request; the enforcement additions read existing
columns.

## AccountCapabilities.sites (`api/components/schemas/AccountSitesCapabilities.yaml`)

New required block of the existing `AccountCapabilities` schema (spec 021/025 added `web` and `mail`).

| Field | Type | Source |
|---|---|---|
| `prefixes` | object | `[sites]` section of `sys_ini`, resolved for the described client |
| `databases` | object | `client.limit_database_quota`, legacy database form |
| `shell` | object | `client.limit_shell_user`, `client.ssh_chroot` |
| `cron` | object | `client.limit_cron_type`, `client.limit_cron_frequency` |

### prefixes

| Field | Type | Source (`[sites]` key) |
|---|---|---|
| `database` | string | `dbname_prefix` |
| `database_user` | string | `dbuser_prefix` |
| `ftp_user` | string | `ftpuser_prefix` |
| `shell_user` | string | `shelluser_prefix` |
| `webdav_user` | string | `webdavuser_prefix` |

Each value is the configured pattern with `[CLIENTID]` and `[CLIENTNAME]` resolved for the described client
(`SitesConfigService::resolvePrefix()` with `['sys_groupid' => <the client's group>]`, the same call the write
endpoints make). `[DOMAINID]` stays unresolved — there is no website context, exactly as legacy leaves it
(`tools_sites::replacePrefix`). An unset or empty pattern yields `""`.

### databases

| Field | Type | Source |
|---|---|---|
| `quota_limit_mb` | integer\|null | `client.limit_database_quota`; `null` when `< 0` (unlimited) |
| `remote_access` | boolean | constant `true` — legacy `database.tform.php:181` has no `valuelimit`, so every client may switch it on |

### shell

| Field | Type | Source |
|---|---|---|
| `available` | boolean | `client.limit_shell_user != 0` |
| `chroot_options` | string[] | `client.ssh_chroot` (comma list) ∩ ISPConfig's own option list, order of the option list |

ISPConfig's option list is `no`, `jailkit` (`shell_user.tform.php:136-141`); installations that offer `ssh-chroot`
keep it because the client column carries it (`client.ssh_chroot` default `no,jailkit,ssh-chroot`). Legacy
`tform_base::applyValueLimit('client:ssh_chroot')` intersects exactly this way; admin sessions skip the intersection,
but an admin key reading a *client* here gets that client's list (the client is the subject, not the caller).

### cron

| Field | Type | Source |
|---|---|---|
| `types` | string[] | from `client.limit_cron_type`: `url` → `["url"]`; `chrooted` → `["url", "chrooted"]`; `full` → `["url", "chrooted", "full"]` |
| `min_interval_minutes` | integer\|null | `client.limit_cron_frequency` when `> 1`, else `null` (legacy checks only `> 1`) |

`types` mirrors what `SitesService::deriveCronType()` can produce for the account plus the URL case, which is always
available (a `http(s)://` command is always stored as `url`).

## UsageSummary.counts.database_users (`api/components/schemas/UsageSummary.yaml`)

| Field | Type | Source |
|---|---|---|
| `used` | integer | `web_database_user` rows the key may read (`ClientLimitService::countUsage()`, `'u'` predicate) |
| `limit` | integer\|null | `client.limit_database_user`; `null` when `< 0` |

Added to `ClientLimitService::USAGE_COUNT_COLUMNS` as `database_users => limit_database_user` and to
`countSpecForColumn()` with the same `LimitSpec` that `countSpecsFor()` already uses for the `web_database_user`
table, so the number shown and the number enforced come from one definition.

## Cron schedule interval (enforcement input)

`CronJob::minIntervalMinutes(run_min, run_hour, run_mday, run_month, run_wday)` — port of legacy
`validate_cron`'s `cron_min_freq` accumulation:

- per field, expand the expression into the concrete values it fires at (`*`, `a`, `a-b`, `*/s`, `a-b/s`, lists);
- the field's own shortest gap is the smallest distance between consecutive values, including the wrap-around from
  the last value to the first of the next period;
- the gap is converted to minutes with the field's multiplier and kept only when it stays inside the field's own
  range (legacy `if($min_freq > 0 && $min_freq <= $max_entry)`);
- the job's interval is the smallest of the kept per-field values; `null` when no field contributes.

No state is stored; the value is computed per request from the merged (existing + submitted) fields.

## Refusals

| Endpoint | Condition (client and reseller keys only) | Problem |
|---|---|---|
| `POST /sites/cron-jobs`, `PUT /sites/cron-jobs/{id}` | interval < `client.limit_cron_frequency` (when `> 1`) | 403 `limit-reached`, `limit {name: limit_cron_frequency, scope: client, max, used}` |
| `POST /sites/cron-jobs`, `PUT /sites/cron-jobs/{id}` | `limit_cron_type = url` and the derived type is not `url` | 403 `feature-not-allowed`, `feature: limit_cron_type` |
| `POST /sites/database-users` | existing count limit (unchanged) | 403 `limit-reached`, `limit {name: limit_database_user, …}` |

`used` carries the job's own shortest interval in minutes, so a panel can say "runs every 5 minutes, the plan allows
60". Both refusals are thrown before any write, so no `sys_datalog` row is produced.
