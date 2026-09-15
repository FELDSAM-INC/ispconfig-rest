# Research: Account Capabilities for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-15.

## R1 — Target account

**Legacy**: the interface always builds forms for the logged-in account; there is no "view as client".

**Decision**: reuse `UsageService::resolveTargetClient()` (spec 017 R7): admin keys must pass `client_id` (422
"The client id is required for admin keys.", unknown → 404); client keys get their own client and may only name
themselves; reseller keys their own client or a client whose group is in the reseller's groups; anything else
404 so client ids cannot be probed. Query validation copies `UsageSummaryController` (unknown parameters 400,
invalid `client_id` 422 "The client id must be a positive integer.").

**Rationale**: identical rules to `/usage/summary`, which the same consumer already calls.

## R2 — Capability sources

**Legacy**: `web_vhost_domain_edit.php:979-996` (flags forced for non-admins), tform `php` valuelimit
`system:sites:web_php_options;client:web_php_options` + `tform_base::applyValueLimit()` (system ∩ client),
`web_vhost_domain.tform.php:88-95` (SSL tab with `limit_ssl`), `:236` (wildcard option with `limit_wildcard`),
`:791-795` (Options tab for resellers with `reseller_can_use_options`). Lock/cancel: `client.locked`,
`client.canceled` (feature 019).

**Decision**: `WebPermissionService::forClient($clientId)` — the exact structure 020 enforces for a key of that
account (reseller when `limit_client != 0`). Output names: `web.ssl`, `web.ssl_letsencrypt`, `web.wildcard`
(module contract), `web.cgi`, `web.ssi`, `web.perl`, `web.ruby`, `web.python`, `web.error_documents`,
`web.directive_snippets`, `web.suexec_forced`, `web.backup`, `web.advanced_options`, `web.php_modes`,
`web.php_default_mode` (`WebPermissionService::defaultPhpMode()`), top-level `client_id`, `account_type`,
`locked`, `canceled`.

**Alternatives considered**: acting-key view for resellers naming a client (would describe the reseller's writes
on that client's websites, not what the client's own panel may do) — rejected; the consumer uses the client's key.

## R3 — PHP version list

**Legacy**: `web_vhost_domain_edit.php:240-272` (initial form list) and `ajax_get_json.php:66-125`
(`getserverphp`, on mode change): `server_id` = website server (parent's for child types, client
`default_webserver` on create), `active = 'y'`, `client_id = 0 OR <client>`, `php-fpm`: `php_fpm_init_script`,
`php_fpm_ini_dir`, `php_fpm_pool_dir` non-empty; `fast-cgi`: `php_fastcgi_binary`, `php_fastcgi_ini_dir`
non-empty; on nginx servers `fast-cgi` is treated as `php-fpm` (`edit.php:243`, `ajax_get_json.php:72`); ajax
orders by `sortprio`.

**Decision**: `PhpVersionService::usable($serverId, [$clientId], $mode)` per allowed mode in
`VERSION_MODES ∩ php_modes` (optionally narrowed by `mode`), merged per version id with the list of supported
modes, ordered `sortprio`, id. `usable()` adds the nginx mapping, which also aligns feature 020 enforcement on
nginx servers (a website in `fast-cgi` mode on nginx validates against FPM versions, like legacy).

**isp-test**: server 1 apache, versions 1–5 (PHP 7.4–8.3), public, sortprio 100, FPM + FastCGI columns set.

## R4 — Default entry

**Legacy**: initial form prepends option `0` = `php_default_name` unless `php_default_hide = y`
(`edit.php:263-265`); ajax inserts `{"0": php_default_name}` before the first record with `sortprio > 0`, only
when records exist. isp-test server 1: `php_default_hide = y`, `php_default_name = Default`.

**Decision** (owner-delegated): list `{id: 0, name: php_default_name ?: "Default", is_default: true, modes:
considered modes}` first for each server whose default is not hidden, whenever at least one version mode is
considered — matching the initial form and the 020 rule that `server_php_id = 0` is valid exactly when the
default is not hidden.

## R5 — Account web servers

**Legacy**: new websites use the client's `default_webserver`/assigned servers (feature 016); existing websites
keep their `server_id` even when the assignment changes.

**Decision**: `ServerAssignmentService::assignedServerIds()` for a lookup scope of the target client (valid,
non-mirror web servers in `web_servers` order), then distinct `web_domain.server_id` of websites whose
`sys_groupid` belongs to the client (`sys_group.client_id`), restricted to non-mirror web servers, ordered by id.
A `server_id` outside the set → 422 "The selected server is not a web server of this account."

## R6 — Response shapes and consumer fit

**Consumer**: WHMCS module `specs/003-websites-domains/contracts/ispconfig-rest-calls.md` reads web flags
`ssl`, `ssl_letsencrypt`, `wildcard`, `php_modes`, `locked` and `data[].id`, `data[].name` with
`?server_id={id}`; its `PlanCapabilities.phpVersions` expects 0 = Default first.

**Decision**: capabilities is a bare object (like `/me`, `/me/servers`); versions are a list resource
`{data, meta}` (Principle V) with entries `id`, `name`, `server_id`, `modes`, `is_default` only
(`additionalProperties: false`, no paths).

## R7 — Parameters and errors

**Decision**:
- `/me/capabilities`: only `client_id` (other parameters 400).
- `/me/php-versions`: `client_id`, `server_id` (positive integer, else 422), `mode` (`php-fpm`|`fast-cgi`, else 422
  "The mode must be php-fpm or fast-cgi."), `limit` (1–100, default 25), `offset` (≥ 0) via
  `HandlesListQuery::positiveIntParam()` (400 on invalid, as every list); other parameters 400.
- Order of checks: parameters (400/422) → target client (422/404) → `server_id` membership (422).
