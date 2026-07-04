# Implementation Plan: Mail Module Completion

**Branch**: `005-mail-module-completion` | **Date**: 2026-07-04 | **Spec**: `specs/005-mail-module-completion/spec.md`
**Input**: Feature specification from `/specs/005-mail-module-completion/spec.md`

**Note**: DRAFT plan for a not-yet-implemented feature, reverse-engineered from the existing OpenAPI contract (`api/modules/mail/`, 18 resource files, 77 endpoints) and the legacy ISPConfig source (`source_code/interface/web/mail/`).

## Summary

Implement the 18 remaining mail-module resources (mailboxes + 5 nested mailbox sub-resources, forwards, alias domains, fetchmail, transports, relay domains/recipients, access rules, content filters, and 4 spamfilter resources) as thin Lumen controllers over `BaseModel`-backed Eloquent models, mirroring the already-shipped `MailDomainController`/`MailDomain` pattern. All writes flow through `sys_datalog`; legacy side effects are reproduced in dedicated services (CRYPTMAIL password hashing, maildir derivation, `spamfilter_users` sync, sieve `custom_mailfilter` regeneration, `server.config` INI handling). Twelve contract↔legacy conflicts are catalogued as C-1…C-12 in the spec and must be resolved (schema fix or documented mapping) per resource before that resource is implemented.

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)  
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp  
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`)  
**Testing**: PHPUnit (`vendor/bin/phpunit`), tests in `tests/*Test.php` — optional per constitution; the spec does not request tests (Swagger-driven verification per story)  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: N/A (CRUD volumes; list endpoints paginated, `per_page` ≤ 100)  
**Constraints**: async write semantics via `sys_datalog` (spec status codes: 201 create / 200 update / 204 delete); behavioral parity with legacy ISPConfig (`source_code/interface/web/mail/`); PHP `crypt()` SHA-512 support required for CRYPTMAIL hashes  
**Scale/Scope**: 18 resources, 77 endpoints, 12 new models, 18 new controllers, 4 new services, 1 routes file edit

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: all 18 `api/modules/mail/*.yaml` definitions and their `api/components/schemas/*.yaml` already exist and are registered in `api/modules/mail/_index.yaml`; this plan implements them verbatim. Contract defects found during reverse-engineering are NOT silently patched in PHP — they are C-1…C-12 items whose resolution is a YAML fix or an explicitly documented mapping (spec, Clarifications section).
- [x] **Datalog-only writes (II)**: 12 new models all extend `App\Models\BaseModel` (see Project Structure); composite side effects (`spamfilter_users` sync, `custom_mailfilter` regeneration) also run through model `save()` — never raw SQL. One flagged exception: `mail/spamfilter/config` writes the `server.config` INI column, which legacy itself updates without datalog — recorded in Complexity Tracking, resolution per C-8.
- [x] **Legacy parity (III)**: legacy forms/actions reviewed file-by-file; validations, defaults and side effects captured in the spec's Parity section (19 numbered behaviors, with file:line citations). Intentional deviations enumerated (email immutability, policy in-use guard, no client-limit enforcement).
- [x] **Route discipline (IV)**: all routes registered in `routes/web.php` inside the `api.auth` group; nested `mail/users/{id}/…` routes precede `mail/users/{id}`; no shadowing of existing `mail/domains` routes (see Structure Decision).
- [x] **HTTP contract (V)**: status codes 201/200/204 + declared error codes; errors `{message, error}`/`{message, errors}` as in `MailDomainController`; list envelope follows the module YAMLs (`{data, pagination}` + `Pagination.yaml`) — the known constitution-vs-spec envelope tension is C-9, resolved in favor of the YAML per Principle I, matching the shipped reference controller.
- [x] **No schema changes**: zero migrations; all tables are pre-existing ISPConfig tables (`mail_user`, `mail_user_filter`, `mail_forwarding`, `mail_get`, `mail_transport`, `mail_relay_domain`, `mail_relay_recipient`, `mail_access`, `mail_content_filter`, `spamfilter_policy`, `spamfilter_users`, `spamfilter_wblist`, `server`).

## Project Structure

### Documentation (this feature)

```text
specs/005-mail-module-completion/
├── spec.md              # Feature spec (reverse-engineered, Draft)
├── plan.md              # This file
└── tasks.md             # Task list (/speckit-tasks output)
```

(No separate research.md/data-model.md — legacy research and the entity→table map live in spec.md's Parity and Key Entities sections.)

### Source Code (repository root)

```text
api/
├── modules/mail/                          # ALL 18 endpoint files EXIST — implement as-is
│   ├── _index.yaml                        # already references all resources; no edit expected
│   ├── users.yaml / user-autoresponder.yaml / user-cc.yaml / user-filters.yaml
│   ├── user-password.yaml / user-spamfilter.yaml
│   ├── forwards.yaml / alias-domains.yaml / get.yaml / transports.yaml
│   ├── relay-domains.yaml / relay-recipients.yaml / access-rules.yaml
│   ├── content-filters.yaml
│   └── spamfilter-config.yaml / spamfilter-policies.yaml / spamfilter-users.yaml / spamfilter-wblist.yaml
└── components/schemas/                    # EXIST; C-1..C-6, C-11, C-12 may require YAML corrections
    ├── MailUser.yaml, MailUserAutoresponder.yaml, MailUserCC.yaml, MailUserFilter.yaml,
    ├── MailUserPassword.yaml, MailUserSpamFilter.yaml, MailForwarding.yaml, MailAliasDomain.yaml,
    ├── MailGet.yaml, MailTransport.yaml, MailRelayDomain.yaml, MailRelayRecipient.yaml,
    ├── MailAccess.yaml, MailContentFilter.yaml, SpamfilterConfig.yaml, SpamfilterPolicy.yaml,
    └── SpamfilterUser.yaml, SpamfilterWBList.yaml

app/
├── Http/Controllers/Api/V1/               # NEW controllers (one per resource, <Entity>Controller)
│   ├── MailUserController.php             # /mail/users CRUD
│   ├── MailUserAutoresponderController.php# show/update/destroy on mail_user autoresponder columns
│   ├── MailUserCCController.php           # show/update (cc, forward_in_lda)
│   ├── MailUserPasswordController.php     # update only
│   ├── MailUserSpamFilterController.php   # show/update (move_junk, purge_*, custom_mailfilter)
│   ├── MailUserFilterController.php       # nested CRUD /mail/users/{id}/filters[/{filter_id}]
│   ├── MailForwardingController.php       # /mail/forwards CRUD (type forward|catchall|alias)
│   ├── MailAliasDomainController.php      # /mail/alias-domains CRUD (mail_forwarding, type=aliasdomain)
│   ├── MailGetController.php              # /mail/get CRUD
│   ├── MailTransportController.php        # /mail/transports CRUD
│   ├── MailRelayDomainController.php      # /mail/relay-domains CRUD
│   ├── MailRelayRecipientController.php   # /mail/relay-recipients CRUD
│   ├── MailAccessController.php           # /mail/access-rules CRUD
│   ├── MailContentFilterController.php    # /mail/content-filters CRUD
│   ├── SpamfilterConfigController.php     # /mail/spamfilter/config (index/store?/show/update — C-8)
│   ├── SpamfilterPolicyController.php     # /mail/spamfilter/policies CRUD (+ in-use delete guard)
│   ├── SpamfilterUserController.php       # /mail/spamfilter/users CRUD
│   └── SpamfilterWBListController.php     # /mail/spamfilter/wblist CRUD
├── Models/                                # NEW models — ALL extend App\Models\BaseModel
│   ├── MailUser.php                       # table mail_user, pk mailuser_id, $hidden=[password]
│   ├── MailUserFilter.php                 # table mail_user_filter, pk filter_id
│   ├── MailForwarding.php                 # table mail_forwarding, pk forwarding_id (serves forwards AND alias-domains)
│   ├── MailGet.php                        # table mail_get, pk mailget_id, $hidden=[source_password]
│   ├── MailTransport.php                  # table mail_transport, pk transport_id
│   ├── MailRelayDomain.php                # table mail_relay_domain, pk relay_domain_id
│   ├── MailRelayRecipient.php             # table mail_relay_recipient, pk relay_recipient_id
│   ├── MailAccess.php                     # table mail_access, pk access_id
│   ├── MailContentFilter.php              # table mail_content_filter, pk content_filter_id
│   ├── SpamfilterPolicy.php               # table spamfilter_policy, pk id, sys_perm_other='r'
│   ├── SpamfilterUser.php                 # table spamfilter_users, pk id
│   └── SpamfilterWBList.php               # table spamfilter_wblist, pk wblist_id
├── Services/                              # NEW services (reusable, no HTTP concerns)
│   ├── MailPasswordService.php            # CRYPTMAIL: UTF-8→ISO-8859-1 + SHA-512 crypt ($6$rounds=5000$)
│   ├── MailUserService.php                # server_id/maildir/homedir/uid/gid/login derivation from
│   │                                      #   server mail config; sys_groupid from mail_domain;
│   │                                      #   spamfilter_users upsert (priority 7, local Y, fullname)
│   ├── MailUserFilterService.php          # custom_mailfilter block regeneration
│   │                                      #   (### BEGIN/END FILTER_ID:<id>, sieve/maildrop per
│   │                                      #   server mail_filter_syntax) + mail_user datalog update
│   └── ServerIniConfigService.php         # read/parse + serialize/write server.config INI sections
│                                          #   ('server' + 'mail') for spamfilter/config (C-8)
└── Casts/
    └── YesNoBoolean.php                   # EXISTS — reuse for lowercase y/n flags (uppercase Y/N and
                                           #   W/B stay plain strings; see spec FR-005)

routes/web.php                             # EDIT — register 77 routes inside the api.auth group
```

**Structure Decision**: One controller per contract resource (18) keeps controllers thin and maps 1:1 to the YAML files; the five mailbox sub-resources share the `MailUser` model rather than gaining pseudo-models. `MailForwarding` is a single model reused by two controllers (`MailForwardingController` scopes `type IN (forward,catchall,alias)`; `MailAliasDomainController` forces/scopes `type='aliasdomain'` — C-1). `SpamfilterConfig` gets a controller + service but **no model**, because it manipulates INI sections inside `server.config` (C-8).

Routes slot into `routes/web.php` immediately after the existing `mail/domains` block, in this order (specific-before-general within the `mail` prefix):

```php
// 1. Mail user NESTED sub-resources — MUST precede mail/users/{id}
GET/PUT/DELETE mail/users/{id}/autoresponder      → MailUserAutoresponderController
GET/PUT        mail/users/{id}/cc                 → MailUserCCController
PUT            mail/users/{id}/password           → MailUserPasswordController
GET/PUT        mail/users/{id}/spamfilter         → MailUserSpamFilterController
GET/POST       mail/users/{id}/filters            → MailUserFilterController
GET/PUT/DELETE mail/users/{id}/filters/{filter_id}→ MailUserFilterController
// 2. Mail users general
GET/POST mail/users ; GET/PUT/DELETE mail/users/{id} → MailUserController
// 3. Forwarding family
mail/forwards, mail/alias-domains (CRUD each)
// 4. Spamfilter subtree (static 'spamfilter' segment — cannot be shadowed by mail/users routes,
//    but keep the whole subtree above any future catch-all mail/{...} pattern)
GET/POST mail/spamfilter/config ; GET/PUT mail/spamfilter/config/{server_id}
mail/spamfilter/policies, mail/spamfilter/users, mail/spamfilter/wblist (CRUD each)
// 5. Routing & filtering admin
mail/transports, mail/relay-domains, mail/relay-recipients,
mail/access-rules, mail/content-filters, mail/get (CRUD each)
```

Existing `mail/domains` routes are untouched and cannot collide (distinct second segment). `mail/get` is a static two-segment path; nothing in the module registers `mail/{param}`, so no shadowing is possible today — the ordering above still keeps the module future-proof.

## Legacy Research (Phase 0 focus)

Completed during reverse-engineering; full detail with file:line citations lives in spec.md → "ISPConfig Parity & Datalog Impact". Summary of what was mined and where:

| Area | Legacy source consulted | Key findings feeding this plan |
|---|---|---|
| Form definitions | `source_code/interface/web/mail/form/*.tform.php` (16 files) | field lists, regex validators, defaults (`access=REJECT/OK`, `sort_order=5`, `priority=5/7`, `wb=B`), y/n vs Y/N casing, admin-only fields (`type=client`), UNIQUE constraints |
| Mailbox side effects | `mail_user_edit.php` | server_id/maildir/homedir/uid/gid/login derivation, quota MB→bytes, sys_groupid forcing, duplicate-vs-forward guard, spamfilter_users upsert (priority 7), autoresponder date clearing, dovecot flag propagation (not API-exposed) |
| Password encryption | `interface/lib/classes/tform_base.inc.php:1372`, `auth.inc.php::crypt_password` | CRYPTMAIL = ISO-8859-1 conversion + SHA-512 crypt `$6$rounds=5000$` + 16-hex salt → `MailPasswordService` |
| Filter engine | `mail_user_filter_edit.php`, `interface/lib/plugins/mail_user_filter_plugin.inc.php` | `### BEGIN/END FILTER_ID:<id>` block regeneration in `custom_mailfilter`, prepend-new/skip-inactive semantics, sieve vs maildrop switch → `MailUserFilterService`; stored enum values ≠ contract enums (C-2) |
| Forward family | `mail_forward_edit.php`, `mail_alias.tform.php`, `mail_domain_catchall.tform.php`, `mail_aliasdomain_edit.php`, `templates/mail_aliasdomain_edit.htm` | destination normalization, mailbox-collision guard, catchall regex, hidden `type=aliasdomain`, dest-domain-derived server_id/sys_groupid (C-1) |
| Transports | `mail_transport_edit.php`, `validate_mail_transport` | unique-per-server + not-a-maildomain validators, server_id revert on update, UI transport composition (dropped in API) |
| Spamfilter config | `spamfilter_config.tform.php` (`db_table=server`), `spamfilter_config_edit.php` | INI-section read/write of `server.config` via `ini_parser`, admin-only, direct UPDATE (no datalog) → `ServerIniConfigService`, C-8 |
| Rspamd effects | `server/plugins-available/rspamd_plugin.inc.php:115-122, 372-479` | `mail_access` → global wblist files; `spamfilter_wblist` requires resolvable `rid` (rid=0 inert, C-10) — documentation only, no interface-side work |
| Table truth | `ISPConfig-DB-Structure.txt` | PKs, column names (`target` not `action_value`, `destination` not `destination_username`), enum sets, DB defaults; confirmed `mail_alias_domain` table does NOT exist |
| List definitions | `web/mail/list/*.list.php` | filterable columns backing each index endpoint's query params |

**Pre-implementation research still open (per-resource gate)**: resolve C-1…C-12 — each is either a small YAML correction (preferred: C-1 x-db mapping, C-2 enums, C-3 field name + `source_read_all`, C-4 missing columns/phantom timestamps, C-5 default, C-11 min length, C-12 `sys_perm` naming) or a documented controller-boundary mapping. C-6 (quota semantics), C-8 (spamfilter config write path + POST semantics) and C-9 (pagination envelope — resolved: follow YAML/MailDomainController) need a maintainer decision recorded in the spec before their tasks start.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| `mail/spamfilter/config` PUT writes `server.config` (serialized INI on the `server` table) outside `BaseModel`/datalog (C-8) | The resource is not a row in a mail table — legacy `spamfilter_config_edit.php` itself performs `UPDATE server SET config = ?` with no datalog entry; server daemons read `server.config` directly | Forcing it through a `Server` BaseModel would emit a datalog entry legacy never produces (risking double-processing) and would still require INI parse/serialize logic; a dedicated `ServerIniConfigService` reproduces legacy behavior exactly and is the documented exception |
| Composite writes (mailbox → `spamfilter_users`; filter → `mail_user.custom_mailfilter`) emit ≥2 datalog entries per API call | Exact legacy parity — ISPConfig's own edit actions perform `datalogInsert/Update` on the companion tables | Skipping the companion writes silently breaks spam policy inheritance and sieve generation on the mail server |
