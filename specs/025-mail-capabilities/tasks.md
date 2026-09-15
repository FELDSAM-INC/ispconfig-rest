---

description: "Task list for spec 025 — mail capabilities and email program settings for scoped keys"
---

# Tasks: Mail Capabilities and Email Program Settings for Scoped Keys

**Input**: Design documents from `/specs/025-mail-capabilities/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1066 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Create `api/components/schemas/MailPasswordPolicy.yaml`, `MailConnection.yaml`, `AccountMailServer.yaml`, `AccountMailSettings.yaml`; add required `mail` to `AccountCapabilities.yaml` and the four counts to `UsageSummary.yaml`; register the new schemas in `api/components/schemas/_index.yaml`
- [ ] T002 Create `api/modules/me/mail-settings.yaml`, register it in `api/modules/me/_index.yaml` and `api/openapi.yaml`; extend the example/description of `api/modules/me/capabilities.yaml` and `api/modules/usage/summary.yaml`; document the tab gates and the `custom_mailfilter` refusal in `api/modules/mail/user-autoresponder.yaml`, `user-spamfilter.yaml`, `user-filters.yaml`
- [ ] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Foundational (blocking prerequisites)

- [ ] T004 Add `SystemConfigService::rawSection(string $section): array` (parsed section, `[]` without table or row) in `app/Services/SystemConfigService.php`
- [ ] T005 Create `app/Services/AccountMailService.php` with `tabEnabled(string $setting)`, `passwordPolicy()`, `customLogin()`, `accountMailServers(int $clientId)` (research R2, R5, R6)

**Checkpoint**: full suite green

---

## Phase 3: User Story 1 — Panel reads the mail capabilities (P1) 🎯 MVP

### Tests (write first, must fail)

- [ ] T006 [US1] Extend `tests/Feature/MeCapabilitiesApiTest.php`: exact `mail` block for a client key; tab switches set/unset (missing → true); `custom_rules` false even with the custom rules tab on; `spamfilter_policy` with world-readable, group-readable and unreadable policies; `dkim` with usable, empty and `/` paths and a hosting-only server; password policy set / missing (8, 0) / empty (0) and `ascii_only`; `custom_login`; admin naming the client gets the same block; no datalog

### Implementation

- [ ] T007 [US1] Implement `AccountMailService::capabilities(int $clientId)` and add `mail` in `AccountCapabilitiesService::capabilities()`

**Checkpoint**: US1 tests green

---

## Phase 4: User Story 2 — Panel shows email program settings (P1)

### Tests (write first, must fail)

- [ ] T008 [US2] Create `tests/Feature/MeMailSettingsApiTest.php`: exact response with `[SERVERNAME]` substitution; empty `webmail_url` apache/nginx fallbacks; assigned servers first then hosting servers by id, duplicates once, mirrors and non-mail servers skipped; `webmail_link` y/n/missing; no servers → `[]`; reseller own/own client/other 404; admin without `client_id` 422, unknown 404; client naming another 404; unknown parameter 400; invalid `client_id` 422; 401 without key; no datalog

### Implementation

- [ ] T009 [US2] Implement `AccountMailService::settings(int $clientId)` and `webmailUrl()`; create `app/Http/Controllers/Api/V1/MeMailSettingsController.php`; route `me/mail-settings` in `routes/api/me.php`

**Checkpoint**: US2 tests green

---

## Phase 5: User Story 3 — Tab rules for customer keys (P2)

### Tests (write first, must fail)

- [ ] T010 [US3] Create `tests/Feature/MailTabEnforcementTest.php`: client `custom_mailfilter` 422 typed and nothing journaled, admin 200; autoresponder PUT/DELETE 403 typed with `feature` when the tab is off, GET 200, admin 200; filter POST/PUT/DELETE and spamfilter `move_junk`/`purge_*` 403 when the filter tab is off, filter GET 200, admin 200; tabs on → client writes succeed; reseller key on its client's mailbox follows the same rules; missing `sys_ini` → tabs on

### Implementation

- [ ] T011 [US3] Create `app/Http/Middleware/RequireMailTab.php`, alias `mail.tab` and priority in `bootstrap/app.php`, apply to the autoresponder and filter write routes in `routes/api/mail.php`; add the field gate (`authorize`/`failedAuthorization`) and the typed `custom_mailfilter` refusal (`after`) to `app/Http/Requests/UpdateMailUserSpamFilterRequest.php`

**Checkpoint**: US3 tests green, `MailUserSubresourceApiTest` unchanged and green

---

## Phase 6: User Story 4 — Usage counts (P3)

- [ ] T012 [US4] Extend `tests/Feature/UsageSummaryApiTest.php` (16 counts; catch-alls, alias domains, filters, fetchmail used/limit, unlimited → null)
- [ ] T013 [US4] Add the four keys to `ClientLimitService::USAGE_COUNT_COLUMNS` and `countSpecForColumn()` in `app/Services/ClientLimitService.php`

---

## Phase 7: Polish

- [ ] T014 [P] Document the mail capabilities, `GET /me/mail-settings`, the tab gates and the new counts in `README.md`; note setting-name `feature` values and the `custom_mailfilter` field type in `docs/problems.md`
- [ ] T015 Run Pint on changed PHP files and the full suite in Docker
- [ ] T016 Deploy to isp-test and run `specs/025-mail-capabilities/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → Phase 2 → US1 → US2 (shares `AccountMailService`) → US3 (uses `tabEnabled`) → US4 → Polish. T001/T002 and
T012/T014 parallel.
