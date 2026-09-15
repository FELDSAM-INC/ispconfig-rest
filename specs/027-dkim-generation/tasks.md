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
- [x] T012 Deploy to isp-test and run `specs/027-dkim-generation/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1/US2 → US3 → US4 → Polish. T001/T002 parallel; T006 and T008 extend the same test file sequentially.

## Results (T011–T012, 2026-09-15)

- T011: Pint clean on changed files; full suite 1114 passed (baseline 1104).
- T012: deployed `91ec844` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (91ec844)). Temporary client `qa027814d1b`
  (client 21: `mail_servers=1`, `limit_maildomain=1`), temporary QA admin key 54 and client key 55, hosted DNS zone 3
  `qa027-814d1b.example.test.` on server 1 (dns and mail server; `dkim_path=/var/lib/amavis/dkim`, `dkim_strength=2048`).
  Private keys were compared by SHA-256 and public-key derivation only. All checks matched:

| Case | Expected | Got |
|---|---|---|
| admin `POST /dns/soa` for the client; client `POST /mail/domains` | 201; 201 without `dkim_private` | as expected |
| client `GET …/dkim` | 200, `enabled` false, `public_key` null, `dns_managed` true, `available` true | as expected |
| client `POST …/dkim` | 200, `enabled` true, `selector` default, `key_bits` 2048, record `default._domainkey.<domain>.`, no private key in the body | as expected |
| hosted zone record | equals `dns_record.value` | equal |
| client `GET /mail/domains/10` | no `dkim_private`, `dkim` true | as expected |
| client `POST …/dkim` `selector=s2` | 200, `selector` s2, new public key, zone holds only `s2._domainkey…` | as expected |
| client `POST …/dkim` `selector=Bad!` | 422 | 422 |
| admin `GET /mail/domains/10` | `dkim_private` present | present |
| server processing | `/var/lib/amavis/dkim/<domain>.private` equals the stored key and matches the returned public key; `dkim_selectors.map` → `s2`; one `dkim_domains.map` line | as expected (`server.updated` 586) |
| client `PUT /mail/domains/10` `dkim=false`, `GET …/dkim` | 200; `enabled` false, public key kept; after processing key files and map lines removed | as expected (`server.updated` 591) |

- Cleanup: mail domain 10 deleted (204); `DELETE /dns/soa/3` returned 400 (`Cannot delete zone that contains DNS records`
  — the DKIM TXT record stays after disabling, as in legacy); client 21 deleted (204), which removed the zone and its
  records; datalog processed (`server.updated` 606 = last id); API keys 54, 55 deleted by SQL (`name LIKE 'qa%'`); no
  `qa027` client, sys_group, sys_user, mail_domain, dns_soa, dns_rr or spamfilter_users rows, no pending datalog, no DKIM
  key files, map lines, zone files or client directory. Remaining keys: 1, 2, 20, 27 and 50, 53, 56, 57 (created by the
  concurrent WHMCS module session, untouched).
