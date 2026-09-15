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

- [x] T001 Replace the 400 refusal with the cascade description in `api/modules/dns/soa.yaml` (DELETE) and remove the 400 response
- [x] T002 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Story 1 — One-call zone removal (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T003 [US1] Create `tests/Feature/DnsZoneCascadeDeleteTest.php`: zone with records deleted by the owning client key → 204 with `X-Change-Set-Id`, no `dns_rr`/`dns_soa` rows left; journal order (`dns_soa` `u` with `active` N, one `dns_rr` `d` per record ascending, `dns_soa` `d`) all sharing one change set id; empty zone → 204 with two entries; repeated delete → 404
- [x] T004 [US1] Replace `test_delete_with_records_returns_400_problem` in `tests/Feature/DnsSoaApiTest.php` with the cascade expectation

### Implementation

- [x] T005 [US1] Implement the cascade in `app/Http/Controllers/Api/V1/DnsSoaController.php::destroy()` (deactivate, delete records, delete zone, one transaction) and drop the `BadRequestHttpException`

**Checkpoint**: US1 tests green

---

## Phase 3: User Story 2 — Scoping unchanged (P1)

- [x] T006 [US2] Scoping cases in `tests/Feature/DnsZoneCascadeDeleteTest.php`: another client's zone → 404 and nothing written; reseller key deleting a managed client's zone → 204 with the records gone; admin key → 204

---

## Phase 4: Polish

- [x] T007 [P] Remove the deviation from `README.md` "Known deviations" and add a superseded note to spec 002 SC-006 in `specs/002-dns-management/spec.md`
- [x] T008 Run Pint on changed PHP files and the full suite in Docker
- [x] T009 Deploy to isp-test and run `specs/034-zone-removal-with-records/quickstart.md` §2 with a temporary client, including the bind zone file check; record results here

## Dependencies

Phase 1 → US1 → US2 → Polish. T003/T004 before T005.

## Results (T008–T009, 2026-09-16)

- T008: Pint clean on the changed files; full suite 1158 passed (baseline 1155 + 3 new cascade tests; the
  `DnsSoaApiTest` 400 test was replaced and its empty-zone test now expects the deactivation entry).
- T009: deployed `6867d09` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (6867d09)). Temporary client
  `qa034e8c66a` (client 33, `dns_servers=1`, `limit_dns_zone=2`), QA admin key 73 and client key 74, zones 6, 7, 8
  and 9. Keys were never printed. Results:

| Case | Expected | Got |
|---|---|---|
| client creates a zone + 3 records, then `DELETE /dns/soa/6` | 204 with `X-Change-Set-Id` | 204, header `e2df0973…` |
| journal of that deletion | `dns_soa` `u`, one `dns_rr` `d` per record (ascending), `dns_soa` `d`, one change set | exactly that: `dns_soa id:6 u`, `dns_rr id:45/46/47 d`, `dns_soa id:6 d`, all session `e2df0973…` |
| rows after the deletion | no `dns_rr` of the zone, no `dns_soa` row | 0 and 0 |
| repeat the same `DELETE` | 404 | 404 |
| admin creates a zone for the client, client deletes it | 201 / 204, records gone | as expected (zone 7) |
| zone with an NS record: bind state before deletion | zone file written and valid | `pri.qa034d-…` present, `named-checkzone` OK, 2 `named.conf.local` references |
| same zone after the API deletion | zone file and named.conf entries gone | 0 files, 0 references, 0 rows |

  Note: a first probe zone (8) without any NS record produced `pri.<zone>.err` — ISPConfig renames a generated zone
  file when `named-checkzone` rejects it (a zone needs NS records); unrelated to this feature, and that file was
  removed by the zone deletion too.

- Cleanup: all four zones deleted through the API (the cascade removed their records), client 33 deleted (204);
  datalog processed (`server.updated` 854 = last id 854); API keys 73, 74 deleted by SQL (`name LIKE 'qa%'`); no
  `qa034` client, sys_group, sys_user, dns_soa or dns_rr rows, no pending datalog, no `qa034` zone files in
  `/etc/bind` and no `named.conf.local` references. Remaining keys: 1, 2, 20, 27, 50 (untouched).

