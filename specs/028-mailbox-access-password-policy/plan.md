# Implementation Plan: Mailbox Access Switches and Password Policy

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/028-mailbox-access-password-policy/spec.md`

## Summary

- `App\Support\MailPasswordPolicy`: exact port of the legacy strength table and `password_check()` messages
  (`strength()`, `violation(password, policy)`); `App\Rules\MailboxPassword` applies it with
  `AccountMailService::passwordPolicy()` (feature 025) on the three mailbox password inputs, replacing `min:5`.
- `MailUser` gains the four switches (fillable, visible, YesNoBoolean casts, `n` defaults); the mailbox requests accept
  them; `MailUserService::applyAccessDerivations()` sets the Dovecot companion columns before the datalog'd save.
- The spec 019 lock guard covers `disablesmtp` through `BaseModel::save()` unchanged.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — `mail_user` (datalog `i`/`u`); reads `sys_ini`  
**Testing**: PHPUnit unit + feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1114)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: + 1 `sys_ini` query per password validation  
**Constraints**: plaintext passwords never logged or returned; datalog-only writes; lock guard unchanged  
**Scale/Scope**: 4 fields, 3 password inputs, 1 support class, 1 rule

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `MailUser.yaml` switches and password description, `MailUserPassword.yaml`, `users.yaml`,
  `user-password.yaml` updated before code (contracts/mailbox-access-password.md).
- [x] **Datalog-only writes (II)**: switches and companion columns in the `mail_user` model save (legacy's direct SQL
  companion update becomes part of the datalog entry).
- [x] **Legacy parity (III)**: `mail_user.tform.php` 129–145, 321–354; `mail_user_edit.php` 363–392;
  `validate_password.inc.php`; `auth.inc.php` 211–228; `tform_base.inc.php` 1057–1064, 1367 (research R1–R5); deviations
  owner-delegated in the spec.
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: 422 validation problems, 403 `account-locked`; codes unchanged.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/028-mailbox-access-password-policy/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/mailbox-access-password.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/MailUser.yaml              # 4 switches, password policy description
api/components/schemas/MailUserPassword.yaml      # password policy description
api/modules/mail/users.yaml                       # descriptions
api/modules/mail/user-password.yaml               # descriptions
app/Support/MailPasswordPolicy.php                # NEW strength + messages
app/Rules/MailboxPassword.php                     # NEW validation rule
app/Models/MailUser.php                           # switches
app/Services/MailUserService.php                  # applyAccessDerivations()
app/Http/Requests/MailUserRequest.php             # normalize switch flags
app/Http/Requests/StoreMailUserRequest.php        # switches, password rule
app/Http/Requests/UpdateMailUserRequest.php       # switches, password rule
app/Http/Requests/UpdateMailUserPasswordRequest.php  # password rule
app/Http/Controllers/Api/V1/MailUserController.php   # derivations on store/update
tests/Unit/MailPasswordPolicyTest.php             # NEW strength table, messages
tests/Feature/MailboxAccessPasswordPolicyTest.php # NEW US1–US3
README.md
```

**Structure Decision**: the policy is a pure support class (unit-testable, reusable for the other password endpoints
listed in research R6) behind one validation rule; switch derivations live with the other mailbox derivations in
`MailUserService`.

## Legacy Research (Phase 0 focus)

See research.md: R1 validation points, R2 policy values and messages, R3 strength algorithm, R4 access switches, R5
lock guard, R6 other password fields.

## Complexity Tracking

None.
