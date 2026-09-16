# Implementation Plan: Password Policy for Non-Mail Users

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/038-password-policy-non-mail/spec.md`

## Summary

- `App\Support\PasswordPolicy`: the neutral strength table, message building and `violation()` extracted from
  `MailPasswordPolicy`, which keeps only its mail-only ASCII branch and delegates the rest (spec 028 behaviour
  unchanged).
- `App\Services\PasswordPolicyService`: reads `[misc] min_password_length` (default 8) and `min_password_strength`
  (default 0) from the system configuration.
- `App\Rules\InstallationPassword`: attached to every field legacy validates — client and reseller `password`, FTP,
  shell, WebDAV and web folder `password`, `database_password`, and `stats_password`.
- `AccountSitesService` reports `password_policy` in the `sites` block of `/me/capabilities` (FR-009).
- Test fixtures move to compliant passwords; `ClientApiTest`'s short-password case asserts the policy message.
- README gains the upgrade note (FR-010); every affected endpoint's contract states the rule.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — reads `sys_ini`; no new writes
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1237 on `8d75316`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: one configuration read per request, shared by every password field
**Constraints**: spec 028 mailbox behaviour must not change in any observable way
**Scale/Scope**: 8 endpoint pairs, 1 new rule, 1 new support class, 1 new service, 1 capability field

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: the rule is written into every affected endpoint's contract and the capability schema
      before the PHP.
- [x] **Datalog-only writes (II)**: no writes; refusals happen during validation.
- [x] **Legacy parity (III)**: field list from the legacy tform validators (research R1), computation and messages
      ported in spec 028, defaults from `auth.inc.php` (R2).
- [x] **Route discipline (IV)**: no new route.
- [x] **HTTP contract (V)**: 422 problem+json with `errors.<field>`; no new problem type.
- [x] **Tests required**: one class covering every affected endpoint plus the capability field; the mailbox suite
      must keep passing untouched.
- [x] **No schema changes**: no migrations.

## Project Structure

```
api/
├── components/schemas/AccountSitesCapabilities.yaml   # + password_policy
├── components/schemas/PasswordPolicy.yaml             # new (shared shape)
├── modules/me/capabilities.yaml                       # description + example
├── modules/client/clients.yaml                        # rule stated
├── modules/client/resellers.yaml                      # rule stated
└── modules/sites/{ftp-users,shell-users,webdav-users,web-folder-users,database-users,web-domains}.yaml
app/
├── Support/PasswordPolicy.php                         # new (neutral)
├── Support/MailPasswordPolicy.php                     # keeps the ASCII branch, delegates
├── Services/PasswordPolicyService.php                 # new
├── Rules/InstallationPassword.php                     # new
├── Services/AccountSitesService.php                   # + password_policy
└── Http/Requests/… (8 pairs)                          # attach the rule
README.md                                              # upgrade note
tests/
├── fixtures/sys_ini_config.ini                        # unchanged policy, compliant test passwords elsewhere
├── Feature/PasswordPolicyNonMailTest.php              # new
├── Feature/ClientApiTest.php                          # short-password case re-framed
└── Feature/{FtpUser,ShellUser,WebdavUser,WebFolderUser,WebDatabaseUser,WebDomain}ApiTest.php  # compliant passwords
```

## Phases

1. **Phase 0 — research** (done): R1–R7.
2. **Phase 1 — contract**: capability field + endpoint descriptions; `SwaggerSpecServerTest` proves it parses.
3. **Phase 2 — tests first**: the new policy test class and the capability case, failing.
4. **Phase 3 — implementation**: support class split, service, rule, request wiring, capability field.
5. **Phase 4 — fixtures**: compliant passwords across the existing suites; re-frame the client short-password case.
6. **Phase 5 — docs**: README upgrade note.
7. **Phase 6 — verification**: full suite, Pint, deploy, live check on isp-test with a temporary client, cleanup.

## Complexity Tracking

| Deviation | Why | Alternative rejected |
|---|---|---|
| Splitting `MailPasswordPolicy` | the ASCII branch is mail-only and short-circuits the rest; non-mail callers must not inherit it | pass `ascii_only: false` from every call site — easy to get wrong and hides the distinction |
| Enforcing for administrator keys | legacy validates the form for every user type; a provider's policy that an admin key can bypass is not a policy | scope to client/reseller keys only — would leave provisioning (an admin key) unchecked, which is the main source of weak passwords |
