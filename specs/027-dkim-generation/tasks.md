---

description: "Task list for spec 027 — DKIM key generation for mail domains"
---

# Tasks: DKIM Key Generation for Mail Domains

**Input**: Design documents from `/specs/027-dkim-generation/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1104 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/MailDomainDkim.yaml` and `MailDomainDkimGenerate.yaml`, register them in `api/components/schemas/_index.yaml`; describe `dkim_private` visibility in `api/components/schemas/MailDomain.yaml`
- [x] T002 Create `api/modules/mail/domain-dkim.yaml` (GET, POST with `X-Change-Set-Id`), register it in `api/modules/mail/_index.yaml` and `api/openapi.yaml`; note generation and `dkim_private` visibility in `api/modules/mail/domains.yaml`
- [x] T003 Verify the YAML parses and `tests/Unit/ChangeSetHeaderContractTest.php` passes

---

## Phase 2: User Stories 1 and 2 — Generate and read (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T004 [US1] [US2] Create `tests/Feature/MailDomainDkimApiTest.php`: status without key (exact keys); generate with server strength (1024 fixture) → view, stored PKCS#8 key matching the public key, one `mail_domain` datalog entry, `X-Change-Set-Id`, no private key in the body; 2048 default when `dkim_strength` is missing or invalid; hosted zone → TXT record published and `dns_managed`; regeneration with `selector` removes the old record and publishes the new one; inactive domain → no DNS; server without DKIM path → 409 nothing written; invalid selector → 422; status with key while disabled keeps the record

### Implementation

- [x] T005 [US1] [US2] Make `MailDomainService::findSoaZone()` public; create `app/Services/MailDomainDkimService.php`, `app/Http/Requests/GenerateMailDomainDkimRequest.php`, `app/Http/Controllers/Api/V1/MailDomainDkimController.php`; routes in `routes/api/mail.php`

**Checkpoint**: US1/US2 tests green

---

## Phase 3: User Story 3 — Private key stays on the server (P1)

- [x] T006 [US3] Tests in `tests/Feature/MailDomainDkimApiTest.php`: client and reseller show/list/create/update responses without `dkim_private` (own uploaded key stored), admin responses with it
- [x] T007 [US3] Drop `dkim_private` for non-admin scopes in `MailDomainController::presentOne()`/`present()`

---

## Phase 4: User Story 4 and tenant matrix (P2)

- [x] T008 [US4] Tests: `PUT /mail/domains/{id}` `dkim: false` after generation → status `enabled=false`, keys kept; generating again enables; client B on A's domain 404; reseller on A's domain 200; readable-but-not-updatable domain 403 nothing written; admin on B's domain 200; locked account may generate
- [x] T009 [US4] Fix any gaps found by T008 (none: disabling and the tenant matrix passed with T005/T007)

---

## Phase 5: Polish

- [x] T010 [P] Document DKIM generation and private key visibility in `README.md`
- [x] T011 Run Pint on changed PHP files and the full suite in Docker
- [ ] T012 Deploy to isp-test and run `specs/027-dkim-generation/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1/US2 → US3 → US4 → Polish. T001/T002 parallel; T006 and T008 extend the same test file sequentially.
