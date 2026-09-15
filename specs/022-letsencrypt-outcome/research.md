# Research: Let's Encrypt Issuance Outcome (022)

All findings read-only on isp-test (ISPConfig 3.3.1p1, Ubuntu 24.04, apache, acme.sh in `/root/.acme.sh`).

## R1 — Where the outcome is decided

**Finding**: `apache2_plugin.inc.php` 1305–1330 (`nginx_plugin.inc.php` 1374–1399 identical) requests a certificate
while processing a `web_domain` journal entry when `ssl = y`, `ssl_letsencrypt = y`, the server is not a mirror and
HTTPS or Let's Encrypt was just enabled, `domain` or `subdomain` changed, or `update_letsencrypt` is set (a child
alias/subdomain changed). Success clears `ssl_request`, `ssl_cert`, `ssl_key`, `ssl_action`; failure sets
`ssl_letsencrypt = n` and, when HTTPS was off before, `ssl = n`, with plain `UPDATE` statements on the local and the
master database. No `sys_datalog` entry and no `sys_datalog.error` is written for a failed request.

**Decision**: derive the outcome from (a) the newest journal entry that requested a certificate, (b) whether the
website's responsible servers processed it (spec 015 `ChangeStatusResolver`), (c) the current `ssl_letsencrypt`
column. Processed + still `y` = issued, processed + `n` = failed.

**Alternatives rejected**: a server-side hook or new table (needs code on web servers — out of scope, constitution
prefers master-only data); reading `sys_datalog.error` (never set for this path).

## R2 — Which journal entries count

**Decision** (FR-003): entries with `dbtable = web_domain`, `dbidx = domain_id:{id}`, decoded with the `DataLog`
payload accessor (`allowed_classes = false`). A *request entry* has new `ssl = y` and `ssl_letsencrypt = y` and is an
insert or changes `ssl`, `ssl_letsencrypt`, `domain` or `subdomain` (mirrors the plugin condition; `update_letsencrypt`
has no journal entry on the parent and is covered by R5). An *off entry* has new `ssl_letsencrypt = n` or `ssl = n`
with old `y`. The newest of either kind decides; other entries (quota, PHP…) are ignored. At most 50 newest entries
are scanned (ISPConfig purges processed entries, R6).

Y/N values in payloads are raw DB strings (`y`/`n`, case-insensitive compare).

## R3 — Failure reasons

**Finding**: `letsencrypt.inc.php` warnings (all `LOGLEVEL_WARN`):

| Line | Message | Reason code |
|------|---------|-------------|
| 233 | `Unable to install acme.sh.  Cannot proceed, no Let's Encrypt client found.` | `client_unavailable` |
| 390 | `Could not verify domain {d}, so excluding it from let's encrypt request.` | `domain_not_reachable` (+ domain) |
| 474 / 487 | `Let's Encrypt SSL Cert for {d} via acme.sh|certbot could not be issued. Used command: …` | `issuance_failed` |
| 494 | `Let's Encrypt Cert file: could not find the issued certificate` | `certificate_not_found` |

`app.inc.php::log()` 257–345 inserts into `sys_log` (`server_id`, `datalog_id` = entry being processed, `loglevel`,
`tstamp`, `message`) only when `priority >= log_priority`; `server.php` 87–91 takes `log_priority` from server config
`[server] loglevel` (isp-test: `2` = errors only, so warnings are not stored by default).

**Decision**: read `sys_log` rows of the website's server with `loglevel >= 1` that have `datalog_id` = request entry
id, or `tstamp >=` request entry tstamp and a message containing the website's domain (covers R5 and log rows written
without datalog id). Parse only the patterns above; precedence `client_unavailable` > `domain_not_reachable` >
`issuance_failed` > `certificate_not_found`; no match → `unknown`. Output fixed English detail texts and parsed domain
names only (FR-006). Domains are validated as hostnames before being returned.

## R4 — Certificate details

**Finding**: `get_website_certificate_paths()` 314–340 — `<document_root>/ssl/<ssl_domain>-le.crt`, `ssl_domain` =
`domain` with a leading `*.` removed (`get_ssl_domain()` 294–312). acme.sh installs copies with umask 0022 (readable
0644); certbot installs symlinks into `/etc/letsencrypt/live` (normally root-only). The API runs as the web-server user
on the master; on multi-server installations the file is on another host.

**Decision**: for `issued` only, when `document_root` is an absolute path without `..` and the domain is a hostname,
read at most 64 KB of that file if `is_readable()`, parse with `openssl_x509_parse()`; `valid_from`/`expires_at` from
`validFrom_time_t`/`validTo_time_t`, `issuer` = issuer `O` else `CN`, `domains` = SAN `DNS:` entries else subject `CN`.
Anything else → `certificate = null`. Private keys are never opened.

## R5 — Failures after an issued certificate

A later child change (alias added) makes the plugin request again (`update_letsencrypt`) without a journal entry for
the parent; a failure leaves the flag `n` while the newest request entry is older and processed → reported `failed`
(correct outcome); the reason comes from log rows naming the domain after the request entry (R3).

## R6 — Retention

`cron.d/200-logfiles.inc.php` 241–300: `sys_log` rows older than 7 days are deleted; processed `sys_datalog` entries
older than the retention are purged. Without a relevant entry the state falls back to the flags (FR-004).

## R7 — Scoping and routing

`GET /sites/web-domains/{webDomain}/ssl/status` in `routes/api/sites.php` above `…/ssl` (specific first); the
`WebDomain` route binding already applies the spec 011 read predicate and limits types to
`vhost`/`vhostsubdomain`/`vhostalias` (other types 404). No plan gate: read-only (owner-delegated decision).

## R8 — Timestamps

`CarbonImmutable::createFromTimestamp($tstamp, config('app.timezone'))->toIso8601String()` as in `ChangeController`
and `WebBackupService` (spec 017 FR-015, decision 26).
