---

description: "Task list for spec 040 — clearable string settings in the system configuration"
---

# Tasks: Clearable String Settings in the System Configuration

**Input**: Design documents from `/specs/040-clearable-string-settings/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1244 on `bfa2e0a`; 1258 passing after this feature).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] State the clearing rule in the description of `api/modules/system/system-config.yaml`: an empty string (or `null`) clears a text setting; numbers, `y`/`n` switches and `web_php_options` still refuse one
- [x] T002 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [x] T003 [US1] Create `tests/Feature/SystemConfigClearableTest.php`: set and then clear `webmail_url` (`mail`), `dns_external_slave_fqdn` (`dns`), `ssh_authentication` (`sites`) and `company_name` (`misc`) with `""` → 200 and an empty value on read; `null` behaves like `""`; clearing an already empty setting is accepted
- [x] T004 [US2] [P] Same class: `web_php_options` with `[]` or `""` → 422 naming the field (legacy `NOTEMPTY`); an integer setting with `""` → 422; a `y`/`n` setting with `""` → 422
- [x] T005 [US3] [P] Same class: clearing one setting leaves every other line of the blob unchanged, including unexposed legacy keys (`phpmyadmin_url`, `client_protection`, `webdavuser_prefix`, `dkim_path`); the stored form is `key=`; the whole-document route behaves like the per-section route

---

## Phase 3: Implementation

- [x] T006 [US1] In `app/Services/SystemConfigService::normalizeInput()`, map `null` to `''` for fields whose type is `string`, before the trim and strip filters, leaving every other type untouched
- [x] T007 [US3] Confirm the whole-document request path normalizes per section the same way — no change was needed: `UpdateSystemConfigRequest::prepareForValidation()` already calls `normalizeInput()` for each submitted section, so it inherited the fix. Verified by test rather than assumed

---

## Phase 4: Verification

- [x] T008 Full suite green in Docker on PHP 8.3 (1258 passing, 10250 assertions); Pint clean on the changed files
- [x] T009 Deploy the pushed commits to isp-test and run quickstart.md §2 — deployed `a4a5eb4`, every check matched (quickstart.md §4)
- [x] T010 Restore every changed setting, prove the configuration correct, clean up per quickstart.md §3 and record the results in this file — `webmail_url` restored and verified in the stored blob, QA keys 113 and the restore key removed, journal fully processed

---

## Implementation notes

- **The whole-document route needed no code change** (T007). Its request already normalizes each submitted section
  through the same service method, so fixing `normalizeInput()` fixed both routes. The plan allowed for extending it;
  the test proved that unnecessary.
- **The live check exposed a flaw in my own check script, and the byte-identity assertion caught it.** The script
  assumed all four settings started empty on isp-test. `webmail_url` was not — it held
  `https://[SERVERNAME]:8081/webmail` — so the "clear" step blanked a real setting. The comparison against the backup
  flagged exactly that one line; the value was restored through the API and confirmed in the stored blob
  (`nwebmail_url=https://[SERVERNAME]:8081/webmail`), with the journal drained afterwards. Reading the current values
  *before* changing them, as the quickstart's step 1 prescribes, is what makes that recoverable — the script printed
  them, which is how the original value was known after the backup file had been removed.
- **Each system-configuration write journals one `sys_datalog` update.** Twelve were still pending when the check
  finished its last step; they drained normally, and the final state was verified only after
  `server.updated` reached the last journal id.
