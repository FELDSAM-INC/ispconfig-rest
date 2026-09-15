---

description: "Task list for spec 034 — zone removal with records"
---

# Tasks: Zone Removal With Records

**Input**: Design documents from `/specs/034-zone-removal-with-records/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1155 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US2 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 Replace the 400 refusal with the cascade description in `api/modules/dns/soa.yaml` (DELETE) and remove the 400 response
- [ ] T002 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Story 1 — One-call zone removal (P1) 🎯 MVP

### Tests (write first, must fail)

- [ ] T003 [US1] Create `tests/Feature/DnsZoneCascadeDeleteTest.php`: zone with records deleted by the owning client key → 204 with `X-Change-Set-Id`, no `dns_rr`/`dns_soa` rows left; journal order (`dns_soa` `u` with `active` N, one `dns_rr` `d` per record ascending, `dns_soa` `d`) all sharing one change set id; empty zone → 204 with two entries; repeated delete → 404
- [ ] T004 [US1] Replace `test_delete_with_records_returns_400_problem` in `tests/Feature/DnsSoaApiTest.php` with the cascade expectation

### Implementation

- [ ] T005 [US1] Implement the cascade in `app/Http/Controllers/Api/V1/DnsSoaController.php::destroy()` (deactivate, delete records, delete zone, one transaction) and drop the `BadRequestHttpException`

**Checkpoint**: US1 tests green

---

## Phase 3: User Story 2 — Scoping unchanged (P1)

- [ ] T006 [US2] Scoping cases in `tests/Feature/DnsZoneCascadeDeleteTest.php`: another client's zone → 404 and nothing written; reseller key deleting a managed client's zone → 204 with the records gone; admin key → 204

---

## Phase 4: Polish

- [ ] T007 [P] Remove the deviation from `README.md` "Known deviations" and add a superseded note to spec 002 SC-006 in `specs/002-dns-management/spec.md`
- [ ] T008 Run Pint on changed PHP files and the full suite in Docker
- [ ] T009 Deploy to isp-test and run `specs/034-zone-removal-with-records/quickstart.md` §2 with a temporary client, including the bind zone file check; record results here

## Dependencies

Phase 1 → US1 → US2 → Polish. T003/T004 before T005.
