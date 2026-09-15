---
description: "Task list for feature 017 — Usage Statistics"
---

# Tasks: Usage Statistics

**Input**: Design documents from `/specs/017-usage-statistics/`
**Prerequisites**: plan.md, spec.md, research.md (R1–R12), data-model.md, contracts/ (`usage-paths.yaml`,
`usage-schemas.yaml`, `installer-timezone.md`), quickstart.md

**Tests**: REQUIRED (constitution v2). Every endpoint ships feature tests for success, two-client scoping, unlimited
limits, missing / stale / corrupt collector data and 400/401/404/422; period maths and blob selection get unit tests.
This feature is read-only, so there are no `sys_datalog` assertions except "no rows written". Run with
`docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit` (local PHP may be older than 8.3).

**Organization**: US1 = P1 dashboard summary, US2 = P2 per-resource lists and details, US3 = P3 traffic history.
FR-015 (API timezone alignment) is its own phase because it changes config and deployment scripts, not endpoints.

**Owner decisions (2026-09-14) the tasks implement**: name filters use the project's `*` wildcard; a client key
sending `client_id` on usage lists gets 400 (on the summary only its own client id is accepted, other → 404);
summary web traffic counts only active websites; collector data older than 30 min (`harddisk_quota`,
`database_size`) or 60 min (`email_quota`) is stale → `null`; client disk total = sum of vhost site usages;
installer aligns the API timezone with the ISPConfig server.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an unfinished task in the same phase)
- **[Story]**: US1 / US2 / US3 (setup, foundational, timezone and polish tasks carry no story label)
- Exact file paths in every task

## Path Conventions (this feature)

| Artifact | Path |
|----------|------|
| OpenAPI paths | `api/modules/usage/{_index,summary,web-domains,mail-users,databases}.yaml`, registered in `api/openapi.yaml` |
| OpenAPI schemas | `api/components/schemas/{UsageMetric,UsageCount,UsageSummary,TrafficPeriods,TrafficHistory,WebDomainUsage,MailUserUsage,DatabaseUsage}.yaml` |
| Controllers | `app/Http/Controllers/Api/V1/Usage/*Controller.php` (pattern: `Api/V1/Monitor/`) |
| Form request | `app/Http/Requests/Usage/TrafficHistoryRequest.php` |
| Services | `app/Services/UsageService.php`, `app/Services/TrafficPeriodService.php` (new); `app/Services/MonitorDataService.php`, `app/Services/ClientLimitService.php` (extend) |
| Scope helper | `app/Support/AuthScope.php` (`forClient()`) |
| Config | `config/api.php` (`usage.stale_after`), `config/app.php` (`timezone`) |
| Routes | `routes/api/usage.php` (new), one `require` in `routes/api.php` outside `scope.admin` |
| Deployment scripts | `install.sh`, `bin/ispconfig-rest` |
| Test support | `tests/Support/UsageSchema.php` (new); reuse `MonitorCompletionSchema`, `SitesSchema`, `MailCompletionSchema`, `TenantSchema`, `TenantFixtures` |
| Tests | `tests/Feature/*Usage*Test.php`, `tests/Unit/TrafficPeriodServiceTest.php`, `tests/Unit/MonitorLatestBlobsTest.php` |

---

## Phase 1: Setup (contract first)

**Purpose**: The OpenAPI contract exists and renders before any PHP is written (Principle I).

- [x] T001 Create `api/modules/usage/_index.yaml` and register the 9 path entries (`/usage/summary`, `/usage/web-domains`, `/usage/web-domains/{id}`, `/usage/web-domains/{id}/traffic`, `/usage/mail-users`, `/usage/mail-users/{id}`, `/usage/mail-users/{id}/traffic`, `/usage/databases`, `/usage/databases/{id}`) and the 8 schema refs in `api/openapi.yaml`, following the `monitor` module registration pattern (re-baseline 2026-09-15: `api/openapi.yaml` has no top-level `tags` section, as for 014/015 — operations carry `tags: [Usage]` only)
- [x] T002 [P] Author `api/modules/usage/summary.yaml` from `specs/017-usage-statistics/contracts/usage-paths.yaml`: `GET /usage/summary`, optional `client_id` (positive integer), responses 200 `UsageSummary`, 401, 404, 422 (shared problem responses)
- [x] T003 [P] Author `api/modules/usage/web-domains.yaml`: list (shared `limit`/`offset`/`sort`/`order`, `sort` enum `domain`, filters `domain` with `*` wildcard, `parent_domain_id`, `client_id` documented as admin/reseller-only with 400 for client keys), `{id}` detail, `{id}/traffic` (`months` 1–36 default 12, `granularity` `month`|`day`); responses 200/400/401/404/422
- [x] T004 [P] Author `api/modules/usage/mail-users.yaml`: list (sort `email`, filters `email` `*` wildcard, `mail_domain`, `client_id` admin/reseller-only), `{id}` detail, `{id}/traffic` (monthly only; `granularity=day` → 422); responses 200/400/401/404/422
- [x] T005 [P] Author `api/modules/usage/databases.yaml`: list (sort `database_name`, filters `database_name` `*` wildcard, `client_id` admin/reseller-only) and `{id}` detail; responses 200/400/401/404
- [x] T006 [P] Author `api/components/schemas/UsageMetric.yaml`, `UsageCount.yaml`, `UsageSummary.yaml`, `TrafficPeriods.yaml`, `TrafficHistory.yaml` from `contracts/usage-schemas.yaml` and data-model.md (nullable fields exactly as specified; `web_traffic_this_month` described as active websites only)
- [x] T007 [P] Author `api/components/schemas/WebDomainUsage.yaml`, `MailUserUsage.yaml`, `DatabaseUsage.yaml` from `contracts/usage-schemas.yaml` and data-model.md
- [x] T008 Verify the contract: YAML parses, `docker run --rm -v "$PWD":/app -w /app -p 8000:8000 php:8.3-cli php artisan serve --host=0.0.0.0` renders `/api/documentation` with the Usage tag and 9 operations without resolver errors (depends on T001–T007)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared services, config, scope helper, list concern, test schema and route file used by every story.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T009 [P] Create `tests/Support/UsageSchema.php` creating `web_traffic` (`hostname`, `traffic_date` DATE, `traffic_bytes`) and `mail_traffic` (`mailuser_id`, `month` `YYYY-MM`, `traffic`) for sqlite; `monitor_data` comes from `tests/Support/MonitorCompletionSchema.php`
- [x] T010 [P] Write `tests/Unit/MonitorLatestBlobsTest.php` (failing first): newest row per `(server_id, type)` wins, one SQL query for several types and servers (query log), undecodable blob → `data: null`, objects are never instantiated (`allowed_classes => false`), unknown server → absent key
- [x] T011 [P] Write `tests/Unit/TrafficPeriodServiceTest.php` (failing first) with `Carbon::setTestNow()` and `config(['app.timezone' => 'Europe/Prague'])`: this month / last month / this year / last year boundaries incl. January rollover and a UTC-vs-Prague midnight case; grouped web sums by hostname and mail sums by `mailuser_id` in one query per table; missing rows → 0; monthly history zero-filled oldest first for 1 and 36 months; daily history for the current month up to yesterday
- [x] T012 Add `latestBlobs(array $types, array $serverIds): array` to `app/Services/MonitorDataService.php` (research R1): one `SELECT server_id, type, created, data … ORDER BY created DESC` query, first row per `(server_id, type)`, decoded through the existing private `decode()`, returning `[serverId][type] => ['data' => ?array, 'created' => int]`; leave `latestPerType()` untouched (spec 009); make T010 pass
- [x] T013 [P] Add `'usage' => ['stale_after' => ['harddisk_quota' => 1800, 'database_size' => 1800, 'email_quota' => 3600]]` to `config/api.php` with a comment citing FR-011 (owner decision 2026-09-14)
- [x] T014 Create `app/Services/TrafficPeriodService.php` (research R5, R11): period boundaries from `CarbonImmutable::now(config('app.timezone'))`; `webPeriods(array $hostnames): array` and `mailPeriods(array $mailuserIds): array` with one grouped `SUM(CASE …)` query each (sqlite and MySQL compatible, `mail_traffic.month` compared as strings); `webHistory(string $hostname, int $months, string $granularity)` and `mailHistory(int $mailuserId, int $months)`; make T011 pass
- [x] T015 Add public read-only `countUsage(AuthScope $scope, string $limitColumn): int`, `allocatedQuota(AuthScope $scope, string $limitColumn): int` and a `limitSpecFor(string $limitColumn)` map to `app/Services/ClientLimitService.php` (research R8), reusing `webDomainCountSpecs()`, `mailForwardingCountSpecs()`, `databaseCountSpecs()`, `count()`, the quota specs and `applyTypeFilter()` for the 12 count columns of data-model.md and the four quota columns; do not change `checkCreate()` / `checkQuotaSum()`
- [x] T016 [P] Add `public static function forClient(int $clientId): self` to `app/Support/AuthScope.php` (research R7): resolve `sys_group.client_id` → `sys_user.default_group` and the groups CSV exactly like `ApiKeyAuth::resolveScope()`; return `null` when the client has no control-panel identity, and `UsageService` answers 404 problem+json, identical to an unknown client (owner decision 2026-09-14)
- [x] T017 [P] Create `app/Http/Controllers/Api/V1/Usage/Concerns/UsageListQuery.php` trait: shared `HandlesListQuery` filter/sort definitions per resource (`domain` / `email` / `database_name` as `wildcard`, `mail_domain`, `parent_domain_id` integer, `client_id` as `owning_client`), and a guard that answers 400 problem+json when a non-admin, non-reseller key sends `client_id` (owner decision 2026-09-14)
- [x] T018 Create `routes/api/usage.php` (empty module group with a comment on ordering: literal `summary` and `{id}/traffic` routes before `{id}`, `whereNumber` on ids) and add `require __DIR__.'/api/usage.php';` to `routes/api.php` inside the `api.key` group after the `sites` require, outside both `scope.admin` groups
- [x] T019 Run `tests/Unit/MonitorLatestBlobsTest.php` and `tests/Unit/TrafficPeriodServiceTest.php` green, plus regressions touching changed services: `tests/Feature/ServerStatusApiTest.php`, `tests/Feature/ClientLimit*Test.php`, `tests/Feature/ClientQuotaSumTest.php`

**Checkpoint**: Foundation ready — user stories can start.

---

## Phase 3: User Story 1 - Plan usage summary for the dashboard (Priority: P1) 🎯 MVP

**Goal**: `GET /usage/summary` returns one client's disk, mail, database and this-month traffic metrics plus the 12
resource counts against limits.

**Independent Test**: spec US1 independent test — client A (two vhosts 868 KiB / 1024 KiB, mailbox 1 MiB, database
5 MiB, current-month traffic, `limit_database_quota = -1`, `limit_web_domain = 5`) and client B; A's key → A's totals
only, database limit unlimited, websites `2` of `5`; admin + `client_id=A` → A; admin without `client_id` → 422.

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T020 [P] [US1] Write `tests/Feature/UsageSummaryApiTest.php` (failing first) using `TenantSchema`/`TenantFixtures`, `SitesSchema`, `MailCompletionSchema`, `MonitorCompletionSchema`, `UsageSchema`: A's totals (used 1937408 bytes disk, allocated from `hd_quota`, limit from `limit_web_quota` × 1024²); `-1` limit → `limit_bytes: null`, `used_percent: null`; count limit `0` returned as `0`; counts match spec 012 rules incl. vhost-only web domain count; inactive website traffic excluded from `web_traffic_this_month`; missing blob → metric `used_bytes`/`measured_at` null with 200; stale blob (created 1801 s ago) → null; `period.timezone` equals `config('app.timezone')`; admin with / without / unknown `client_id` → 200 / 422 / 404; reseller own, child, foreign client → 200 / 200 / 404; client key with own `client_id` → 200, other → 404; missing key → 401; no `sys_datalog` rows written

### Implementation for User Story 1

- [x] T021 [US1] Create `app/Services/UsageService.php` with `resolveTargetClient(AuthScope $scope, ?int $clientId): int` (research R7) and `summary(int $clientId): array` (research R9): scope via `AuthScope::forClient()`, vhost disk sum and mailbox / database sums from one `latestBlobs()` call over the client's server ids, staleness from `config('api.usage.stale_after')`, `measured_at` = oldest contributing `created`, allocated and limit bytes (MB × 1024²), `web_traffic_this_month` from `TrafficPeriodService::webPeriods()` over active `vhost`/`vhostsubdomain`/`vhostalias` hostnames, counts map keys → limit columns from data-model.md via `ClientLimitService::countUsage()`, `period {this_month_start, timezone}`
- [x] T022 [US1] Create `app/Http/Controllers/Api/V1/Usage/UsageSummaryController.php` with `show(Request $request)`: accept only `client_id` (positive integer; other query parameters → 400, invalid value → 422), call `UsageService`, return the `UsageSummary` shape from `api/components/schemas/UsageSummary.yaml`
- [x] T023 [US1] Register `GET usage/summary` in `routes/api/usage.php` before any `{id}` route
- [x] T024 [US1] Run T020 green and verify `GET /usage/summary` in Swagger UI "Try it out" against `api/modules/usage/summary.yaml` (fields, nulls, status codes)

**Checkpoint**: The dashboard summary works on its own.

---

## Phase 4: User Story 2 - Per-resource usage lists and details (Priority: P2)

**Goal**: Website, mailbox and database usage tables with detail views, scoped and paginated.

**Independent Test**: spec US2 independent test — A's key lists only A's vhost / child sites with `{data, meta}`,
vhost rows carry disk figures, child rows `disk: null` + `parent_domain_id`; `mail_domain` filter narrows mailboxes;
B's database detail → 404.

### Tests for User Story 2 (REQUIRED) ⚠️

- [ ] T025 [P] [US2] Write `tests/Feature/WebDomainUsageApiTest.php` (failing first): only `vhost`/`vhostsubdomain`/`vhostalias` rows; vhost disk from its own server's `harddisk_quota` (`used × 1024`, soft/hard limits, files); percent against soft limit, fallback against `hd_quota × 1024²` when soft is 0 (du fallback); child types → disk fields null with `parent_domain_id`; traffic `this_month`/`last_month`/`this_year`/`last_year` bytes and `traffic_quota_bytes`; filters `domain=*.test.cz` wildcard, `parent_domain_id`, `client_id` (admin 200, reseller 200, client key 400); `sort=domain` only; unknown parameter → 400; B's site detail with A's key → 404; `{data, meta}` totals scoped
- [ ] T026 [P] [US2] Write `tests/Feature/MailUserUsageApiTest.php` (failing first): `used_bytes` from `email_quota` by email; email missing from blob → `used_bytes: null`; quota `0` and `-1` → `quota_bytes: null`, `used_percent: null`; `mail_domain` and `email` wildcard filters; `client_id` 400 for client keys; mail traffic periods from `mail_traffic.month`; B's mailbox → 404
- [ ] T027 [P] [US2] Write `tests/Feature/DatabaseUsageApiTest.php` (failing first): `size_bytes` from `database_size` by `database_name` on the database's server; quota `database_quota × 1024²`, `≤ 0` → null; `database_name` wildcard filter; `client_id` 400 for client keys; B's database → 404
- [ ] T028 [P] [US2] Write `tests/Feature/UsageCollectorDataTest.php` (failing first): missing, stale (1801 s disk / database, 3601 s mail) and corrupt blobs → affected fields null with `measured_at: null` and 200 on all three lists; two servers with the same system user `web1` → each site reads only its own server's blob; FR-012 — each list request issues one `monitor_data` query and at most one query per traffic table (query log assertion)

### Implementation for User Story 2

- [ ] T029 [US2] Add `webDomainRows(Collection $domains): array` to `app/Services/UsageService.php` (research R3, data-model WebDomainUsage): one `latestBlobs(['harddisk_quota'], $serverIds)`, normalise legacy `[0 => x, 1 => y]` shapes, stale → null, one `TrafficPeriodService::webPeriods()` call for the page's hostnames, `hd_quota_bytes` and `traffic_quota_bytes` unlimited rules
- [ ] T030 [US2] Add `mailUserRows(Collection $mailUsers): array` to `app/Services/UsageService.php` (research R4): one `latestBlobs(['email_quota'], …)`, quota rules, one `TrafficPeriodService::mailPeriods()` call
- [ ] T031 [US2] Add `databaseRows(Collection $databases): array` to `app/Services/UsageService.php` (research R4): one `latestBlobs(['database_size'], …)` indexed by `database_name` per server, quota MB × 1024²
- [ ] T032 [P] [US2] Create `app/Http/Controllers/Api/V1/Usage/WebDomainUsageController.php` (`index`, `show`) on `App\Models\WebDomain` through `HandlesListQuery` + `UsageListQuery` (types `vhost`/`vhostsubdomain`/`vhostalias`, read predicate, `whereNumber` ids, 404 outside scope), rows from `UsageService::webDomainRows()`
- [ ] T033 [P] [US2] Create `app/Http/Controllers/Api/V1/Usage/MailUserUsageController.php` (`index`, `show`) on `App\Models\MailUser` with filters `email`, `mail_domain`, `client_id`, rows from `UsageService::mailUserRows()`
- [ ] T034 [P] [US2] Create `app/Http/Controllers/Api/V1/Usage/DatabaseUsageController.php` (`index`, `show`) on `App\Models\WebDatabase` with filters `database_name`, `client_id`, rows from `UsageService::databaseRows()`
- [ ] T035 [US2] Register list and `{id}` detail routes for `usage/web-domains`, `usage/mail-users`, `usage/databases` in `routes/api/usage.php` (keep room for US3 literal `{id}/traffic` routes above the `{id}` routes)
- [ ] T036 [US2] Run T025–T028 green and verify the 6 list/detail operations in Swagger UI against `api/modules/usage/{web-domains,mail-users,databases}.yaml`

**Checkpoint**: US1 and US2 work independently.

---

## Phase 5: User Story 3 - Traffic history (Priority: P3)

**Goal**: Monthly traffic history for websites and mailboxes, daily history of the current month for websites.

**Independent Test**: spec US3 independent test — 14 months of `web_traffic` rows; `?months=12` → 12 points oldest
first with zero-filled months; `?granularity=day` → one point per day of the current month up to yesterday.

### Tests for User Story 3 (REQUIRED) ⚠️

- [ ] T037 [P] [US3] Extend `tests/Feature/WebDomainUsageApiTest.php` with `GET /usage/web-domains/{id}/traffic`: default 12 monthly points oldest first, missing months 0, `period_start`/`period_end`/`timezone`; `months=1` and `36` accepted, `0` and `37` → 422; `granularity=day` daily points up to yesterday; invalid granularity → 422; B's site → 404
- [ ] T038 [P] [US3] Extend `tests/Feature/MailUserUsageApiTest.php` with `GET /usage/mail-users/{id}/traffic`: monthly points from `mail_traffic.month`, `granularity=day` → 422, B's mailbox → 404

### Implementation for User Story 3

- [ ] T039 [P] [US3] Create `app/Http/Requests/Usage/TrafficHistoryRequest.php`: `months` integer 1–36 (default 12), `granularity` in `month`,`day` (default `month`), 422 problem+json via the project's Form Request handling
- [ ] T040 [P] [US3] Create `app/Http/Controllers/Api/V1/Usage/WebDomainTrafficController.php` (`show`): resolve the website through the read predicate (404), return `TrafficHistory` from `TrafficPeriodService::webHistory()` (depends on T039)
- [ ] T041 [P] [US3] Create `app/Http/Controllers/Api/V1/Usage/MailUserTrafficController.php` (`show`): read predicate (404), `granularity=day` → 422, `TrafficPeriodService::mailHistory()` (depends on T039)
- [ ] T042 [US3] Register `GET usage/web-domains/{id}/traffic` and `GET usage/mail-users/{id}/traffic` in `routes/api/usage.php` above the matching `{id}` routes and confirm no shadowing with `php artisan route:list --path=usage`
- [ ] T043 [US3] Run T037–T038 green and verify both traffic operations in Swagger UI

**Checkpoint**: All user stories work independently.

---

## Phase 6: API timezone alignment (FR-015)

**Purpose**: Calendar periods must use the ISPConfig server's timezone. Today `config/app.php` hard-codes `'UTC'`
(isp-test runs `Europe/Prague`), so traffic periods near month boundaries are wrong. Ship this phase with the first
deployment that exposes traffic figures. Contract: `specs/017-usage-statistics/contracts/installer-timezone.md`.

- [ ] T044 [P] Change `config/app.php` to `'timezone' => env('APP_TIMEZONE', 'UTC')`; confirm `.env.example` keeps `APP_TIMEZONE=UTC`; verify with `docker run --rm -e APP_TIMEZONE=Europe/Prague -v "$PWD":/app -w /app php:8.3-cli php artisan tinker --execute='echo config("app.timezone");'` → `Europe/Prague`
- [ ] T045 [P] Extend `install.sh`: `--timezone TZ` flag and `ISPC_REST_TIMEZONE` env (documented in `usage()`), detection order `timedatectl show -p Timezone --value` → `/etc/timezone` → `readlink -f /etc/localtime` minus `/usr/share/zoneinfo/`, validation with `"$PHP_BIN" -r` + `timezone_identifiers_list()` (invalid explicit value → `die`, nothing valid detected → `UTC` + warning), write the result instead of the hard-coded `APP_TIMEZONE=UTC` in the `.env` heredoc, and add `TIMEZONE` and `TIMEZONE_MODE="auto"|"explicit"` to the `install.conf` heredoc
- [ ] T046 [P] Extend `bin/ispconfig-rest`: a timezone detection + validation helper (same order as T045); in `cmd_update`, before `artisan config:cache`, when `TIMEZONE_MODE` is `auto` or unset re-detect and replace only the `APP_TIMEZONE=` line of `$INSTALL_DIR/.env` (append when missing), update `TIMEZONE`/`TIMEZONE_MODE="auto"` in `/etc/ispconfig-rest/install.conf`, print `Timezone set to <TZ>` when it changed; leave `explicit` untouched; in `cmd_status` print `timezone: <APP_TIMEZONE> (system: <detected>, mode: auto|explicit)` and warn when they differ
- [ ] T047 Static checks: `bash -n install.sh bin/ispconfig-rest` and `shellcheck install.sh bin/ispconfig-rest` (when available); no new warnings in the changed functions
- [ ] T054 [P] Write `tests/Feature/AppTimezoneImpactTest.php` (research R13, re-baseline 2026-09-15) with the application timezone set to `Europe/Prague`: `/changes` `created_at` and monitor `last_updated` keep the same UTC instants; `/changes?since=` with `Z` is unchanged; a newly minted API key's `created_at` serialises as the current UTC instant
- [ ] T048 Manual verification on isp-test (quickstart.md §4). Until the owner approves deploying, only read state: `ssh root@isp-test.feldhost.cz 'timedatectl show -p Timezone --value; grep APP_TIMEZONE /opt/ispconfig-rest/.env; grep TIMEZONE /etc/ispconfig-rest/install.conf'`. After an owner-approved deploy: `ispconfig-rest update` → `.env` has `APP_TIMEZONE=Europe/Prague`, `install.conf` has `TIMEZONE_MODE="auto"`, `ispconfig-rest artisan tinker --execute='echo config("app.timezone");'` prints `Europe/Prague`, `ispconfig-rest status` shows the timezone line without warning. The explicit-mode check (`install.sh --timezone UTC`, then `update` keeps UTC) runs only on a scratch VM, never on isp-test

**Checkpoint**: Period boundaries match ISPConfig's dates on real installations.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T049 [P] Write `tests/Feature/ScopingUsageModuleTest.php`: admin / reseller / client A / client B key matrix over all 9 usage operations — client keys never see another client's rows, totals, counts or history (SC-002), and usage routes are never admin-gated (client key never gets 403)
- [ ] T050 [P] Extend `tests/Feature/UsageCollectorDataTest.php` with the SC-005 dataset (200 websites, 500 mailboxes, 50 databases for one client): each list page answers within 2 s on sqlite and keeps the per-request query count from T028
- [ ] T051 [P] Update `README.md`: add the `usage` module to the Modules table and document `--timezone` / automatic timezone alignment in the installer section
- [ ] T052 Legacy parity spot-check (SC-003) on isp-test after an owner-approved deploy, read-only API calls from quickstart.md §3: `web1` 868 KiB → `used_bytes` 888832, soft 1048576 KiB → 1073741824, mailbox and database figures equal the ISPConfig statistics pages; no writes on the server
- [ ] T053 Run the full suite `docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit` — all green, no regressions in `ServerStatusApiTest`, `ClientLimit*Test`, `ClientQuotaSumTest`, `Scoping*ModuleTest`, `ModuleGateTest`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none. T001–T007 before T008.
- **Foundational (Phase 2)**: depends on Setup; BLOCKS all stories. T010 → T012 and T011 → T014 (tests first);
  T015 and T018 are single-file edits; T019 closes the phase.
- **US1 (Phase 3)**: depends on Foundational (T012, T013, T014, T015, T016, T018). T020 → T021 → T022 → T023 → T024.
- **US2 (Phase 4)**: depends on Foundational (T012, T013, T014, T017, T018); independent of US1 except that T029–T031
  edit `UsageService.php` created in T021 — if US2 starts first, T029 creates the file.
- **US3 (Phase 5)**: depends on Foundational (T014, T018); independent of US1/US2 apart from sharing the two test files
  and `routes/api/usage.php`.
- **Timezone (Phase 6)**: independent of the stories; must ship in the same release as any traffic figure (US1 summary
  traffic, US2 periods, US3 history).
- **Polish (Phase 7)**: after the stories that are being released; T049 needs all 9 endpoints.

### Within Each Story

- Tests are written and fail before implementation.
- Contract YAML (Phase 1) before controllers; services before controllers; controllers before routes; Swagger check last.
- `routes/api/usage.php` edits (T018, T023, T035, T042) are sequential — single file, ordering matters.
- `app/Services/UsageService.php` edits (T021, T029–T031) are sequential.

### Parallel Opportunities

- Phase 1: T002–T007 together.
- Phase 2: T009, T010, T011, T013, T016, T017 together; then T012, T014, T015.
- US2: tests T025–T028 together; controllers T032–T034 together after T029–T031.
- US3: T037, T038, T039 together; then T040 and T041 together.
- Phase 6: T044, T045, T046 together (different files), then T047.

## Parallel Example: User Story 2

```bash
# Tests (different files):
Task: "Write tests/Feature/WebDomainUsageApiTest.php"
Task: "Write tests/Feature/MailUserUsageApiTest.php"
Task: "Write tests/Feature/DatabaseUsageApiTest.php"
Task: "Write tests/Feature/UsageCollectorDataTest.php"

# Controllers after UsageService row projections (T029–T031):
Task: "Create app/Http/Controllers/Api/V1/Usage/WebDomainUsageController.php"
Task: "Create app/Http/Controllers/Api/V1/Usage/MailUserUsageController.php"
Task: "Create app/Http/Controllers/Api/V1/Usage/DatabaseUsageController.php"
```

---

## Implementation Strategy

### MVP First (US1 + timezone)

1. Phase 1 (contract) and Phase 2 (services, config, scope helper, list concern, test schema, route file).
2. Phase 3: `GET /usage/summary`.
3. Phase 6: timezone alignment — the summary includes this month's traffic, so it must not ship with UTC periods.
4. **STOP and VALIDATE**: T020 green, full suite green, Swagger check; deploy to isp-test only with owner approval,
   then run T048 and the summary part of T052.

### Incremental Delivery

1. US2 adds the three drill-down lists without touching the summary.
2. US3 adds traffic history (literal routes above `{id}` routes).
3. Polish: scoping matrix, performance dataset, README, parity spot-check.

---

## Cross-feature Notes

- **No datalog writes**: every usage endpoint is a GET (FR-013), so spec 015's `X-Change-Set-Id` header does not apply
  and the usage paths need no header references; 015's contract check targets write operations only.
- **Spec 012 counting reused, not duplicated**: `ClientLimitService::countUsage()` / `allocatedQuota()` read the same
  `LimitSpec` map that enforces limits (T015); enforcement methods stay unchanged and `ClientLimit*Test` must stay green.
- **Spec 009 shared service**: `MonitorDataService::latestBlobs()` is additive; `latestPerType()` and
  `ServerStatusApiTest` stay unchanged.
- **Spec 015 re-baseline (2026-09-15)**: `ChangeSetHeaderContractTest` checks only POST/PUT/PATCH/DELETE operations, so no change is needed for the usage paths; the `change.set` middleware never adds the header to GETs.
- **Timezone switch (FR-015)**: impact on existing endpoints is recorded in research R13 and covered by T054.
- **Spec 016**: unaffected — usage reads resources on whatever server they were created.
- **Merges**: `routes/api.php` gains one require line (like 014 `me` and 015 `changes`), and the `CLAUDE.md` SPECKIT block
  differs per plan branch; both are trivial conflicts at merge time.

## Notes

- [P] tasks = different files, no dependency on an unfinished task in the same phase; never two parallel edits to
  `routes/api/usage.php` or `UsageService.php`.
- The feature must not write to any table (FR-013); a task that writes to the database is out of scope.
- Deployment-script changes are verified manually; any change on isp-test beyond read-only checks needs the owner's
  approval first.
- Commit after each task or logical group.
