---

description: "Task list for spec 022 — Let's Encrypt issuance outcome"
---

# Tasks: Let's Encrypt Issuance Outcome

**Input**: Design documents from `/specs/022-letsencrypt-outcome/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1019 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1, US2, US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/WebDomainSslStatus.yaml` per data-model.md (with nested failure and certificate objects) and register it in `api/components/schemas/_index.yaml`
- [x] T002 Add the path item `/sites/web-domains/{id}/ssl/status` to `api/modules/sites/web-domains.yaml` per contracts/ssl-status.md and register it in `api/openapi.yaml` above `/sites/web-domains/{id}/ssl`
- [x] T003 Verify the YAML parses and every new `$ref` resolves (`tests/Feature/SwaggerSpecServerTest.php` plus a ref check)

---

## Phase 2: Foundational (blocking prerequisites)

- [x] T004 Create `tests/Feature/WebDomainSslStatusApiTest.php` extending `Tests\Support\SitesApiTestCase` with `MonitorSchema::create()` / `MonitorCompletionSchema::create()` in setUp and helpers to seed journal entries (serialized old/new payloads, session_id, tstamp), set the server watermark and add `sys_log` rows
- [x] T005 Create `app/Services/LetsEncryptStatusService.php` skeleton (`status(WebDomain): array`) and register `GET sites/web-domains/{webDomain}/ssl/status` → `WebDomainSslController::status` first in the SSL block of `routes/api/sites.php`

**Checkpoint**: full suite green

---

## Phase 3: User Story 1 — requested / issued / failed / none (P1) 🎯 MVP

- [x] T006 [US1] Tests in `tests/Feature/WebDomainSslStatusApiTest.php`: requested (pending), requested (stalled server), issued, failed, off entry → none, never enabled → none, no entry + flags on → issued with `requested_at = null`, entries of other tables/records and unrelated updates ignored, corrupt payload skipped, 401, other tenant 404, non-vhost type 404, no `X-Change-Set-Id` header, response keys exactly per contract
- [x] T007 [US1] Implement request/off entry detection (≤ 50 newest entries), `ChangeStatusResolver` status, state derivation and timestamps in `app/Services/LetsEncryptStatusService.php`; `status()` action in `app/Http/Controllers/Api/V1/WebDomainSslController.php`

**Checkpoint**: US1 tests pass, full suite green

---

## Phase 4: User Story 2 — failure reasons (P2)

- [ ] T008 [US2] Tests: each legacy warning text (research R3 table) → reason code; precedence when several rows exist; rows matched by `datalog_id` and by domain after the request tstamp; rows of other servers/domains, older rows and debug rows ignored; `domains` parsed and validated; no log text or command in the body; `excluded_domains` for issued
- [ ] T009 [US2] Implement the `sys_log` lookup, pattern parsing, precedence, fixed detail texts and `excluded_domains` in `app/Services/LetsEncryptStatusService.php`

**Checkpoint**: US2 tests pass, full suite green

---

## Phase 5: User Story 3 — certificate details (P3)

- [ ] T010 [US3] Tests: issued + readable self-signed certificate at `<tmp document_root>/ssl/<domain>-le.crt` → `valid_from`, `expires_at`, `issuer`, `domains`; wildcard domain uses the bare domain file; unreadable/missing/non-PEM file → `certificate = null`; requested/failed never return details; relative or `..` document root ignored
- [ ] T011 [US3] Implement the guarded certificate read and X.509 parsing in `app/Services/LetsEncryptStatusService.php`

**Checkpoint**: US3 tests pass, full suite green

---

## Phase 6: Polish

- [ ] T012 [P] README: mention `ssl/status` in the sites module row and the log-level note
- [ ] T013 Pint on changed PHP files; full suite green
- [ ] T014 Deploy to isp-test and run quickstart.md §2–§3 (temporary client only), record results here

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1 → US2 → US3 → Polish (US2/US3 extend the same service and test file, so they run in order).
