# Research: Account-Wide Backup Overview

Decisions for feature 041, grounded in the shipped spec 018 implementation, ISPConfig 3.3.1p1 read on
isp-test, and the account endpoints of specs 021/031/036. Each item records the decision, why, and what was
rejected.

## R1 — The endpoint belongs to the `me` module

**Decision**: `GET /me/backups`, registered in `routes/api/me.php` and contracted in
`api/modules/me/backups.yaml`, next to `me/servers`, `me/capabilities`, `me/hosting-addresses` and
`me/hosting-links`.

**Why**: the resource describes the calling account ("the backup situation of my websites"), not a single
website, and the `me` module already owns the `client_id` convention for admin and reseller keys
(`ReadsAccountQuery` + `UsageService::resolveTargetClient()` through `AccountCapabilitiesService::resolveTarget()`).

**Rejected**: `GET /sites/backups` (the sites module addresses rows by their own id; an account-wide
aggregate has no such id and would need a `client_id` parameter the module has nowhere else);
extending `GET /sites/web-domains` with a backup block (would burden every website listing with backup
queries and leak the backup plan gate into an unrelated endpoint).

## R2 — Visibility is delegated, never redefined

**Decision**: the set of websites comes from the scoped read predicate
(`AuthScope::applyReadPredicate()`) restricted to `type = 'vhost'`, and the backups of those websites come
from the same rule the per-website list uses: `parent_domain_id` = the website and `server_id` in
`WebBackupService::backupServerIds($website)` (the website's server plus the servers of its databases).

**Why**: any second definition of "which backups belong to this website" would eventually disagree with
`GET /sites/web-domains/{id}/backups`, and a disagreement here is a data-leak class of bug. Delegating also
means the mongodb/borg/format handling of `backupRepresentation()` is reused verbatim.

**Rejected**: querying `web_backup` by `sys_groupid` (backup rows carry no ownership columns of their own —
they are owned through their website); joining on the client id (same problem).

## R3 — One query per concern, never one per website

**Decision**: the endpoint runs a fixed set of queries: the paged website page, the database-server ids of
those websites in one grouped query, one `web_backup` query for all websites of the page, and one `server`
query for the `backup_dir` flags. The newest row per (website, type) and the per-website totals are folded
in PHP from that single backup result.

**Why**: FR-008/SC-002 — the whole reason for the feature is a consumer that may not call per row; an
implementation that queries per row would only move the problem from HTTP to SQL and would degrade for
accounts with many websites.

**Rejected**: a correlated subquery per website (N queries in one statement, same growth); a window
function (MySQL 5.7 is still supported by ISPConfig installations, so `ROW_NUMBER()` is not available);
`GROUP BY` with `MAX(tstamp)` only (returns the newest timestamp but not the rest of the row, and the
representation needs the full row).

## R4 — `backupServerIds` is resolved for the whole page at once

**Decision**: `WebBackupService::backupServerIds()` is kept as the authority for a single website, and the
new service adds a page-level variant that resolves the database servers of all websites of the page in one
`web_database` query, then reuses the same composition rule (website server + its database servers).

**Why**: keeps one definition of the rule while satisfying R3; the per-website method stays the reference
implementation and the page-level variant is asserted against it in a test (SC-003/SC-004).

**Rejected**: calling the per-website method in a loop (N queries); duplicating the rule inline.

## R5 — The plan gate is the one from spec 018, applied before any work

**Decision**: the controller refuses client and reseller keys whose client has `limit_backup != 'y'` with
403 problem type `feature-not-allowed` and `feature: limit_backup`, reusing `WebBackupService::backupAllowed()`
and the problem type of spec 023. Admin keys are not limited. `vhost`-only filtering replaces the 404 that
`RequireBackupAccess` produces for a single non-vhost website.

**Why**: the overview must not disclose anything the per-website endpoints would refuse; legacy hides the
Backup tab on exactly these conditions (`web_vhost_domain.tform.php:85-97`, ported in
`app/Http/Middleware/RequireBackupAccess.php`).

**Rejected**: reusing the `scope.backup` middleware itself (it is bound to a route-model-bound website and
would need a website id this route does not have); returning an empty list instead of 403 (would hide the
reason and contradict the per-website behaviour).

## R6 — `backups_available` comes from the server, cached per page

**Decision**: `WebBackupService::backupsAvailable($serverId)` decides the flag; the new service calls it
once per distinct server of the page, not once per website.

**Why**: the flag is a property of the server's `backup_dir`, and a page typically has one or two distinct
servers.

**Rejected**: leaving the flag out (the consumer would offer actions that the backend refuses with 409);
computing it from the backup rows (a server with a directory but no backups yet would be reported wrong).

## R7 — Ordering, paging and the empty case

**Decision**: websites are ordered by `domain` ascending and paged with the shared `limit`/`offset`
parameters (default 25, maximum 100) with `meta.total` counting the account's vhost websites. A website
without visible backups is returned with `latest: []` and `total: 0`.

**Why**: the consumer renders a website list, so the page must be a page of *websites*; omitting
backup-less websites would make the overview disagree with the account's website list and hide exactly the
websites a customer most needs to notice.

**Rejected**: ordering by newest backup (a website without backups would have no sort key and the order
would change under the customer's feet); paging over backups (the page would contain an unpredictable
number of websites).

## R8 — `latest` is one entry per type, not one entry overall

**Decision**: `latest` carries the newest backup of each type that exists for the website (`web`, `mysql`,
`mongodb`), newest first.

**Why**: a customer asking "am I backed up?" needs both halves — files and databases are separate backups in
ISPConfig, and a site whose files are backed up daily while its database backup failed a week ago is exactly
the case the overview must reveal.

**Rejected**: a single newest backup (hides the missing half); all backups per website (that is the
per-website list, and the response would grow without bound).

## R9 — Timestamps and representation

**Decision**: `created_at` is the ISO-8601 string in the API timezone, produced by
`WebBackupService::timestamp()`, and every other field of an entry comes from
`WebBackupService::backupRepresentation()` unchanged.

**Why**: spec 017's timezone decision applies to the whole API, and SC-003 requires field-by-field equality
with the per-website list.

**Rejected**: a reduced representation (the consumer would have to call the per-website endpoint to show a
size or an encrypted flag, defeating the feature).

## R10 — Test strategy

**Decision**: a feature test class built on `WebBackupApiTestCase` (its tenant matrix, `limit_backup`
column and two servers — one with a `backup_dir`, one without), covering: the newest-per-type fold, the
empty website, the `backups_available` flag, tenant isolation, the `limit_backup` refusal, the admin
`client_id` rules, unknown parameters, paging, and two assertions that make the feature's promises
observable — a query-count assertion (constant in the number of websites) and a field-equality assertion
against `GET /sites/web-domains/{id}/backups`.

**Why**: constitution "Testing (REQUIRED)", and SC-002/SC-003 are only meaningful if asserted.

**Rejected**: reusing only the existing backup tests (they never exercise an account-wide read).
