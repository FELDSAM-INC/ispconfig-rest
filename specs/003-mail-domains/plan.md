# Implementation Plan: Mail Domains

**Branch**: `003-mail-domains` (virtual — code lives on `main`, commit `8a4d502` "mail/domains") | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/003-mail-domains/spec.md`

**Note**: Brownfield migration — this plan documents what was actually built (and verified from the code), not a proposal.

## Summary

CRUD REST endpoints for ISPConfig mail domains (`mail_domain` table) under `/api/v1/mail/domains`: filtered/sorted/paginated listing, show, create (201), update (200, partial), delete (204). All writes flow through `App\Models\BaseModel` → `App\Services\DatalogService` into `sys_datalog` (`i`/`u`/`d`, `{new, old}` payloads matching legacy `datalogSave()`), so ISPConfig daemons apply changes asynchronously. Validation mirrors the legacy `mail_domain.tform.php` field rules (domain format/uniqueness, DKIM key parseability via OpenSSL, selector regex, permission presets); legacy *side effects* (DKIM DNS records, spamfilter policy, delete cascades, quota checks) were **not** ported — recorded as gaps in tasks.md.

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3) — *caveat: `MailDomainController::index()` uses `str_starts_with()`, a PHP 8.0+ function, so the effective floor for this feature is PHP 8.0*
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; ext-openssl (`openssl_pkey_get_private()` for DKIM validation); dev: phpunit ^9.5, mockery, fakerphp
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`)
**Testing**: PHPUnit (`vendor/bin/phpunit`) — no tests exist for this feature (`tests/` contains only `ClientApiTest.php`, `ExampleTest.php`, `TestCase.php`); the original build did not request them
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: N/A (simple CRUD; list capped at 100 rows/page)
**Constraints**: async write semantics via `sys_datalog` (status codes 201 create / 200 update / 204 delete — implemented as such); behavioral parity with `source_code/interface/web/mail/` (partial — see Legacy Research)
**Scale/Scope**: 5 endpoints, 1 entity (`MailDomain`), 1 controller, 1 model; no new service or cast (reuses `DatalogService`, `YesNoBoolean`)

## Constitution Check

*Verified against the actual code on `main` (constitution v1.0.1).*

- [x] **Spec-first (I)** — **PARTIAL**: `api/modules/mail/domains.yaml` and `api/components/schemas/MailDomain.yaml` existed before the PHP (commit `8a4d502` only trimmed 15 lines from the schema) and are registered in `api/modules/mail/_index.yaml`. Paths, methods, and success codes match. **Deviations**: controller implements `per_page`/`page`/`-sort` instead of the referenced shared `limit`/`offset`/`order` parameters; the YAML's operator-filter syntax (`field[op]=value`) is unimplemented; declared 403/409 responses are never produced; response bodies use `domain_id` + booleans where the schema declares `id` + `y`/`n` enums; `dkim_public`/`server_name` response fields never populated; pagination object returns 4 of 11 fields `Pagination.yaml` requires.
- [x] **Datalog-only writes (II)** — **PASS**: `App\Models\MailDomain` extends `BaseModel`; `save()`/`delete()` route through `DatalogService::log()` into `sys_datalog` with `{new, old}` payloads (diff-only for updates, full record for insert/delete), matching legacy `db_mysql.inc.php::datalogSave()`. No direct table writes anywhere in the feature. *Caveat*: `BaseModel::normalizeBooleanForDatalog()` emits uppercase `'Y'`/`'N'` in payloads whereas legacy ISPConfig uses lowercase `'y'`/`'n'` — a parity risk for case-sensitive server-plugin comparisons (project-wide, not specific to this feature).
- [x] **Legacy parity (III)** — **PARTIAL**: `source_code/interface/web/mail/` was clearly consulted — field rules, defaults, `dkim_selector` regex (verbatim), DKIM key check, and `auth_preset` permissions all mirror `mail_domain.tform.php`. Side effects from `mail_domain_edit.php`/`mail_domain_del.php` (DKIM DNS record + SOA serial bump, spamfilter_users policy, rename cascades, delete cascades, `limit_maildomain` quotas, mail-server-only `server_id` restriction, IDN/lowercase filters, `validate_isnot_mailtransport`) are **not** implemented. Documented in spec.md Parity section; gaps tracked in tasks.md.
- [x] **Route discipline (IV)** — **PASS**: five routes at `routes/web.php:104-108` inside the versioned `api.auth` group; static `mail/domains` registered before `mail/domains/{id}`; no shadowing with neighboring `dns/*` or `monitor/*` routes. Controller uses the standard `index/show/store/update/destroy` set.
- [x] **HTTP contract (V)** — **PARTIAL**: write codes **fully compliant** — 201 create (`Response::HTTP_CREATED`), 200 update, 204 delete (`Response::HTTP_NO_CONTENT`); no 202 anywhere (does not copy the client-era deviation). 401 via middleware, 404 for missing rows, 422 for validation, 500 with `{message, error}` + rollback + `Log::error`. **Deviations**: list envelope is `{data, pagination}` not `{items, total, limit, offset}` (follows the mail YAML and the de-facto DNS-era convention — `DnsSoa`/`DnsRecord`/`DnsTemplate`/`DnsSlave` controllers do the same); 404 body is `{message}` without `error`; 422 body is `{message, errors: {bag}}` via `Validator::make()` instead of Lumen's `$this->validate()`. Field normalization correctly uses `App\Casts\YesNoBoolean` (no ad-hoc `y`/`n` handling in the controller).
- [x] **No schema changes** — **PASS**: no migrations; `database/` untouched by commit `8a4d502`.

## Project Structure

### Documentation (this feature)

```text
specs/003-mail-domains/
├── spec.md              # Reverse-engineered feature spec (this migration)
├── plan.md              # This file
└── tasks.md             # Completed-task record + Gaps section
```

*(No research.md/data-model.md/contracts/ — the OpenAPI files under `api/` are the contract; legacy research is summarized below.)*

### Source Code (repository root)

```text
api/
├── modules/mail/
│   ├── _index.yaml                       # references domains.yaml (pre-existing)
│   └── domains.yaml                      # 5 endpoint definitions (pre-existing)
└── components/
    ├── schemas/MailDomain.yaml           # entity schema (pre-existing; 15 lines removed in 8a4d502)
    ├── schemas/Pagination.yaml           # list envelope (reused)
    ├── parameters/{limit,offset,sort,order}.yaml   # referenced by the YAML (reused)
    └── responses/{BadRequest,Unauthorized,Forbidden,NotFound,Conflict,UnprocessableEntity,InternalServerError}.yaml

app/
├── Http/Controllers/Api/V1/MailDomainController.php  # index/show/store/update/destroy (new, 218 lines)
├── Models/MailDomain.php                              # extends BaseModel (new, 219 lines)
├── Models/BaseModel.php                               # datalog save/delete (reused)
├── Services/DatalogService.php                        # sys_datalog writer (reused)
└── Casts/YesNoBoolean.php                             # active/dkim/local_delivery casts (reused)

routes/web.php                             # +7 lines (104–108), Mail Domain block between dns/records and monitor/data-logs
```

**Structure Decision**: single-resource feature; the routes slot after the DNS blocks and before the Monitor block inside the `api.auth` group. Static `mail/domains` precedes `mail/domains/{id}` — ordering rule satisfied. No service class was added (no reusable business logic was ported — notably, the DKIM DNS side effect that *would* have justified reusing `DnsSerialService` was not implemented).

## Legacy Research (Phase 0 focus)

From `source_code/interface/web/mail/form/mail_domain.tform.php`:

- **Table/PK**: `mail_domain` / `domain_id`; history enabled (`db_history = yes` → datalog).
- **Fields & defaults**: `server_id` (SELECT limited to `mail_server = 1 AND mirror_server_id = 0` servers), `domain` (default ''), `dkim` (CHECKBOX, default `n`, values n/y), `dkim_private`/`dkim_public` (TEXTAREA, default ''), `dkim_selector` (default `default`, maxlength 126 — though the DB column is `varchar(63)`), `relay_host`/`relay_user`/`relay_pass` (plain VARCHARs, no cross-field requirement), `active` (default `y`), `local_delivery` (default `y`).
- **Validators**: `domain` → NOTEMPTY + ISDOMAIN + custom `validate_mail_transport::validate_isnot_mailtransport`; `dkim_private` → `validate_dkim::check_private_key` (openssl parse; only enforced when `dkim = y`); `dkim_selector` → REGEX `/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/`.
- **Save filters on `domain`**: `IDNTOASCII` (punycode) + `TOLOWER`.
- **auth_preset**: `perm_user = riud`, `perm_group = riud`, `perm_other = ''`.

From `mail_domain_edit.php` (side effects the controller does **not** mirror):

- `onShowNew`: client + reseller `limit_maildomain` quota checks; `onSubmit` re-checks the quota and validates the client may use the chosen server.
- `onSubmit`: extracts `dkim_public` from `dkim_private` via `openssl_pkey_get_details()` when not submitted.
- `onAfterInsert`: upserts `spamfilter_users` for `@domain` with the posted `policy` id (datalog); if `active = y && dkim = y`, finds the enclosing `dns_soa` zone (`find_soa_domain`), replaces the `v=DKIM1` TXT record in `dns_rr`, and bumps the SOA serial (`update_dns`, lines ~745–769) — all via datalog.
- `onBeforeUpdate`: rejects `server_id` changes outright; non-admins may not rename a domain without `u` permission.
- `onAfterUpdate`: on rename, cascades to `spamfilter_users`/`spamfilter_wblist`, `mail_forwarding`, `mail_mailinglist`, `mail_get` (datalog updates), refreshes DKIM DNS records and DMARC `dns_rr` entries with SOA serial bumps.

From `mail_domain_del.php`: deleting a domain datalog-deletes all dependent `mail_forwarding`, `mail_get`, `mail_user`, `spamfilter_users` (+ their `spamfilter_wblist` rows), and `mail_mailinglist` records.

**How the controller mirrors it**: field-level validation and defaults — yes (regexes, defaults, permission presets, DKIM key parse check; relay chain is intentionally stricter per the YAML). Filters, quota/permission checks, and every cross-table side effect — no. The API is therefore a *field-faithful but side-effect-free* port; consumers relying on legacy DKIM-DNS or cascade behavior will observe differences (see tasks.md Gaps).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| List envelope `{data, pagination}` instead of constitution's `{items,total,limit,offset}` | Mirrors `api/modules/mail/domains.yaml` (the authored contract for this module) and the de-facto convention of every DNS/monitor controller | Changing shape unilaterally would break Principle I (spec is source of truth); reconciliation of constitution vs. mail/DNS YAMLs is deferred (gap) |
| `Validator::make()` + `{message, errors}` 422 body instead of `$this->validate()` + `{message, error}` | Built before the constitution was ratified; behavior shipped | Not justified — recorded as an unchecked gap, not an accepted deviation |
