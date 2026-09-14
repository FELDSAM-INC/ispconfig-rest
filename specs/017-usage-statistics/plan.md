# Implementation Plan: Usage Statistics

**Branch**: `017-usage-statistics` | **Date**: 2026-09-14 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/017-usage-statistics/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

Add a read-only `usage` module (9 GET endpoints) that admin, reseller and client keys can call, scoped by the
spec 011 read predicate. It projects ISPConfig's collector data into customer-facing figures: website disk usage
(`monitor_data` `harddisk_quota`), mailbox storage (`email_quota`), database size (`database_size`), web and mail
traffic periods (`web_traffic`, `mail_traffic`), plus a per-client summary of totals and resource counts against
`client.limit_*`. Nothing is written to ISPConfig tables.

Technical approach: thin controllers under `App\Http\Controllers\Api\V1\Usage\`, list endpoints on the existing
`WebDomain` / `MailUser` / `WebDatabase` models through `HandlesListQuery`, and three services:
`MonitorDataService` gains a batched "newest blob per server" reader shared with spec 009; a new
`TrafficPeriodService` computes calendar periods in the API timezone and aggregates a page's traffic with one
grouped query per table; a new `UsageService` assembles rows and the summary, reusing spec 012's count and
quota-sum specs through new public read methods on `ClientLimitService`. FR-015 makes `config/app.php` honour
`APP_TIMEZONE` (today it is hard-coded to UTC) and teaches `install.sh` / `ispconfig-rest update` to align it with
the ISPConfig server's system timezone.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL — ISPConfig's `dbispconfig` (read-only for this feature: `monitor_data`, `web_traffic`,
`mail_traffic`, `web_domain`, `mail_user`, `web_database`, `client`, `sys_user`, `sys_group`, counted resource tables)  
**Testing**: PHPUnit feature tests in `tests/Feature/` (sqlite in-memory, module schema helpers in `tests/Support/`),
unit tests for period maths and blob selection in `tests/Unit/`  
**Target Platform**: Linux server alongside an ISPConfig 3.3 installation (verified against 3.3.1p1 on isp-test)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: SC-005 — a list page for a client with 200 websites / 500 mailboxes / 50 databases within
2 s; FR-012 — each collector blob type and each traffic table read at most once per request  
**Constraints**: read-only (FR-013); collector data freshness is ISPConfig's (5 min disk and databases, 15 min
mail storage, nightly traffic); units normalised to bytes; unknown values are `null`, never errors (FR-011);
calendar periods in the API timezone, aligned with the ISPConfig server (FR-015)  
**Scale/Scope**: 9 endpoints, 1 new contract module (5 path files, 8 schemas), 7 controllers, 2 new services,
2 extended services, 1 config change, installer + manager script changes, ~8 test classes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: new `api/modules/usage/` path files and 8 schemas are authored first from
  `contracts/usage-paths.yaml` and `contracts/usage-schemas.yaml`, registered in `api/openapi.yaml`; controllers
  mirror them verbatim; bodies reference `api/components/schemas/`.
- [x] **Datalog-only writes (II)**: the feature writes nothing (FR-013). No new model maps an ISPConfig table;
  `monitor_data`, `web_traffic` and `mail_traffic` are read with the query builder inside services, the pattern
  established by spec 009 for `monitor_data` (see Complexity Tracking). Existing models are only queried.
- [x] **Legacy parity (III)**: legacy reviewed on ISPConfig 3.3.1p1 — `interface/lib/classes/quota_lib.inc.php`
  (`get_quota_data`, `get_trafficquota_data`, `get_mailquota_data`, `get_databasequota_data`),
  `sites/web_sites_stats.php`, `mail/mail_user_stats.php`, `dashboard/dashlets/limits.php`, collectors in
  `server/lib/classes/cron.d/` and `monitor_tools::delOldRecords()`; parity and the spec's intentional deviations
  are captured in research.md R1–R9.
- [x] **Route discipline (IV)**: routes in a new `routes/api/usage.php` required from `routes/api.php` inside the
  `api.key` group and outside `scope.admin`; literal `usage/summary` and `…/{id}/traffic` routes registered
  before `…/{id}`; `whereNumber` on ids. Controllers thin; logic in `app/Services/`.
- [x] **HTTP contract (V)**: lists `{data, meta:{total,limit,offset}}` via `HandlesListQuery`; 400 on unknown or
  invalid list parameters; 401/404/422 as RFC 9457 problem+json; 200 for every read.
- [x] **No schema changes**: no migrations; the API-owned `api_keys` table is untouched.
- [x] **Tests required**: feature tests per endpoint (success, two-client scoping, unlimited limits, missing /
  stale / corrupt collector data, 400/401/404/422) plus unit tests for period maths and blob selection.

**Post-design re-check (after Phase 1)**: all gates still pass. The only addition beyond API code is FR-015's
installer/manager change, which touches deployment scripts, not ISPConfig data or the contract.

## Project Structure

### Documentation (this feature)

```text
specs/017-usage-statistics/
├── spec.md              # Feature spec
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R12
├── data-model.md        # Phase 1: projections, sources, unit and null rules
├── quickstart.md        # Phase 1: how to run tests and verify on isp-test
├── contracts/
│   ├── usage-paths.yaml     # Draft of api/modules/usage/*.yaml path items
│   ├── usage-schemas.yaml   # Draft of the 8 component schemas
│   └── installer-timezone.md # FR-015 installer / manager behaviour
├── checklists/requirements.md
└── tasks.md             # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
api/
├── openapi.yaml                                   # + tag Usage, + 9 paths entries, + 8 schema refs
├── modules/usage/                                 # NEW module
│   ├── _index.yaml
│   ├── summary.yaml                               # GET /usage/summary
│   ├── web-domains.yaml                           # GET list, {id}, {id}/traffic
│   ├── mail-users.yaml                            # GET list, {id}, {id}/traffic
│   └── databases.yaml                             # GET list, {id}
└── components/schemas/
    ├── UsageSummary.yaml   UsageMetric.yaml   UsageCount.yaml
    ├── WebDomainUsage.yaml MailUserUsage.yaml DatabaseUsage.yaml
    └── TrafficPeriods.yaml TrafficHistory.yaml

app/
├── Http/Controllers/Api/V1/Usage/                 # NEW submodule namespace (pattern: Api/V1/Monitor)
│   ├── UsageSummaryController.php                 # show()
│   ├── WebDomainUsageController.php               # index(), show()
│   ├── WebDomainTrafficController.php             # show()
│   ├── MailUserUsageController.php                # index(), show()
│   ├── MailUserTrafficController.php              # show()
│   └── DatabaseUsageController.php                # index(), show()
├── Http/Requests/Usage/
│   └── TrafficHistoryRequest.php                  # months 1–36, granularity month|day (422)
├── Services/
│   ├── MonitorDataService.php                     # + latestBlobs(types, serverIds): newest decoded blob per (server,type)
│   ├── TrafficPeriodService.php                   # NEW: period boundaries (APP_TIMEZONE), grouped period sums, history
│   ├── UsageService.php                           # NEW: web/mail/db row projection, client summary, target-client resolution
│   └── ClientLimitService.php                     # + public countUsage()/allocatedQuota() over existing LimitSpecs
└── Support/
    └── AuthScope.php                              # + static forClient(int $clientId): scope of a client identity

config/app.php                                     # 'timezone' => env('APP_TIMEZONE', 'UTC')
config/api.php                                     # + usage.stale_after: harddisk_quota/database_size 1800 s, email_quota 3600 s (owner decision 2026-09-14)
routes/api.php                                     # + require __DIR__.'/api/usage.php' (outside scope.admin)
routes/api/usage.php                               # NEW (ordering: literals before {id})
install.sh                                         # + --timezone / ISPC_REST_TIMEZONE, auto-detect, TIMEZONE_MODE in install.conf
bin/ispconfig-rest                                 # update: sync APP_TIMEZONE when TIMEZONE_MODE is auto/unset; status: show timezone

tests/
├── Support/UsageSchema.php                        # NEW: web_traffic, mail_traffic (+ monitor_data via MonitorCompletionSchema)
├── Feature/UsageSummaryApiTest.php
├── Feature/WebDomainUsageApiTest.php              # incl. traffic history
├── Feature/MailUserUsageApiTest.php               # incl. traffic history
├── Feature/DatabaseUsageApiTest.php
├── Feature/ScopingUsageModuleTest.php             # admin / reseller / clientA / clientB matrix, zero leaks
├── Feature/UsageCollectorDataTest.php             # missing, stale, corrupt blobs; multi-server matching
├── Unit/TrafficPeriodServiceTest.php              # month/year boundaries, January, timezone offset
└── Unit/MonitorLatestBlobsTest.php                # newest-per-server selection, one query
```

**Structure Decision**: Mirror the `Api\V1\Monitor\` submodule namespace for the controllers (one controller per
contract resource, traffic history as its own controller to keep `index/show` semantics). Lists reuse the real
models so `HandlesListQuery` applies the spec 011 read predicate, pagination and strict parameters for free.
Blob decoding already lives in `MonitorDataService` (spec 009), so the batched reader is added there instead of
duplicating `unserialize` hardening. Period maths is isolated in `TrafficPeriodService` so it can be unit-tested
against fixed clocks and timezones. Count and quota-sum rules stay single-sourced in `ClientLimitService`
(spec 012) and are only exposed read-only. Routes go into a new module file required alongside
`client`/`dns`/`mail`/`sites` (all keys), never inside the `scope.admin` group that wraps `monitor`.

## Legacy Research (Phase 0 focus)

Consolidated in [research.md](research.md). Key facts verified on isp-test (ISPConfig 3.3.1p1):

- `harddisk_quota` blob: `['user' => [system_user => [used, soft, hard, files]], 'group' => [client_group => [used,
  soft, hard]]]`, values in KiB as strings (`repquota -au/-ag`; `du -s` fallback without real soft/hard);
  collector `*/5`.
- `email_quota` blob: `[email => ['used' => bytes]]` (doveadm kB × 1024 or `du -s` × 1024); collector `*/15`.
- `database_size` blob: list of `['database_name', 'size' (bytes), 'sys_groupid']`; collector `*/5`.
- Collectors `REPLACE INTO monitor_data` then `delOldRecords()` deletes rows older than 240 s for that type and
  server, so the newest row always survives until the next run.
- `web_traffic` (`hostname`, `traffic_date` DATE, `traffic_bytes`) and `mail_traffic` (`mailuser_id`, `month`
  `YYYY-MM`, `traffic`) are written nightly (`0 0 * * *`).
- Legacy periods use PHP `date()` in the server timezone. isp-test runs `Europe/Prague` while the API runs UTC and
  `config/app.php` ignores `APP_TIMEZONE` — FR-015 fixes both.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Query-builder reads of `monitor_data`, `web_traffic` and `mail_traffic` inside services, without Eloquent models | All three are read-only collector tables: `monitor_data` has a composite key and is REPLACE-managed; the traffic tables are aggregated with grouped `SUM(CASE …)` queries, never loaded as rows | Models would suggest write support that must not exist and add nothing to aggregate queries; spec 009 already established builder reads of `monitor_data` in `MonitorDataService` |
