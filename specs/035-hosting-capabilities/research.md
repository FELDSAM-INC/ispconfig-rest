# Research: Hosting Capabilities for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — Where the capabilities belong

**Options**: (a) a new `GET /me/hosting` endpoint; (b) a `sites` block inside the existing `GET /me/capabilities`.

**Findings**: `/me/capabilities` already answers "what may this account do" for websites (spec 021) and mailboxes
(spec 025) with the target resolution every `/me` read shares (`AccountCapabilitiesService::resolveTarget()`). The
WHMCS module's contract (`specs/006-databases-ftp-cron/contracts/ispconfig-rest-calls.md`) budgets at most 8 reads
per page and already calls `/me/capabilities` on every panel page; a separate endpoint would cost one more read on
pages that are already at the budget.

**Decision** (owner-delegated 2026-09-16): (b), a required `sites` block, named after ISPConfig's own module. Values
that do not exist in the installation are reported as `null`/`[]`, never omitted, so a consumer never has to
distinguish "absent" from "unlimited".

## R2 — Resolving the prefixes for an account that owns nothing

**Legacy**: `tools_sites::replacePrefix()` substitutes `[CLIENTNAME]`, `[CLIENTID]` and `[DOMAINID]`.
`getClientName()`/`getClientID()` take the group from the *session* for a plain client, and from the record for
admins/resellers; with no record and no session context they return the placeholder unchanged.
`convertClientName()` lowercases, drops spaces and replaces anything outside `[a-z0-9_]` with `_`.

**API**: `SitesConfigService::resolvePrefix()` already ports this (admin path), and the write endpoints call
`sitesPrefix($key, ['sys_groupid' => …])`. The described client's group is available without extra lookups through
`AuthScope::forClient($clientId)->sysGroupId`, the same object the capabilities service already builds.

**Decision**: resolve with `['sys_groupid' => <described client's group>]` — identical to what
`POST /sites/databases` would apply for that client. `[DOMAINID]` stays unresolved (no website context), matching
legacy's behaviour in the same situation. isp-test values: `dbname_prefix`/`dbuser_prefix` = `c[CLIENTID]`,
`ftpuser_prefix`/`shelluser_prefix`/`webdavuser_prefix` = `[CLIENTNAME]`.

## R3 — Which prefixes to report

`[sites]` carries five prefix keys (`dbname_prefix`, `dbuser_prefix`, `ftpuser_prefix`, `shelluser_prefix`,
`webdavuser_prefix`). All five are reported: the module manages databases, database users, FTP and SSH accounts, and
WebDAV users exist in the same area even though the module does not manage them yet. `SystemSitesConfig.yaml`
deliberately hides `webdavuser_prefix` from the admin configuration schema, but the *resolved* value of an account is
not installation configuration — it is the name the account's own writes will produce.

## R4 — Database options a client may set

**Legacy**: `database.tform.php:181-199` defines `remote_access` as a plain CHECKBOX and `remote_ips` as TEXT, with
no `valuelimit` — every client may switch remote access on. The only database-side plan limit besides the counts is
`limit_database_quota` (`database_edit.php:185-280`), enforced as a quota sum.

**Decision**: report `remote_access: true` as a constant with that reason documented, and `quota_limit_mb` from
`client.limit_database_quota` (`null` when `< 0`). The API already enforces the quota sum
(`ClientLimitService::checkQuotaSum()`, `grp` predicate), so this is a read-only companion to an existing rule.

## R5 — Shell chroot options

**Legacy**: `shell_user.tform.php:136-141` offers `no` and `Jailkit` with `'valuelimit' => 'client:ssh_chroot'`.
`tform_base::applyValueLimit()` returns the full list for admin sessions, otherwise intersects the offered keys with
the comma list in `client.ssh_chroot` (plus the record's current value). isp-test: the column defaults to
`no,jailkit,ssh-chroot`; client 1 has `no,jailkit`, client 19 (`WHMCS-2`) has `jailkit`.

**Decision**: `chroot_options` is the client's comma list intersected with the options ISPConfig offers, in the
option list's order; `[]` when shell access is not available. An admin key reading a client sees that client's list —
the subject is the client, not the caller (an admin's own unrestricted view is not a capability of the account).
`available` follows `limit_shell_user != 0`, the same value `RequireClientLimit` gates the shell endpoints with.

## R6 — Task kinds

**Legacy**: `cron_edit.php:145-160` derives the kind from the command: a `http(s)://` command is always `url`;
otherwise the owner's `limit_cron_type` decides (`full` → `full`, anything else → `chrooted`; an admin-owned site →
`full`). `onInsertSave`/`onUpdateSave` then refuse a non-`url` kind when the client's limit is `url`.

**API**: `SitesService::deriveCronType()` already ports the derivation, so the kinds an account can reach are
`["url"]` for `limit_cron_type = url`, `["url", "chrooted"]` for `chrooted`, and `["url", "chrooted", "full"]` for
`full`.

**Decision**: report exactly that list. It is what a panel needs to decide whether to offer a command field at all.

## R7 — Shortest interval (`cron_min_freq`)

**Legacy** (`validate_cron.inc.php:99-222`): per schedule field, the expression is expanded into the values it fires
at; the field's shortest gap is the smallest distance between consecutive values *including* the wrap-around
(`($first - $min_entry) + ($max_entry - $last) + 1`); the gap is multiplied by the field's minute weight and stored
only when `$min_freq > 0 && $min_freq <= $max_entry`; the job's `cron_min_freq` is the smallest stored value across
fields.

Field weights and ranges:

| Field | min | max | minutes per unit |
|---|---|---|---|
| `run_min` | 0 | 59 | 1 |
| `run_hour` | 0 | 23 | 60 |
| `run_mday` | 1 | 31 | 1440 |
| `run_month` | 1 | 12 | 1440 × 28 ("not exactly but enough") |
| `run_wday` | 0 | 7 | 1440 |

`cron_edit.php:176-183` then refuses when `limit_cron_frequency > 1` and `cron_min_freq < limit_cron_frequency`.

Worked examples: `*/5 * * * *` → `run_min` gap 5 → 5 minutes. `0 * * * *` → `run_min` yields 60, which exceeds its
own max (59) and is discarded; `run_hour = *` yields 1 × 60 → 60 minutes. `@reboot` in `run_month` is accepted by the
field validator and contributes nothing.

**Decision**: port the accumulation verbatim as `CronJob::minIntervalMinutes()`, including the discard condition, and
compare exactly as legacy does (`< limit`, only when `limit > 1`). The computed value is reported as `used` in the
refusal so a panel can phrase "runs every 5 minutes, the plan allows 60".

## R8 — `limit_database_user`: enforced, uncounted

The WHMCS module's dependency note assumed the limit was unenforced. It is not: `countSpecsFor()` maps
`web_database_user` to `limit_database_user` with a reseller cap, so `POST /sites/database-users` already refuses
past the cap through `BaseModel::save()`. What is missing is `USAGE_COUNT_COLUMNS`, hence no `database_users` count
in `/usage/summary` and no `countSpecForColumn()` case for `countUsage()`.

**Decision**: add the count only, and pin the existing enforcement with tests so the two definitions cannot drift.
The module spec's note is corrected in this spec's Context.

## R9 — Where the cron limits are enforced

**Options**: (a) in the form requests; (b) in `BaseModel::save()` next to the count limits; (c) in the controller
after the type derivation.

**Findings**: the kind is derived from the command *and* the parent domain (`deriveCronType()`), which the request
does not resolve; `BaseModel::save()` runs `checkCreate()` for creates only, while legacy checks updates too. The
controller already resolves the parent domain and derives the type for both verbs.

**Decision**: (c) — one `ClientLimitService::checkCronLimits(CronJob $job)` called from `store()` and `update()`
after the type is set and before `save()`, skipped for admin scopes exactly like the other limit checks. The
refusals reuse `ProblemAuthorizationException` with the existing problem types, so no new type is introduced.

## R10 — Scope of the account counts

`countUsage()` counts with the described client's own read predicate. For `web_database_user` the rows carry
`sys_groupid`, so the `'u'` predicate used by every other simple count applies unchanged; no bespoke predicate is
needed.
