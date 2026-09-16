---

description: "Task list for spec 038 — password policy for non-mail users"
---

# Tasks: Password Policy for Non-Mail Users

**Input**: Design documents from `/specs/038-password-policy-non-mail/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1237 on `8d75316`; 1244 passing after this feature).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/PasswordPolicy.yaml` (`min_length`, `min_strength`); add the required `password_policy` property to `sites` in `api/components/schemas/AccountSitesCapabilities.yaml` and extend the description and example of `api/modules/me/capabilities.yaml`
- [x] T002 [P] State the rule on every affected field — see the deviation note below: it is documented in the resource schemas (`Client.password`, `FtpUser.password`, `ShellUser.password`, `WebdavUser.password`, `WebFolderUser.password`, `DatabaseUser.database_password`, `WebDomain.stats_password`) rather than repeated in 16 endpoint descriptions
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [x] T004 [US1] [US2] Create `tests/Feature/PasswordPolicyNonMailTest.php`: with policy 8/3 a weak password is refused on every field of data-model.md (client create and update, reseller create, FTP, shell, WebDAV, database user, `stats_password`), with the legacy message, nothing journaled and an unchanged stored hash on updates; a compliant password is accepted on each
- [x] T005 [US3] [P] Same class: the message names length and strength with a strength configured, and length only when `min_password_strength` is 0; a policy without a length falls back to the legacy default of 8
- [x] T006 [US1] [P] Same class: an absent or empty password is not judged (a shell user created with a key only; a blank password on a client update still means "no change"); client, reseller and admin keys are all enforced
- [x] T007 [US5] [P] Extend `tests/Feature/MeCapabilitiesApiTest.php`: `sites.password_policy` in the exact-JSON view and its key list, with the installation defaults

---

## Phase 3: Implementation

- [x] T008 [US1] Create `app/Support/PasswordPolicy.php` with the strength table, message building and `violation()` for length+strength, moved from `MailPasswordPolicy`
- [x] T009 [US4] Reduce `app/Support/MailPasswordPolicy.php` to the mail-only ASCII branch delegating to `PasswordPolicy`, keeping its constants and `violation()` signature so spec 028 expectations are untouched
- [x] T010 [US1] Create `app/Services/PasswordPolicyService.php` reading `[misc] min_password_length` (default 8) and `min_password_strength` (default 0), with `fromMisc()` as the single mapping
- [x] T011 [US1] Create `app/Rules/InstallationPassword.php` using the service and the support class
- [x] T012 [US1] [US2] Attach the rule in all 13 request classes and drop the hard-coded `min:8` on clients
- [x] T013 [US5] Report `password_policy` in the `sites` block of `app/Services/AccountSitesService.php`

---

## Phase 4: Fixtures

- [x] T014 Update the existing suites to compliant passwords (`FtpUserApiTest`, `ShellUserApiTest`, `WebdavUserApiTest` — including its re-digest assertion, `WebFolderApiTest`, `WebDatabaseUserApiTest`) and add `password_policy` to the capabilities key list

---

## Phase 5: Documentation

- [x] T015 README: the upgrade note — the exact endpoints and fields, what a compliant generator must satisfy, that weak-password integrations begin to fail, and that production rollout is the operator's decision

---

## Phase 6: Verification

- [x] T016 Full suite green in Docker on PHP 8.3 (1244 passing, 10161 assertions); Pint clean on every changed file
- [x] T017 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client — deployed `bfa2e0a`, every step matched except one unobserved line (quickstart.md §4)
- [x] T018 Clean up per quickstart.md §3 and record the results in this file — no leftovers of this run, QA keys 106–109 removed, system configuration untouched

---

## Implementation notes

- **Deviation on T002 (owner-delegated 2026-09-16):** the rule is documented on the **field** in each resource
  schema instead of in the POST and PUT prose of eight endpoint files. One place per field covers both operations,
  is where a consumer reads the constraint, and avoids inventing anchors in PUT descriptions that never mentioned the
  field. The endpoints' behaviour is unchanged.
- **The reseller endpoint is `/resellers`, not `/clients/resellers`** — its own admin-only prefix
  (`routes/api/client.php`, `api/openapi.yaml`). The first draft of the spec, the README table and the contract named
  the wrong path; all three are corrected, and the test exercises the real one.
- **The scripted wiring needed two repairs.** The first pass appended the rule without a separator
  (`'max:255' new InstallationPassword`) and skipped the three request classes that have no `use` block at all
  (`StoreClientRequest`, `UpdateClientRequest`, `UpdateWebDatabaseUserRequest`, where the import goes after the
  namespace). Both were fixed and verified with `php -l` on all 13 files plus Pint.
- **Fixture churn was the expected signal, not collateral damage.** Nine existing tests posted throwaway passwords;
  where a test asserted a *controller-level* error (duplicate, overlong or blacklisted name) validation now
  short-circuits with only a password error, so those cases needed compliant passwords to keep testing what they
  were written for. `WebdavUserApiTest`'s digest assertion was updated to the new plaintext.
- **No mailbox expectation changed**: `MailboxAccessPasswordPolicyTest` and the mail suites pass untouched.
- **One live line was not observed** (quickstart §4): the mailbox create with a *compliant* password. The first
  attempt failed on an unrelated payload mistake (the mailbox `name` field was missing from the check script), and by
  the time the corrected call ran its output fell outside the captured tail; the temporary client was deleted in the
  same pass, so it could not be re-observed without rebuilding the fixture. The mailbox *refusal* was observed live
  with the policy message, and mailbox acceptance is covered by the green mail suite.
