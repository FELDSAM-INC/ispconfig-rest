---
description: "Task list for feature 012 — Client Resource Limits (quota counting enforcement)"
---

# Tasks: Client Resource Limits (quota counting enforcement)

**Input**: Design documents from `/specs/012-client-resource-limits/`
**Prerequisites**: plan.md (required), spec.md (required — the classification table is the implementation source of truth)

**Tests**: REQUIRED (constitution v2). Every gated resource ships a limit matrix (unlimited / disabled / at-cap / under-cap / admin-bypass, plus type-isolation and reseller-cap where applicable). Pattern: `tests/Feature/ClientLimit*Test.php`, run with `vendor/bin/phpunit`.

**Organization**: Grouped by user story (US1 = P1 high-value counts, US2 = P2 remaining counts + reseller/bespoke, US3 = P3 quota sums). Foundational phase builds the `checkCreate` engine + resource map + chokepoint + test fixtures BEFORE any per-resource wiring.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 / US2 / US3 (or FOUND)
- Include exact file paths.

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| Limit service (extend) | `app/Services/ClientLimitService.php` |
| Create chokepoint | `app/Models/BaseModel.php` (`save()` insert branch) |
| Optional value object / exception | `app/Support/LimitSpec.php`, `app/Support/Exceptions/OverLimitException.php` |
| Exception→403 mapping | `bootstrap/app.php` (only if a new exception type is used) |
| Test fixtures | `tests/Support/TenantSchema.php`, `tests/Support/TenantFixtures.php` |
| Tests (REQUIRED) | `tests/Feature/ClientLimit*Test.php`, `tests/Feature/ClientQuotaSumTest.php` |

---

## Phase 1: Setup (research + contract confirmation)

- [X] T001 [FOUND] Confirm from spec.md's classification table the exact `(limit_column, table, pk, type_filter, predicate)` for every "count" row; confirm 403 is already declared on all gated create ops (`api/modules/**` — no contract change needed). No YAML edits.
- [X] T002 [FOUND] Resolve the three NEEDS CLARIFICATION items with the owner before wiring the affected resources: NC-1 (`/clients/domains` → `domain` table / `limit_domainmodule` gate), NC-2 (quota-sum on `PUT` as well as `POST`), NC-3 (`default_group` vs `sysGroupId` for the bespoke `sys_groupid` counts).

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: No per-resource wiring can begin until this phase is complete.

- [X] T003 [FOUND] Extend `app/Services/ClientLimitService.php` with the **resource map** `limitSpecFor(BaseModel $model): ?LimitSpec` (keyed by `$model->getTable()`, with the `type`-switch for `web_domain`, `mail_forwarding`, and the postgres branch of `web_database`; returns `null` for unmapped tables). Encode every "count" row from the classification table. (Optionally add `app/Support/LimitSpec.php` value object: `limitColumn, table, pk, typeWhere, predicate`.)
- [X] T004 [FOUND] Add `checkCreate(BaseModel $model): void` to `ClientLimitService`: admin/unmapped short-circuit; client cap via `AuthScope::applyReadPredicate($q,'u')` (or bespoke `sys_groupid` per `predicate`) + `typeWhere`, deny when `count >= limit` (reuse existing `limitValue()`); `-1` skip, `null` (no client row) allow. Throw a 403-mapped `OverLimitException` (or `AuthorizationException`) whose message names the limit. Parity: tform.inc.php:197-205.
- [X] T005 [FOUND] Add the **reseller cap** to `checkCreate` (parity tform.inc.php:211-250): when the acting client's `parent_client_id != 0`, read reseller `sys_user.userid`/`groups` + reseller `{limitColumn}`; count `WHERE (sys_groupid IN (reseller.groups) OR sys_userid = reseller.userid) [AND typeWhere]`; deny (`count >= reseller.limit`) with a "Reseller:"-prefixed detail. `limit_client` is client-cap-only (no reseller cap).
- [X] T006 [FOUND] Wire the chokepoint in `app/Models/BaseModel.php` `save()`: on insert (`! $wasUpdate && $this->hasSysFields`), after `applySysFieldDefaults()`, call `App::make(ClientLimitService::class)->checkCreate($this)` BEFORE `parent::save()` — so a denial writes no `sys_datalog` row. Verify admin scope and unmapped tables are no-ops (the 011 564-test suite must stay green).
- [X] T007 [FOUND] If a new `OverLimitException` type is introduced, map it to 403 `application/problem+json` in `bootstrap/app.php` (via `App\Support\Problem`), detail = the exception message. (Skip if reusing the already-403-mapped `AuthorizationException`.)
- [X] T008 [FOUND] Extend `tests/Support/TenantSchema.php` with the remaining `client.limit_*` columns the fixtures need (limit_maildomain/mailbox/web_domain/database/ftp_user/shell_user/dns_zone/mailalias/mailforward/mailcatchall/mailaliasdomain/mailfilter/fetchmail/web_subdomain/web_aliasdomain/webdav_user/cron/database_user/dns_slave_zone/domainmodule/database_postgresql + P3 quota columns), defaulting per the DDL (ispconfig3.sql:174-247).
- [X] T009 [FOUND] Extend `tests/Support/TenantFixtures.php`: add a helper to seed N limit-consuming rows for a tenant on a given table (stamped `ownedBy`), and a reseller-cap seeding helper (rows across R's group set). (`setClientLimit()` already exists.)

**Checkpoint**: `checkCreate` engine, map, chokepoint, and fixtures ready — per-resource wiring can begin.

---

## Phase 3: User Story 1 - High-value row counts (Priority: P1) 🎯 MVP

**Goal**: `limit_maildomain, limit_mailbox, limit_web_domain, limit_database, limit_ftp_user, limit_shell_user, limit_dns_zone` enforced on create with admin bypass and correct `type='vhost'` filtering for web domains.

**Independent Test**: Per resource — unlimited(-1)→201, disabled(0)→403, at-cap(n)→403 (no datalog), under-cap→201, admin→201.

### Tests for User Story 1 (REQUIRED) ⚠️

- [X] T010 [P] [US1] `tests/Feature/ClientLimitMailTest.php` — matrices for `limit_maildomain` (POST /mail/domains) and `limit_mailbox` (POST /mail/users); assert 403 detail names the limit and no `sys_datalog` row on denial.
- [X] T011 [P] [US1] `tests/Feature/ClientLimitSitesTest.php` — matrices for `limit_web_domain` (POST /sites/web-domains, assert `type='vhost'` filter — a child domain does not consume it), `limit_database` (POST /sites/databases), `limit_ftp_user`, `limit_shell_user`.
- [X] T012 [P] [US1] `tests/Feature/ClientLimitDnsTest.php` — matrix for `limit_dns_zone` (POST /dns/soa).

### Implementation for User Story 1

- [X] T013 [US1] Add the US1 rows to the `ClientLimitService` map (T003): mail_domain, mail_user, web_domain(`type='vhost'`), web_database, ftp_user, shell_user, dns_soa — all `predicate='u'`. Verify each model's `$table`/`$primaryKey` (MailDomain=mail_domain/domain_id, MailUser=mail_user/mailuser_id, WebDomain=web_domain/domain_id, WebDatabase=web_database/database_id, FtpUser=ftp_user/ftp_user_id, ShellUser=shell_user/shell_user_id, DnsSoa=dns_soa/id).
- [X] T014 [US1] Run T010–T012 green; confirm the 011 suite still passes (admin regression).

**Checkpoint**: P1 counts enforced end-to-end.

---

## Phase 4: User Story 2 - Remaining counts + reseller & bespoke predicates (Priority: P2)

**Goal**: All other row-count limits, the reseller double-cap, and the bespoke `sys_groupid` counts (`limit_client`, `limit_database_postgresql`).

**Independent Test**: Same matrix per resource; plus type-isolation for `/mail/forwards` and `/sites/web-child-domains`; plus reseller-cap and bespoke-count scenarios.

### Tests for User Story 2 (REQUIRED) ⚠️

- [X] T015 [P] [US2] Extend `ClientLimitMailTest.php`: `/mail/forwards` per-type (`limit_mailalias`/`limit_mailforward`/`limit_mailcatchall` — alias at cap does not block forward), `/mail/alias-domains` (`limit_mailaliasdomain`), `/mail/users/{id}/filters` (`limit_mailfilter`), `/mail/fetchmail` (`limit_fetchmail`), and the count layer of the access-gated `/mail/transports` (`limit_mailrouting`), `/mail/access-rules` (`limit_mail_wblist`), `/mail/spamfilter/wblist` (`limit_spamfilter_wblist`) once booked (limit>0).
- [X] T016 [P] [US2] Extend `ClientLimitSitesTest.php`: `/sites/web-child-domains` per-type (`limit_web_subdomain` vs `limit_web_aliasdomain`), `/sites/database-users` (`limit_database_user`), `/sites/webdav-users` (`limit_webdav_user`), `/sites/cron-jobs` (`limit_cron`), and the postgres bespoke count (`limit_database_postgresql`, `type='postgresql'` only).
- [X] T017 [P] [US2] Extend `ClientLimitDnsTest.php`: `/dns/slaves` (`limit_dns_slave_zone`); add a guard that `POST /dns/records` is **never** limited (no `limit_dns_record` wiring — spec SC-006).
- [X] T018 [P] [US2] `tests/Feature/ClientLimitResellerTest.php` — reseller double-cap across ≥3 resources (mail domain, dns zone, web domain): `A.limit=-1`, `R.limit=n`, `n` rows across R's group set → 403 "Reseller:"; and `limit_client` on reseller `POST /clients` via the bespoke `sys_groupid` count.

### Implementation for User Story 2

- [X] T019 [US2] Add the US2 rows to the `ClientLimitService` map: mail_forwarding (`type`-switch: alias/forward/catchall → limit_mailalias/mailforward/mailcatchall; aliasdomain → limit_mailaliasdomain), mail_user_filter, mail_get, web_domain child (`type`-switch: subdomain/vhostsubdomain → limit_web_subdomain; alias/vhostalias → limit_web_aliasdomain), webdav_user, cron, web_database_user, dns_slave, mail_transport, mail_access, spamfilter_wblist, domain (limit_domainmodule — pending NC-1). All `predicate='u'` except the bespoke ones.
- [X] T020 [US2] Add the bespoke-predicate entries: `client` → `limit_client`, `predicate='grp'` (`sys_groupid = scope.sysGroupId`), client-cap-only; `web_database` postgres branch → `limit_database_postgresql`, `predicate='grp'`, `typeWhere="type='postgresql'"`, applied **in addition** to the `limit_database` count when the payload `type=='postgresql'`.
- [X] T021 [US2] Confirm `limit_spamfilter_policy`/`limit_spamfilter_user` are NOT added (admin-only writes — unreachable) and `limit_dns_record` is NOT added (no legacy call site). Add code comments citing the reason.
- [X] T022 [US2] Run T015–T018 green; confirm 011 suite still passes.

**Checkpoint**: All row-count limits + reseller cap + bespoke counts enforced.

---

## Phase 5: User Story 3 - Quota-sum limits (Priority: P3, independently deferrable)

**Goal**: `limit_mailquota`, `limit_web_quota` (+ `limit_traffic_quota`), `limit_database_quota` SUM caps on create and update.

**Independent Test**: `sum(existing) + new > limit` → 403; unlimited quota with a finite cap → 403; `-1` → allowed; reseller-SUM variant.

### Tests for User Story 3 (REQUIRED) ⚠️

- [X] T023 [P] [US3] `tests/Feature/ClientQuotaSumTest.php` — `limit_mailquota` (POST/PUT /mail/users, bytes→MB), `limit_web_quota` (POST/PUT /sites/web-domains, `type='vhost'`), `limit_database_quota` (POST/PUT /sites/databases, bespoke `sys_groupid`): sum-exceeds → 403; unlimited-with-cap → 403; reseller-SUM variant; `-1` → allowed.

### Implementation for User Story 3

- [X] T024 [US3] Add `checkQuotaSum(BaseModel $model): void` to `ClientLimitService` (+ a `quotaSpecFor` map): sum `quotaColumn` over existing rows (excl. current row on update) via the resource's predicate (`u` for web/mail, `grp` for database) + type filter; deny when `sum + new > limit` or when `new` is unlimited while `limit >= 0`; unit conversions (mail bytes→MB); reseller-SUM variant. Parity: mail_user_edit.php:216-219, web_vhost_domain_edit.php:1120-1142, database_edit.php:280-281.
- [X] T025 [US3] Add a per-model quota opt-in (e.g. `protected ?string $quotaColumn` on MailUser/WebDomain/WebDatabase) and invoke `checkQuotaSum($this)` from `BaseModel::save()` on **both** insert and update for models that declare it (pending NC-2 for the update path). No-op otherwise.
- [X] T026 [US3] Run T023 green; confirm 011 + US1/US2 suites still pass.

**Checkpoint**: Quota-sum caps enforced (or this phase deferred to feature 013 per the plan recommendation, with P1/P2 shipped).

---

## Phase 6: Polish & Cross-Cutting

- [X] T027 [P] Re-verify legacy parity for the documented count queries and the three bespoke-predicate cases against `source_code/interface/web` (spot-check the exact SQL).
- [ ] T028 Update `README.md` / any authorization notes if the public behavior list enumerates limits (no endpoint change). — DEFERRED: no endpoint/contract change (FR-026); the README does not enumerate per-limit behavior, so there is nothing to update. Left unchecked pending owner confirmation.
- [X] T029 Run `vendor/bin/phpunit` (full suite must pass — the admin dev key is the regression bar; assert no `sys_datalog` rows on denied creates).

---

## Dependencies & Execution Order

- **Setup (Phase 1)**: resolve NC items and confirm the map source (spec table) first.
- **Foundational (Phase 2)**: BLOCKS all stories — the `checkCreate` engine, map, chokepoint, and fixtures must exist. T003→T004→T005→T006 are sequential (same files); T007 depends on T004's exception choice; T008/T009 (tests support) can run in parallel with T003–T007.
- **US1 (Phase 3)**: depends on Foundational. Map rows (T013) before running tests (T014); test files (T010–T012) are [P] with each other.
- **US2 (Phase 4)**: depends on Foundational; independent of US1 at the map level (additive rows), but share `ClientLimitService` so map edits (T019/T020) are sequential.
- **US3 (Phase 5)**: depends on Foundational; independent of US1/US2 (separate method + opt-in). Deferrable as a block without touching US1/US2.
- **Polish (Phase 6)**: after the desired stories.

### Within each story

- Tests written and failing before/with implementation.
- `ClientLimitService` map edits are sequential (single shared file) — not [P] with each other.
- `BaseModel::save()` edits (T006, T025) are sequential (single shared file).

### Parallel Opportunities

- The three test files (T010/T011/T012) are [P]; US2 test extensions (T015/T016/T017/T018) are [P] across different files.
- Fixtures (T008/T009) parallel with the service engine (T003–T007).

---

## Implementation Strategy

### MVP First (US1 only)

1. Phase 1 + Phase 2 (engine + chokepoint + fixtures).
2. Phase 3: the seven high-value counts.
3. **STOP and VALIDATE**: run the P1 matrices + the full 011 suite (admin regression); confirm denied creates leave no `sys_datalog` row.

### Incremental Delivery

1. US2 adds the remaining counts + reseller cap without touching US1.
2. US3 adds quota sums (or is deferred to feature 013 — P1/P2 unaffected).

---

## Notes

- The count runs in `BaseModel::save()` (not middleware) so it sees the validated `type` and target table — the reason it can't be a pure route middleware.
- A denial MUST throw before `parent::save()` → no datalog row (asserted in tests).
- Admin scopes and unmapped tables short-circuit `checkCreate` → zero overhead, 011 suite stays green.
- Do NOT wire `limit_dns_record` (no legacy call site) or the spamfilter policy/user counts (admin-only writes) — spec Edge Cases / T021.
- Commit after each task or logical group.
