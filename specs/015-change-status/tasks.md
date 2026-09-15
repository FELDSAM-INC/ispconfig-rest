---

description: "Task list for feature 015 — Change Status for API Writes"
---

# Tasks: Change Status for API Writes

**Input**: Design documents from `/specs/015-change-status/`
**Prerequisites**: plan.md, spec.md, research.md (R1–R12), data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Every endpoint and the response header ship with feature tests (success,
400, 401, 404, cross-tenant isolation), status derivation and the contract header lint ship with unit tests.
Tests are written first and must fail before the implementation task that satisfies them. Run with
`vendor/bin/phpunit` (PHP 8.3+; on older hosts use the docker commands in quickstart.md).

**Organization**: US1 = P1 track one write (header + `GET /changes/{change_set_id}`), US2 = P2 pending/failed
list (`GET /changes`), US3 = P3 record view (`table` + `record_id`). The contract header on all 148 write
operations is its own bounded phase (Phase 6) with a guard test.

**Owner decisions (2026-09-14)** built into these tasks: change set status and `entry_counts` over all entries
with entries paginated; header name `X-Change-Set-Id` documented on every journaling write (non-journaling 014/018 writes in `NON_JOURNALING_WRITES`); non-admin visibility = own
writes plus the readable-record view (reseller keys see only their own username's writes); CORS exposure
deferred.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1, US2, US3 (Setup, Foundational, Phase 6 and Polish carry no story label)
- Include exact file paths.

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| Module contract (NEW) | `api/modules/changes/changes.yaml`, `api/modules/changes/_index.yaml` |
| Schemas (NEW) | `api/components/schemas/Change.yaml`, `api/components/schemas/ChangeSet.yaml` |
| Header component (NEW) | `api/components/headers/ChangeSetId.yaml`, `api/components/headers/_index.yaml` |
| Contract registration | `api/openapi.yaml` (`paths`, `components.schemas`, new `components.headers`, tag `Changes`) |
| Write operation contracts | `api/modules/{client,dns,mail,server,sites,system}/*.yaml` (54 files, 148 operations) |
| Controller (NEW) | `app/Http/Controllers/Api/V1/ChangeController.php` |
| Middleware (NEW) | `app/Http/Middleware/AttachChangeSetId.php` (alias `change.set`) |
| Services (NEW) | `app/Services/ChangeStatusResolver.php`, `app/Services/ChangeRecordResolver.php` |
| Changed | `app/Support/IspContext.php`, `app/Services/DatalogService.php`, `bootstrap/app.php`, `routes/api.php` |
| Routes (NEW) | `routes/api/changes.php` |
| Test support | `tests/Support/MonitorSchema.php` (extend), `tests/Support/ChangeFixtures.php` (NEW), `tests/Support/TenantSchema.php` / `TenantFixtures.php`, `tests/Support/MailSchema.php` (reuse) |
| Tests (NEW) | `tests/Feature/ChangeSetHeaderTest.php`, `tests/Feature/ChangeStatusApiTest.php`, `tests/Feature/ChangeListApiTest.php`, `tests/Feature/ChangeRecordViewApiTest.php`, `tests/Unit/ChangeStatusResolverTest.php`, `tests/Unit/ChangeSetHeaderContractTest.php` |

---

## Phase 1: Setup (contract first)

**Purpose**: Author and register the OpenAPI contract before any PHP (Principle I). Copy from the drafts in
`specs/015-change-status/contracts/` and drop their `# Draft of …` comment lines.

- [ ] T001 [P] Create `api/components/headers/ChangeSetId.yaml` from `contracts/ChangeSetId.header.yaml` and `api/components/headers/_index.yaml` listing it (mirror the `_index.yaml` style of `api/components/schemas/`)
- [ ] T002 [P] Create `api/components/schemas/Change.yaml` and `api/components/schemas/ChangeSet.yaml` from `contracts/Change.schema.yaml` and `contracts/ChangeSet.schema.yaml` (`entries.items` → `./Change.yaml`, `meta` → `./Meta.yaml`; `entry_counts` required with the four statuses)
- [ ] T003 [P] Create `api/modules/changes/changes.yaml` and `api/modules/changes/_index.yaml` from `contracts/changes.yaml`: `GET /changes` (shared `limit`/`offset`, `order` asc|desc default desc, no `sort`, filters `status`, `table`, `record_id`, `change_set_id`, `since`), `GET /changes/{change_set_id}` (path pattern `^[A-Za-z0-9,-]{1,64}$`, shared `limit`/`offset`), 200 plus 400/401/404 problem+json responses
- [ ] T004 Register the contract in `api/openapi.yaml`: paths `/changes` before `/changes/{change_set_id}` (`$ref: './modules/changes/changes.yaml#/~1changes'` and `#/~1changes~1{change_set_id}`), `components.schemas.Change`/`ChangeSet`, a new `components.headers` section with `ChangeSetId: $ref: './components/headers/ChangeSetId.yaml'`, and a `Changes` tag (depends on T001–T003)
- [ ] T005 Verify the contract parses and renders: load `/api/spec` and `/api/documentation` (quickstart.md §2, docker `php:8.3-cli` + `php artisan serve`); the Changes tag shows both operations and the schemas resolve

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Test schema, status derivation and the empty endpoint skeleton shared by all three stories.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T006 Extend `tests/Support/MonitorSchema.php` so `sys_datalog` has `datalog_id`, `server_id`, `dbtable`, `dbidx`, `action`, `tstamp`, `user`, `data`, `status`, `error`, `session_id` and `server` has `server_id`, `active`, `mirror_server_id`, `updated` (add only missing columns; keep existing monitor tests green), and create `tests/Support/ChangeFixtures.php` with helpers `addServer(int $id, bool $active = true, int $mirrorOf = 0, int $updated = 0)`, `setWatermark(int $serverId, int $updated)` and `journalEntry(array $overrides): int` (defaults: `session_id`, `user`, `dbtable = mail_domain`, `dbidx = domain_id:1`, `action = i`, `error = ''`)
- [ ] T007 Write failing `tests/Unit/ChangeStatusResolverTest.php` on the sqlite schema from T006: derivation matrix per data-model.md status rules — active target; inactive target with active mirror; target and mirror both required; inactive target without mirror → `stalled`; deleted target server row → `stalled`; `server_id = 0` with all/some/no active servers; processed entry with error → `failed`; unprocessed entry with error → `pending`; FR-005 set precedence (pending > stalled > failed > applied); and `applyStatusFilter()` selects exactly the rows `statusOf()` classifies as each status
- [ ] T008 Implement `app/Services/ChangeStatusResolver.php`: load all `server` rows once per request, build responsible sets `R(s)` and thresholds `T(s)` for every server id plus 0 (research R2), `statusOf(object $row): string`, `aggregate(array $counts): string` (FR-005), `applyStatusFilter(Builder $query, string $status): Builder` (research R6 predicates) and `statusCountSelects(): array` of conditional-sum expressions for the change set aggregate query (research R12); make T007 pass
- [ ] T009 [P] Create `routes/api/changes.php` registering `GET changes` → `ChangeController@index` before `GET changes/{changeSetId}` → `ChangeController@show` with `->where('changeSetId', '[A-Za-z0-9,-]{1,64}')`, require it from `routes/api.php` inside the `api.key` group next to the non-gated modules (outside both `scope.admin` groups), and create `app/Http/Controllers/Api/V1/ChangeController.php` with empty `index`/`show` actions (filled by US1/US2) and a shared private `toChange(object $row): array` mapper per data-model.md (`record_id` parsed from `dbidx`, `i/u/d` → `create/update/delete`, `error` only when `failed`, `created_at` ISO 8601; never `data`, `user`, `server_id`, `status` column or raw `dbidx`)

**Checkpoint**: status derivation proven by unit tests; routes resolve — story phases can begin.

---

## Phase 3: User Story 1 - Track one write until it is applied (Priority: P1) 🎯 MVP

**Goal**: every journaling write returns `X-Change-Set-Id`; `GET /changes/{change_set_id}` reports the set's
status and per-status counts over all visible entries with a page of entries.

**Independent Test**: with client A's key `POST /mail/domains` → 201 with `X-Change-Set-Id`;
`GET /changes/{id}` → `pending`; set `server.updated` past the entry → `applied`; with an error on the processed
entry → `failed` with the text; client B's key → 404; status calls write no `sys_datalog` rows.

### Tests for User Story 1 (REQUIRED) ⚠️

- [ ] T010 [P] [US1] Write failing `tests/Feature/ChangeSetHeaderTest.php` (TenantSchema/TenantFixtures + MailSchema): header value equals the `session_id` of every row written by the request for create (201), update (200) and a cascading delete (204, all cascade rows share it); absent on a no-change update, on a 422 validation failure and on GET requests; present for both admin and client keys
- [ ] T011 [P] [US1] Write failing `tests/Feature/ChangeStatusApiTest.php` (ChangeFixtures + TenantFixtures): `pending` → `applied` when the watermark passes; `failed` with verbatim multi-line error only once processed; `stalled` for inactive target without mirror; mirror servers; `server_id = 0`; set precedence and `entry_counts` over all entries while `?limit=1&offset=1` returns one entry oldest-first with `meta.total` = set size; unknown query parameter → 400; no key → 401; unknown id, id longer than 64 characters or with invalid characters → 404; client B on A's set → 404; reseller key sees only sets written under its own username, not its clients'; admin key sees any set; response never contains `data`, `user` or `server_id`; `sys_datalog` row count unchanged by status calls; query count for a large set (e.g. 500 entries) stays constant (aggregate + page + server queries, no per-entry queries)

### Implementation for User Story 1

- [ ] T012 [US1] Add a request-scoped journal counter to `app/Support/IspContext.php`: `recordJournalEntry(): void` and `journalEntryCount(): int` (no change to `sessionId()` or `username()`)
- [ ] T013 [P] [US1] In `app/Services/DatalogService.php::log()` call `IspContext::recordJournalEntry()` right after the successful `sys_datalog` `insertGetId`, and not when the no-change path returns early; the inserted row bytes stay identical (existing `DataLogApiTest` and datalog byte assertions must stay green) (depends on T012)
- [ ] T014 [P] [US1] Create `app/Http/Middleware/AttachChangeSetId.php`: after `$next($request)`, set `X-Change-Set-Id` to `IspContext::sessionId()` when `$response->isSuccessful()` and `journalEntryCount() > 0` (depends on T012)
- [ ] T015 [US1] Wire the middleware: alias `change.set` in `bootstrap/app.php` and add `AttachChangeSetId::class` to the priority list after `ApiKeyAuth::class`; change the group in `routes/api.php` to `Route::middleware(['api.key', 'change.set'])` (depends on T014; T009 already edited `routes/api.php`, so sequence the two edits)
- [ ] T016 [P] [US1] Implement `ChangeController@show` in `app/Http/Controllers/Api/V1/ChangeController.php` (research R12, plan note 7): visibility `session_id = {changeSetId}` plus `user = IspContext::username()` for non-admin scopes; one aggregate query (`COUNT(*)`, `ChangeStatusResolver::statusCountSelects()`, `MIN(tstamp)`) → 404 problem+json when total is 0; set `status` via `aggregate()`; one page query `ORDER BY datalog_id ASC` with the shared `limit`/`offset` and unknown-parameter rejection from `HandlesListQuery`; respond `{id, status, entry_counts, created_at, entries[], meta{total, limit, offset}}` using `toChange()` (depends on T008, T009)
- [ ] T017 [US1] Run `vendor/bin/phpunit --filter 'ChangeSetHeader|ChangeStatus'` and the regression suites `DataLogApiTest`, `ModuleGateTest`, `MailDomainApiTest`; all green

**Checkpoint**: a client panel can track any write to `applied`/`failed`/`stalled` with its own key.

---

## Phase 4: User Story 2 - Show the customer's pending and failed changes (Priority: P2)

**Goal**: `GET /changes` lists entries visible to the key with status, filters and correct paging.

**Independent Test**: A writes two entries, B one, admin one; A's key `?status=pending` → exactly A's two with
`meta.total = 2`; admin → all four; `status=failed` returns only processed entries with an error.

### Tests for User Story 2 (REQUIRED) ⚠️

- [ ] T018 [P] [US2] Write failing `tests/Feature/ChangeListApiTest.php`: non-admin keys see only entries with their own username (including legacy-panel rows with 26-character session ids), reseller keys only their own username, admin keys all; newest first by default and `order=asc`; filters `status` (each of the four), `table`, `change_set_id`, `since` (ISO 8601, `tstamp >=`) alone and combined; `meta.total` and paging stay correct with `status` filtering; `sort` parameter, unknown parameter, invalid `status` value and invalid `since` → 400; no key → 401; no `data`, `user` or `server_id` in items; constant query count per page

### Implementation for User Story 2

- [ ] T019 [US2] Implement `ChangeController@index` in `app/Http/Controllers/Api/V1/ChangeController.php` (plan note 6, research R9): reject `sort` and validate `status` enum and `since` (400 problem+json); apply visibility (`user = IspContext::username()` for non-admin scopes, none for admin); apply `table`, `change_set_id`, `since` and `ChangeStatusResolver::applyStatusFilter()`; paginate with `HandlesListQuery::listQuery(DataLog::query(), sortable: ['datalog_id'], defaultSort: 'datalog_id', …)` with `order` defaulting to `desc`; map items with `toChange()` (same file as T016 — do not run in parallel with it)
- [ ] T020 [US2] Run `vendor/bin/phpunit --filter ChangeList` plus the US1 suites; all green

**Checkpoint**: dashboard indicator and failure list available; US1 unaffected.

---

## Phase 5: User Story 3 - Status of changes to one record (Priority: P3)

**Goal**: `GET /changes?table=…&record_id=…` shows entries of a readable record from any writer.

**Independent Test**: admin updates A's `web_domain` 12; A's key with the record filter sees the admin's entry;
B's key → 404; deleted record → 404.

### Tests for User Story 3 (REQUIRED) ⚠️

- [ ] T021 [P] [US3] Write failing `tests/Feature/ChangeRecordViewApiTest.php` (TenantFixtures + SitesSchema or MailSchema): readable record returns entries from other writers (admin entry visible to client A) without writer details; record of another tenant → 404; nonexistent and deleted record → 404; `record_id` without `table` → 400; table outside the data-model map with `record_id` → 400; `sys_ini` / `client_template_assigned` with `record_id` → 404 for non-admin keys, entries for admin keys; admin keys see entries even when the record no longer exists; combining the record view with `status` keeps correct `meta.total`

### Implementation for User Story 3

- [ ] T022 [P] [US3] Create `app/Services/ChangeRecordResolver.php`: the table → primary key map from data-model.md (with a has-sys-fields flag; `sys_ini` and `client_template_assigned` admin-only), `supports(string $table): bool`, `assertReadable(string $table, int $id): void` loading the row through `AuthScope::applyReadPredicate('r')` for non-admin scopes and throwing the project's 404 problem otherwise, and `dbidx(string $table, int $id): string` returning `"<pk>:<id>"`
- [ ] T023 [US3] Add the record-view branch to `ChangeController@index` in `app/Http/Controllers/Api/V1/ChangeController.php`: `record_id` requires `table` and a supported table (400), non-admin scopes call `assertReadable()` (404) and replace the username constraint with `dbtable = table AND dbidx = dbidx(...)` (depends on T019, T022)
- [ ] T024 [US3] Run `vendor/bin/phpunit --filter 'ChangeRecordView|ChangeList|ChangeStatus'`; all green

**Checkpoint**: all three stories independently functional.

---

## Phase 6: Contract header on every write operation (FR-001, FR-012, SC-004)

**Purpose**: document `X-Change-Set-Id` on all 149 inline 2xx responses of the 148 POST/PUT/DELETE operations in
54 module files (counts from `contracts/write-operations-header.md`), guarded by a lint test. Depends only on T001
and T004; can run in parallel with Phases 3–5.

**Owner decision (2026-09-14)**: write operations that never journal (014 `/system/api-keys`, 018 backup remote
actions) are exceptions in `NON_JOURNALING_WRITES` and do not document the header.

**Edit rule** (every task below): text edit, never a YAML dump (module files carry comments and hand ordering).
Insert under each 2xx response of `post`/`put`/`patch`/`delete`, directly after `description:` and before
`content:` (204 responses get only `description` + `headers`):

```yaml
        headers:
          X-Change-Set-Id:
            $ref: '../../components/headers/ChangeSetId.yaml'
```

- [ ] T025 Write failing `tests/Unit/ChangeSetHeaderContractTest.php` (symfony/yaml): parse every `api/modules/*/*.yaml`; for each `post`/`put`/`patch`/`delete` operation, every 2xx response must contain `headers.X-Change-Set-Id.$ref` equal to `../../components/headers/ChangeSetId.yaml`, except operations listed in a `NON_JOURNALING_WRITES` exception constant (owner decision 2026-09-14: write operations that never journal — 014 `/system/api-keys` writes, 018 backup remote actions — are listed there once those features are merged; empty until then, see Cross-feature notes); assert at least 148 write operations were checked and that `api/modules/changes/changes.yaml` GET responses carry no such header
- [ ] T026 [P] Add the header to the 17 write operations in the 6 files of `api/modules/client/*.yaml`
- [ ] T027 [P] Add the header to the 12 write operations in the 4 files of `api/modules/dns/*.yaml`
- [ ] T028 [P] Add the header to the 48 write operations in the 19 files of `api/modules/mail/*.yaml` (including `mail/spamfilter/config` PUT, whose legacy write has no journal — the header description already says it is then absent)
- [ ] T029 [P] Add the header to the 25 write operations in the 6 files of `api/modules/server/*.yaml`
- [ ] T030 [P] Add the header to the 33 write operations in the 10 files of `api/modules/sites/*.yaml` (the one PUT with an extra 201 response gets the header on both 2xx responses)
- [ ] T031 [P] Add the header to the 13 write operations in the 9 files of `api/modules/system/*.yaml`
- [ ] T032 Verify Phase 6: `vendor/bin/phpunit tests/Unit/ChangeSetHeaderContractTest.php` passes; `git diff api/modules | grep '^[-+]' | grep -v '^+++\|^---' | grep -vE "headers:|X-Change-Set-Id:|ChangeSetId.yaml"` prints nothing (only header lines added); `curl -s …/api/spec | grep -c X-Change-Set-Id` ≥ 148; Swagger UI shows the header on a POST, PUT and DELETE

**Checkpoint**: contract matches runtime behaviour; future write operations cannot omit the header silently.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T033 [P] Update `README.md` Conventions → "Async writes": writes return `X-Change-Set-Id` when journaled; any key polls `GET /api/v1/changes/{id}` or lists `GET /api/v1/changes?status=pending`; `/monitor/data-logs` stays the admin payload view
- [ ] T034 Re-verify legacy parity against research R2, R3, R5 and R10 citations (mirrors, `server_id = 0`, active filter, error only once processed, purge → 404, username visibility) and confirm the spec's intentional deviations list is complete
- [ ] T035 Code cleanup: controller thin (visibility, validation, mapping only), status predicates only in `ChangeStatusResolver`, record rules only in `ChangeRecordResolver`, no per-entry queries, no ISPConfig schema changes (`git diff --stat` shows no migrations)
- [ ] T036 Run the full suite in docker (`php:8.3-cli vendor/bin/phpunit`, quickstart.md §1); all existing module write tests stay green with the new group middleware
- [ ] T037 Manual end-to-end per quickstart.md §2–3 on isp-test.feldhost.cz after the owner deploys the branch there: header capture, poll to `applied`, paged set, pending list, no-change update without header, other client's key → 404, cross-check with the `sys_datalog`/`server` SQL

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none. T001–T003 in parallel, T004 after them, T005 after T004.
- **Foundational (Phase 2)**: after Setup; blocks US1–US3. T006 → T007 → T008; T009 in parallel with T006–T008.
- **US1 (Phase 3)**: after Phase 2. T012 → (T013 ∥ T014) → T015; T016 after T008/T009 and in parallel with T012–T015.
- **US2 (Phase 4)**: after Phase 2; T019 edits the same controller as T016 (sequence them if both are in progress).
- **US3 (Phase 5)**: after US2's T019 (the record view is a branch of `index`); T022 can start after Phase 2.
- **Phase 6 (contract header)**: after T001 and T004 only; independent of Phases 2–5 (different files).
- **Polish (Phase 7)**: after the desired stories and Phase 6.

### Within Each User Story

- Tests first (T010/T011, T018, T021) and failing before implementation.
- Contract (Phase 1) lands before any controller code (Principle I); Phase 6 lands before release.
- Route file ordering: `changes` before `changes/{changeSetId}` (Principle IV), set once in T009.

### Parallel Opportunities

- T001 ∥ T002 ∥ T003; T009 ∥ T006–T008.
- T010 ∥ T011; T013 ∥ T014 ∥ T016; T018 ∥ T021 ∥ T022.
- T026–T031 all in parallel (one module directory each), alongside any story phase.

### Parallel Example: User Story 1

```bash
# after Phase 2
Task: "Write failing tests/Feature/ChangeSetHeaderTest.php"
Task: "Write failing tests/Feature/ChangeStatusApiTest.php"
# then
Task: "IspContext journal counter in app/Support/IspContext.php"          # T012
Task: "ChangeController@show in app/Http/Controllers/Api/V1/ChangeController.php"  # T016, parallel to T012
# after T012
Task: "DatalogService counter call in app/Services/DatalogService.php"    # T013
Task: "AttachChangeSetId middleware in app/Http/Middleware/AttachChangeSetId.php"  # T014
```

---

## Implementation Strategy

### MVP First (US1 + Phase 6)

1. Phase 1 (contract) and Phase 2 (resolver, routes, skeleton).
2. Phase 3 (US1): header + change set status — the WHMCS panel can show "being applied / done / failed".
3. Phase 6: document the header on all write operations and land the guard test (required before release by
   Principle I and SC-004).
4. STOP and VALIDATE: US1 tests, contract lint, quickstart §2–3 create/poll flow.

### Incremental Delivery

1. US2 adds the dashboard list (same controller, new action).
2. US3 adds the record view (new service + a branch in `index`).
3. Each increment keeps the full existing suite green; no ISPConfig schema changes at any point.

---

## Cross-feature notes

- **014 API key management**: writes only the API-owned `api_keys` table → no journal entry → never emits
  `X-Change-Set-Id` at runtime. Its new `POST/PUT/DELETE /system/api-keys` operations are added to
  `NON_JOURNALING_WRITES` in `tests/Unit/ChangeSetHeaderContractTest.php` (T025) rather than document a header
  that can never appear (owner decision 2026-09-14).
- **016 client server assignment**: adds no write operations, only validation on existing ones (and fetchmail
  destination scoping) → no contract header edits; its 422 rejections happen before any journal write, so no
  header on those responses.
- **017 usage statistics**: read-only → no header work.
- **018 backups**: the backup settings `PUT` writes `web_domain` through the datalog → it MUST reference the
  header like every other write. Remote-action endpoints (`POST …/backups`, `POST …/backups/{backup_id}/restore`,
  `POST …/backups/{backup_id}/download`, `DELETE …/backups/{backup_id}`) insert `sys_remoteaction` rows without a
  journal entry → no header at runtime; add them to `NON_JOURNALING_WRITES` (owner decision 2026-09-14). 018's `scope.backup` route middleware
  runs inside the `['api.key', 'change.set']` group and needs no ordering change for the header.
- **Merge order guidance**:
  - Recommended: 014 → 016 → 015 → 017 → 018 (provisioning blockers first; 015's 54-file contract edit lands once
    most other contract changes are in).
  - If 015 merges before 014 or 018, those branches must rebase and add header references or allowlist entries,
    otherwise `ChangeSetHeaderContractTest` fails — this is the intended enforcement.
  - If 014/016/018 merge before 015, re-count write operations when executing Phase 6 (T026–T032) and extend
    `NON_JOURNALING_WRITES` for 014's and 018's non-journaling operations before T032.
  - `routes/api.php` gains require lines from 014 (`me.php`), 015 (`changes.php` + group middleware array) and 017
    (`usage.php`); `CLAUDE.md`'s SPECKIT block is edited by every plan branch — both are trivial textual conflicts.

---

## Notes

- [P] tasks = different files, no dependencies; T016, T019 and T023 all edit `ChangeController.php` — never in
  parallel with each other; T009 and T015 both edit `routes/api.php` — sequence them.
- Every status endpoint is read-only: tests assert the `sys_datalog` row count is unchanged (FR-011).
- The `status` column of `sys_datalog` is never read (always `ok` in ISPConfig 3.3); failures come only from `error`.
- No index or column may be added to `sys_datalog` or `server` (research R7).
- Commit after each task or logical group.
