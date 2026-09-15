# Implementation Plan: Mail Capabilities and Email Program Settings for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/025-mail-capabilities/spec.md`

## Summary

- `GET /me/capabilities` gains `mail` from a new `AccountMailService::capabilities()`: tab switches and custom login
  from `sys_ini` [mail], readable spam filter policies with the client's own scope, DKIM from the account mail
  servers' [mail] `dkim_path`, and the password policy from `sys_ini` [misc]/[mail] with the ISPConfig runtime
  defaults.
- `GET /me/mail-settings` (new invokable controller) returns `AccountMailService::settings()`: per account mail server
  (assigned first, then servers hosting its mail domains) host name, fixed IMAP/POP3/SMTP connections and the resolved
  webmail URL; plus `custom_login`, `webmail_link` and the password policy.
- `/usage/summary` counts gain `mail_catchalls`, `mail_alias_domains`, `mail_filters`, `fetchmail_accounts` via
  `ClientLimitService::USAGE_COUNT_COLUMNS`.
- Mailbox sub-resources enforce the legacy tab rules for non-admin keys: a `mail.tab:<setting>` route gate
  (autoresponder writes, filter writes), a field-based gate in `UpdateMailUserSpamFilterRequest` (`move_junk`,
  `purge_*`) and a typed 422 for `custom_mailfilter`.
- `SystemConfigService::rawSection()` reads `sys_ini` without failing when the singleton is absent.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads only (`sys_ini`, `server`, `client`, `sys_group`, `sys_user`,
`spamfilter_policy`, `mail_domain`, `mail_forwarding`, `mail_user_filter`, `mail_get`)  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1066)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: capabilities + ≤ 4 queries and 1 per considered mail server; mail-settings ≤ 5 queries + 1 per
server  
**Constraints**: no writes on reads; no server paths, keys or secrets in responses; refusals journal nothing  
**Scale/Scope**: 1 new GET endpoint, 1 extended GET, 1 extended summary, 3 write gates, 4 new schemas

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `api/modules/me/mail-settings.yaml`, the `mail` block in `AccountCapabilities.yaml`,
  schemas `AccountMailSettings.yaml`, `AccountMailServer.yaml`, `MailConnection.yaml`, `MailPasswordPolicy.yaml`, the
  four counts in `UsageSummary.yaml` and the 403/422 notes in `api/modules/mail/user-*.yaml` are written and registered
  before code (contracts/mail-capabilities.md).
- [x] **Datalog-only writes (II)**: no new writes; gates refuse before any datalog entry.
- [x] **Legacy parity (III)**: `mail_user.tform.php` 350–480, `mail_user_edit.php` 99–133, `mail_domain.tform.php`
  105–141, `webmailer.php`, `auth.inc.php` 211–228, `mail_plugin_dkim.inc.php::check_system()` (research R2–R8);
  deviations owner-delegated in the spec.
- [x] **Route discipline (IV)**: static path `me/mail-settings` in `routes/api/me.php`; gate alias `mail.tab` in
  `bootstrap/app.php` with the other scope gates (before route-model binding); thin controllers.
- [x] **HTTP contract (V)**: bare objects; problem+json 400/401/403/404/422 with spec 023 types.
- [x] **No schema changes**: no migrations; test schemas already have the mail tables and `server.config`.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/025-mail-capabilities/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/mail-capabilities.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/AccountCapabilities.yaml     # + mail
api/components/schemas/AccountMailSettings.yaml     # NEW
api/components/schemas/AccountMailServer.yaml       # NEW
api/components/schemas/MailConnection.yaml          # NEW
api/components/schemas/MailPasswordPolicy.yaml      # NEW
api/components/schemas/UsageSummary.yaml            # + 4 counts
api/components/schemas/_index.yaml
api/modules/me/capabilities.yaml                    # example + description
api/modules/me/mail-settings.yaml                   # NEW GET /me/mail-settings
api/modules/me/_index.yaml
api/modules/usage/summary.yaml                      # description
api/modules/mail/user-autoresponder.yaml            # 403 tab gate
api/modules/mail/user-spamfilter.yaml               # 403 tab gate, 422 custom_mailfilter
api/modules/mail/user-filters.yaml                  # 403 tab gate
api/openapi.yaml                                    # path /me/mail-settings
app/Services/SystemConfigService.php                # rawSection()
app/Services/AccountMailService.php                 # NEW capabilities, settings, tab and policy readers
app/Services/AccountCapabilitiesService.php         # mail block
app/Services/ClientLimitService.php                 # 4 usage count columns
app/Http/Controllers/Api/V1/MeMailSettingsController.php  # NEW invokable
app/Http/Middleware/RequireMailTab.php              # NEW 'mail.tab:<setting>'
app/Http/Requests/UpdateMailUserSpamFilterRequest.php     # tab fields 403, custom_mailfilter 422
bootstrap/app.php                                   # alias + priority
routes/api/me.php, routes/api/mail.php
docs/problems.md, README.md
tests/Feature/MeCapabilitiesApiTest.php             # mail block
tests/Feature/MeMailSettingsApiTest.php             # NEW US2
tests/Feature/MailTabEnforcementTest.php            # NEW US3
tests/Feature/UsageSummaryApiTest.php               # 16 counts
```

**Structure Decision**: one service (`AccountMailService`) owns every mail-side account read so capabilities, settings
and the write gates share the same setting readers and defaults.

## Legacy Research (Phase 0 focus)

See research.md: R1 target account, R2 tab switches, R3 spam filter levels, R4 DKIM, R5 password policy, R6 account
mail servers, R7 host names and ports, R8 webmail URL, R9 usage counts, R10 sys_ini access, R11 shapes and problems.

## Complexity Tracking

None.
