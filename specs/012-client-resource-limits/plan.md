# Implementation Plan: Client Resource Limits (quota counting enforcement)

**Branch**: `012-client-resource-limits` | **Date**: 2026-07-05 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/012-client-resource-limits/spec.md`

**Status**: Draft — spec-only stage; this plan describes FUTURE code. No app code changes yet.

## Summary

Complete feature 011's deferred User Story 3: the **counting** half of ISPConfig client resource limits. Feature 011 already shipped the read/`u` predicate (`AuthScope::applyReadPredicate`), the write chokepoint (`BaseModel::save()`), and the **access gate** (`ClientLimitService::resourceEnabled` — limit `0` disables a resource). This feature adds `ClientLimitService::checkCreate(BaseModel $model)` that mirrors legacy `checkClientLimit()` (`getAuthSQL('u')` count vs. limit) and `checkResellerLimit()` (raw group-OR-userid count vs. the parent client's limit), driven by one central **resource map** classifying every `client.limit_*` column (spec's classification table). The check is invoked at the same chokepoint every create flows through — `BaseModel::save()` on insert — so no controller changes are needed; over-limit throws a 403-mapped exception before any write (no datalog row). P3 adds a distinct `checkQuotaSum()` for the three SUM-of-quota caps, invoked at the same point on create and update. **Zero endpoints, zero contract changes** (the 403 is already declared on every gated create — verified).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker
**Storage**: MySQL — ISPConfig's `dbispconfig` (schema owned by ISPConfig; never migrated; writes via `sys_datalog`). New reads only: `client` (`limit_*`, `parent_client_id`), `sys_user` (reseller `userid`/`groups`) — all single-row, indexed, read-only. No new tables, no `api_keys` columns.
**Testing**: PHPUnit (`vendor/bin/phpunit`), feature tests in `tests/Feature/`. This feature's tests are per-resource limit matrices on the SQLite in-memory `tests/Support/*Schema.php` + `TenantFixtures` pattern built in 011.
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: `checkCreate` adds at most one `SELECT count(*)` per non-admin create (plus one `client` row read, and — only when the client has a reseller — one `sys_user` read + one reseller count). Admin creates: zero overhead (short-circuit). Same cost profile legacy pays per interface create.
**Constraints**: no endpoint/shape changes (FR-026); admin-key behavior byte-identical (FR-025 — the 011 regression bar); denials write no datalog; parity citations per spec.
**Scale/Scope**: 0 new endpoints; ~1 modified service (`ClientLimitService`), ~1 modified core file (`BaseModel::save()`), 1 new exception (or reuse `AuthorizationException`), exception→403 mapping in `bootstrap/app.php` (if a distinct over-limit exception is used), `TenantSchema` gains the remaining `limit_*` columns, `TenantFixtures` gains a couple of helpers, per-resource test files.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: PASS — no new endpoints; the only activated code (403 over-limit) is already declared on every gated create (`api/modules/**` POST audit 2026-07-05: 43/43 POSTs declare 403). No contract diff.
- [x] **Datalog-only writes (II)**: PASS — adds **no** write paths; strengthens Principle II by denying over-limit creates before `parent::save()`. No direct table writes; the count reads are query-builder `SELECT`s against read-only system tables (`client`, `sys_user`) — the same pattern 011's `ClientLimitService::limitValue` and `AuthScope::isReseller` already use.
- [x] **Legacy parity (III)**: PASS — `checkClientLimit`/`checkResellerLimit` reverse-engineered with file:line (spec Parity section), including the bespoke `sys_groupid` predicates and the P3 quota-SUM math. Deviations (403 not HTML; create-submit not form-render; `limit_dns_record` and toggles not wired) are declared.
- [x] **Route discipline (IV)**: PASS — no new routes; the check lives in `BaseModel`, not middleware, so no route-ordering impact. The existing `scope.limit:*` gates (011) are untouched.
- [x] **HTTP contract (V)**: PASS — denial is RFC 9457 403 `application/problem+json` via existing `App\Support\Problem`; list/create shapes unchanged.
- [x] **No schema changes**: PASS — no migrations against ISPConfig tables. `TenantSchema` (test-only) gains `limit_*` columns for fixtures; production `dbispconfig` already has them.

## Project Structure

### Documentation (this feature)

```text
specs/012-client-resource-limits/
├── spec.md              # Feature spec (done)
├── plan.md              # This file
└── tasks.md             # Task list
```

(No research.md/data-model.md: legacy research is consolidated in spec.md's Parity section + classification table with file:line citations; no new entities.)

### Source Code (repository root)

```text
app/
├── Services/
│   └── ClientLimitService.php     # MODIFIED — add:
│                                   #   checkCreate(BaseModel $model): void   (P1/P2 row counts)
│                                   #   checkQuotaSum(BaseModel $model): void (P3, if in scope)
│                                   #   private limitSpecFor(BaseModel): ?LimitSpec  (the resource map)
│                                   #   private clientCount()/resellerCount() helpers
│                                   #   reuses AuthScope::applyReadPredicate($q,'u') + existing limitValue()
├── Support/
│   ├── LimitSpec.php               # FUTURE (optional) — tiny value object:
│   │                               #   limitColumn, table, pk, typeWhere, predicate ('u'|'grp'), quota fields
│   └── Exceptions/OverLimitException.php  # FUTURE (optional) — or reuse AuthorizationException with a
│                                   #   distinct message; carries the limit name for the 403 detail
├── Models/
│   └── BaseModel.php               # MODIFIED — in save(), on insert (!exists) for non-admin sys-fielded
│                                   #   models, call ClientLimitService::checkCreate($this) BEFORE
│                                   #   parent::save() (right after applySysFieldDefaults()); P3: also
│                                   #   checkQuotaSum($this) on insert AND update where the model declares
│                                   #   a quota column
└── (no new controllers, no new routes)

bootstrap/app.php                   # MODIFIED only if OverLimitException is introduced — map it to a
                                    #   403 problem+json (AuthorizationException is already 403-mapped by 011)

tests/
├── Support/
│   ├── TenantSchema.php            # MODIFIED — add the remaining client limit_* columns used by fixtures
│   │                               #   (limit_mailbox, limit_web_domain already partly present; add
│   │                               #   limit_database, limit_ftp_user, limit_shell_user, limit_dns_zone,
│   │                               #   limit_mailalias/forward/catchall/aliasdomain, limit_mailfilter,
│   │                               #   limit_fetchmail, limit_web_subdomain/aliasdomain, limit_webdav_user,
│   │                               #   limit_cron, limit_database_user, limit_dns_slave_zone,
│   │                               #   limit_domainmodule, limit_database_postgresql, + P3 quota columns)
│   └── TenantFixtures.php           # MODIFIED — setClientLimit() exists; add ownRow() convenience to seed
│                                    #   N limit-consuming rows for a tenant, and a reseller-cap seeding helper
└── Feature/
    ├── ClientLimitMailTest.php      # FUTURE — maildomain/mailbox/forwards(per type)/alias-domains/
    │                                #   filters/fetchmail/transports/access-rules/wblist matrices
    ├── ClientLimitSitesTest.php     # FUTURE — web-domains/child-domains(per type)/databases/database-users/
    │                                #   ftp/shell/webdav/cron matrices + postgres bespoke count
    ├── ClientLimitDnsTest.php       # FUTURE — dns/soa, dns/slaves matrices; dns/records NOT limited guard
    ├── ClientLimitResellerTest.php  # FUTURE — reseller double-cap across resources; limit_client on /clients
    └── ClientQuotaSumTest.php       # FUTURE (P3) — mailquota/web_quota/database_quota sum + unlimited-with-cap
```

**Structure Decision**: The counting check is centralized in `ClientLimitService` (which already owns limit logic) and invoked from the one chokepoint every create shares (`BaseModel::save()` on insert), exactly as feature 011 placed its write gate there. The ~50 controllers need **no** edits; per-resource work is the map entries + tests. This mirrors legacy centralizing the check in `tform`.

## Design

### Chokepoint: why `BaseModel::save()` and not middleware

The `scope.limit:{column}` route middleware from 011 runs **before** payload validation and does not know the target row's `type` — but `limit_web_domain` vs `limit_web_subdomain` vs `limit_web_aliasdomain` (and `mail_forwarding`'s four types, and the postgres-only database count) are selected by the row's `type`. Middleware also cannot cleanly resolve the target table for counting. Therefore the count runs at model-save time, when the fully-validated model (table, pk, `type`, quota) exists. `BaseModel::save()` is the single chokepoint all create paths share (controller `store()`, `ClientService`, cascades), so placing the check there guarantees coverage with zero per-controller code — the same reasoning that put 011's `u`/`d` write gate in `save()`/`delete()`. The 011 `scope.limit:0` access gate stays as-is (cheap pre-validation reject for disabled features); `checkCreate` adds the `n > 0` counting for booked features and is a no-op for admin scopes.

Invocation (in `BaseModel::save()`, extending the existing insert branch):

```
if (! $wasUpdate && $this->hasSysFields) {
    $this->applySysFieldDefaults();
    App::make(ClientLimitService::class)->checkCreate($this);   // NEW — throws 403 when over limit
    // P3: App::make(ClientLimitService::class)->checkQuotaSum($this);
}
```

`checkCreate` short-circuits (returns) when `authScope()->isAdmin` or the model's table is not in the map, so admin creates and unmapped tables incur no query — preserving the 011 regression bar and zero overhead.

### `checkCreate` (P1/P2) — extending ClientLimitService

1. `$scope = IspContext::authScope()`; if `$scope->isAdmin` → return.
2. `$spec = $this->limitSpecFor($model)`; if `null` → return (unmapped table = not limited).
   - `limitSpecFor` maps `$model->getTable()` → limit config. For `web_domain` and `mail_forwarding` (and the postgres branch of `web_database`), it inspects `$model->getAttribute('type')` to pick the column + `typeWhere`. Returns `LimitSpec{limitColumn, table, pk, typeWhere, predicate}`.
3. **Client cap** (parity tform.inc.php:197-205): `$limit = limitValue($scope, $spec->limitColumn)` (reuse the existing method); if `$limit === null` → return (no client row = unlimited); if `$limit === -1` → skip; else count and deny when `count >= $limit`:
   - predicate `'u'`: `$q = DB::table($spec->table); $scope->applyReadPredicate($q, 'u');` + `$spec->typeWhere`.
   - predicate `'grp'` (`limit_client`, `limit_database_postgresql`): `$q->where('sys_groupid', $scope->sysGroupId)` + `$spec->typeWhere`.
   - `count($q) >= $limit` → throw over-limit 403 with detail naming `$spec->limitColumn`.
4. **Reseller cap** (parity tform.inc.php:211-250): resolve the acting client's `parent_client_id` (from the `client` row already read); if `!= 0`, read the reseller's `sys_user.userid, groups` (by `client_id = parent`) and the reseller's `$spec->limitColumn`; if `>= 0`, count `WHERE (sys_groupid IN (reseller.groups) OR sys_userid = reseller.userid)` + `$spec->typeWhere`; `count >= reseller.limit` → throw 403 with a "Reseller:" detail. (The reseller cap uses the raw predicate, never `applyReadPredicate`.)

### The resource map — where it lives

A private method/array on `ClientLimitService` (`limitSpecFor()`), keyed by table name, with the type-switch for the two shared tables. It is the single source implementations code against (== the spec's classification table). Kept in the service (not a config file) so it sits next to the count logic and can carry closures for the `type` branching. Unmapped tables return `null` (not limited) — this is how `dns_rr` (no `limit_dns_record`), spamfilter tables (admin-only), and every non-scoped table are excluded by construction.

### Reseller-cap logic notes

- Only runs when the acting client's `parent_client_id != 0` (leaf clients under a reseller). A top-level reseller creating for itself has `parent_client_id == 0` → no reseller cap; its own client cap (`checkCreate` step 3) already spans its clients' rows because `getAuthSQL('u')` uses the reseller's `groups` CSV.
- `limit_client` (reseller `POST /clients`) has **no reseller cap** (a reseller's client count is bounded only by its own `limit_client`; there is no `checkResellerLimit('limit_client')` call site) — the map marks it client-cap-only.

### P3 quota-sum design (`checkQuotaSum`, if in scope)

A separate method because the mechanism differs: it needs the **new row's quota** (a model attribute), sums existing rows' quota (excluding the current row on update), converts units, and applies the "cannot be unlimited when capped" rule.

1. Map (subset of `limitSpecFor`, or a parallel `quotaSpecFor`): `web_domain → (limit_web_quota, hd_quota MB, type='vhost', 'u')` + `(limit_traffic_quota, traffic_quota, —, 'u')`; `mail_user → (limit_mailquota, quota bytes→MB, —, 'u')`; `web_database → (limit_database_quota, database_quota MB, —, 'grp')`.
2. `$limit = limitValue(...)`; `-1`/null → skip.
3. `$sum = SELECT sum(quotaCol) FROM table WHERE {predicate} [AND typeWhere] [AND pk != currentId]` (converted to MB); `$new = $model->getAttribute(quotaCol)` (MB; mail converts bytes).
4. Deny (403) when `$sum + $new > $limit`, OR when `$new` is unlimited (`<= 0` for web/db, `== 0` for mail) while `$limit >= 0`.
5. Reseller-SUM variant when `parent_client_id != 0` (parity mail_user_edit.php:231-244 etc.).
6. Invoked from `BaseModel::save()` on **both** insert and update for models that declare a quota column (a per-model opt-in property, e.g. `protected ?string $quotaColumn`, keeps `checkQuotaSum` a no-op for the majority of tables).

**Recommendation**: include P3 in this feature. The chokepoint and `limitValue`/`AuthScope` plumbing are reused; the marginal cost is one method + 3-4 map entries + one model opt-in property + tests. Defer to feature 013 only if the update-path re-check (NC-2) or per-resource unit handling proves larger than a timebox during implementation — P1/P2 artifacts are unaffected either way.

### 403 vs 422 (decision)

**403.** The payload is well-formed and passes validation; the client is simply not *permitted* to create more of the resource — an authorization/quota policy, not a data-shape error (422). This matches feature 011's access gate (`RequireClientLimit` already returns 403 "This feature is not enabled"), the frozen contract (403 declared on every gated create), and legacy's semantic intent (a permission-style refusal rendered as a form error only because the interface has no other channel). The over-limit `detail` names the limit; the reseller cap prefixes "Reseller:".

### Test strategy

Per-resource matrices on the 011 `TenantFixtures` (admin / clientA / clientB / reseller R with `groups = "groupR,groupA"`). For each gated resource:

| Case | Setup | Expect |
|---|---|---|
| unlimited | `setClientLimit(A, col, -1)` | create → 201 |
| disabled | `setClientLimit(A, col, 0)`, 0 rows | create → 403 |
| at cap | `setClientLimit(A, col, n)`, seed `n` A-owned rows | create → 403, detail names limit, no datalog row |
| under cap | as above, delete one | create → 201 |
| admin bypass | admin key, any limit | create → 201 |
| type isolation | `/mail/forwards` alias at cap, forward under cap | alias → 403, forward → 201 |
| reseller cap | `A.col=-1`, `R.col=n`, `n` rows across R's groups | A create → 403 "Reseller:" |
| bespoke count | reseller `limit_client=n` / postgres `limit_database_postgresql=n` | 403 via `sys_groupid` count |
| dns records guard | `/dns/records` at any client limit | never 403 for limits |

`ClientLimitResellerTest` covers the double-cap and `limit_client`; `ClientQuotaSumTest` (P3) covers the sum math. The full pre-existing suite is the admin-regression bar (SC-008).

## Legacy Research (Phase 0 focus)

Complete — consolidated in spec.md's Parity section + classification table with file:line citations (checkClientLimit/checkResellerLimit exact queries, all 30+ call sites with type filters, the three bespoke-predicate exceptions, the quota-SUM formulas, and the negative findings: `limit_dns_record` has no call site; spamfilter policy/user counts are unreachable under 011's admin-only writes). Open items are the three NEEDS CLARIFICATION in spec.md (domainmodule mapping, quota update semantics, `default_group` vs `sysGroupId` for bespoke counts).

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Count logic invoked inside `BaseModel::save()` | Only chokepoint every create path shares; middleware can't see the validated `type`/table | Per-controller `checkCreate` calls = ~20 edits, drift risk, and cascade/service creates would bypass it |
| Direct query-builder counts on `client`/`sys_user`/resource tables | Counts must run before the insert against read-only system tables; identical to legacy's `count()` queries and 011's existing `limitValue`/`isReseller` reads | Full Eloquent models/relations add nothing for indexed single-row reads and one `count(*)` |
