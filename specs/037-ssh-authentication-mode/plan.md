# Implementation Plan: SSH Authentication Mode for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/037-ssh-authentication-mode/spec.md`

## Summary

- `SitesConfigService::sshAuthenticationMode()` reads the `[sites]` section instead of `[misc]` (research R1–R3), so
  the administrator's choice takes effect.
- `AccountSitesService` reports it as `sites.shell.authentication` (`password_or_key` | `password` | `key`) in
  `GET /me/capabilities`.
- `StoreShellUserRequest` and `UpdateShellUserRequest` gain an `after()` closure that refuses a non-empty credential
  the installation does not accept, for client and reseller keys, with a typed 422 (spec 033 pattern).
- `ShellUserController::applySshAuthenticationMode()` stays for administrator keys.
- Contract first: `AccountSitesCapabilities.yaml`, `api/modules/me/capabilities.yaml`, `ShellUser.yaml`,
  `api/modules/sites/shell-users.yaml`.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — reads `sys_ini`, `shell_user`, `client`; no new writes
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1230 on `c36c127`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: no extra query — the `[sites]` section is already read for prefixes in the same request path
**Constraints**: administrator keys must keep their current, permissive behaviour
**Scale/Scope**: 1 new capability field, 2 request refusals, 1 corrected config read

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: the capability field and both refusals are written into the OpenAPI files before the PHP.
- [x] **Datalog-only writes (II)**: no writes are added; refusals happen during validation, before any save.
- [x] **Legacy parity (III)**: every rule cites its call site (`system_config.tform.php:256-260`,
      `shell_user_edit.php:100` and `:131-137`), and the one deliberate deviation — reading `[sites]` and refusing
      scoped keys — is recorded as an owner-delegated decision with its reason (legacy's dead code).
- [x] **Route discipline (IV)**: no new route; existing endpoints only.
- [x] **HTTP contract (V)**: 422 problem+json with `errors` and `error_types`, exactly as spec 033 emits.
- [x] **Tests required**: capability values, both refusals on create and update, the accepted empty/unchanged cases,
      and the administrator path (existing test, moved to the real section).
- [x] **No schema changes**: no migrations.

## Project Structure

### Documentation (this feature)

```
specs/037-ssh-authentication-mode/
├── spec.md
├── checklists/requirements.md
├── plan.md
├── research.md
├── data-model.md
├── contracts/ssh-authentication.md
├── quickstart.md
└── tasks.md
```

### Source (repository root)

```
api/
├── components/schemas/AccountSitesCapabilities.yaml   # + shell.authentication
├── components/schemas/ShellUser.yaml                  # corrected Authentication section
├── modules/me/capabilities.yaml                       # description + example
└── modules/sites/shell-users.yaml                     # POST/PUT refusal
app/
├── Services/SitesConfigService.php                    # read [sites]
├── Services/AccountSitesService.php                   # report the mode
├── Http/Requests/StoreShellUserRequest.php            # refusal
└── Http/Requests/UpdateShellUserRequest.php           # refusal
README.md
tests/
├── Support/SitesApiTestCase.php                       # fixture writes [sites]
├── Feature/ShellUserApiTest.php                       # admin path on the real section
├── Feature/ShellUserAuthenticationModeTest.php        # new: scoped-key refusals
└── Feature/MeCapabilitiesApiTest.php                  # the reported value
```

## Phases

1. **Phase 0 — research** (done): R1–R7 in research.md.
2. **Phase 1 — contract**: the capability field and both endpoint descriptions; `SwaggerSpecServerTest` proves the
   spec parses.
3. **Phase 2 — tests first**: the new refusal test class, the capability cases and the moved fixture, all failing.
4. **Phase 3 — implementation**: the corrected config read, the reported value, the two `after()` closures.
5. **Phase 4 — docs**: README note on the mode and the corrected section.
6. **Phase 5 — verification**: full suite, Pint, deploy, quickstart on isp-test with a temporary client and a
   restored system configuration, cleanup.

## Complexity Tracking

| Deviation | Why | Alternative rejected |
|---|---|---|
| Reading `[sites]` instead of legacy's `[misc]` | legacy's clearing is dead code, so mirroring it ships a feature that does nothing (research R2) | keep `[misc]`: the capability would always report `password_or_key` and no credential would ever be refused |
| Refusing scoped keys instead of clearing | silent credential loss is the defect being fixed; precedents in specs 033 and 025 | clear silently for everyone: the customer is not told, which is the current complaint |
