---

description: "Task list for spec 040 — clearable string settings in the system configuration"
---

# Tasks: Clearable String Settings in the System Configuration

**Input**: Design documents from `/specs/040-clearable-string-settings/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1244 on `bfa2e0a`).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] State the clearing rule in the descriptions of `api/modules/system/system-config.yaml` and the per-section configuration endpoints: an empty string (or `null`) clears a text setting; numbers, `y`/`n` switches and `web_php_options` still refuse one
- [ ] T002 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [ ] T003 [US1] Create `tests/Feature/SystemConfigClearableTest.php`: set and then clear `webmail_url` (`mail`), `dns_external_slave_fqdn` (`dns`), `ssh_authentication` (`sites`) and `company_name` (`misc`) with `""` → 200 and an empty value on read; `null` behaves like `""`; clearing an already empty setting is accepted
- [ ] T004 [US2] [P] Same class: `web_php_options` with `[]` or `""` → 422 naming the field (legacy `NOTEMPTY`); an integer setting with `""` → 422; a `y`/`n` setting with `""` → 422
- [ ] T005 [US3] [P] Same class: clearing one setting leaves every other key of the blob unchanged, including keys the contract does not expose (`phpmyadmin_url`, `webftp_url`, `client_protection`); the stored form is `key=`; the whole-document route behaves like the per-section route

---

## Phase 3: Implementation

- [ ] T006 [US1] In `app/Services/SystemConfigService::normalizeInput()`, map `null` to `''` for fields whose type is `string`, before the trim and strip filters, leaving every other type untouched
- [ ] T007 [US3] Confirm the whole-document request path normalizes per section the same way, extending it if it does not

---

## Phase 4: Verification

- [ ] T008 Full suite green in Docker on PHP 8.3; Pint clean on the changed files
- [ ] T009 Deploy the pushed commits to isp-test and run quickstart.md §2, backing up `sys_ini` first
- [ ] T010 Restore every changed setting, prove the blob byte-identical, clean up per quickstart.md §3 and record the results in this file
