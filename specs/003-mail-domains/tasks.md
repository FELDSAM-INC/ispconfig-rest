---

description: "Task list for Mail Domains (brownfield migration — records what was actually built in commit 8a4d502)"
---

# Tasks: Mail Domains

**Input**: Design documents from `/specs/003-mail-domains/`
**Prerequisites**: plan.md, spec.md

**Tests**: OPTIONAL per constitution — none were requested for the original build and **none exist** (see Gaps).

**Organization**: Tasks grouped by user story. All `[x]` items reflect code verifiably present on `main` (commit `8a4d502` "mail/domains"); the Gaps section lists genuine unfinished/deviating items as `[ ]`.

## Format: `[ID] [P?] [Story] Description`

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/mail/domains.yaml` (registered in `api/modules/mail/_index.yaml`) |
| OpenAPI schema | `api/components/schemas/MailDomain.yaml` |
| Model | `app/Models/MailDomain.php` — extends `App\Models\BaseModel` |
| Controller | `app/Http/Controllers/Api/V1/MailDomainController.php` |
| Cast (reused) | `app/Casts/YesNoBoolean.php` |
| Service (reused) | `app/Services/DatalogService.php` (via `BaseModel`) |
| Routes | `routes/web.php` lines 104–108, inside the `api.auth` group |
| Tests | `tests/MailDomainApiTest.php` — **does not exist** |

---

## Phase 1: Setup

**Purpose**: Contract and legacy research in place before PHP

- [x] T001 Endpoint spec exists in `api/modules/mail/domains.yaml` (5 operations, success codes 200/201/200/200/204) and is registered under `domains:` in `api/modules/mail/_index.yaml` — pre-existed the implementation (spec-first)
- [x] T002 [P] Shared schema `api/components/schemas/MailDomain.yaml` exists (with `x-db-table: mail_domain`, `x-db-field: domain_id`); commit `8a4d502` trimmed 15 lines from it; reuses `api/components/schemas/Pagination.yaml`, `api/components/parameters/{limit,offset,sort,order}.yaml`, `api/components/responses/*`
- [x] T003 [P] Legacy behavior extracted from `source_code/interface/web/mail/form/mail_domain.tform.php`, `mail_domain_edit.php`, `mail_domain_del.php`, `source_code/interface/lib/classes/validate_dkim.inc.php` — captured in spec.md "ISPConfig Parity & Datalog Impact" and plan.md "Legacy Research" (field rules ported; side effects knowingly not — see Gaps)

---

## Phase 2: Foundational (Blocking Prerequisites)

- [x] T004 Model `app/Models/MailDomain.php` created extending `BaseModel`: `$table = 'mail_domain'`, `$primaryKey = 'domain_id'`, `$fillable` (12 columns + sys fields; note `dkim_public` deliberately absent), `YesNoBoolean` casts for `active`/`dkim`/`local_delivery`, integer casts, `$hidden = ['relay_pass']`, defaults (`active=y`, `dkim=n`, `local_delivery=y`, `dkim_selector='default'`, `sys_perm_user/group='riud'`, `sys_perm_other=''`), static `$rules` + `getValidationRules($id)` (update relaxation + scoped unique), `validateDkimPrivateKey()` (openssl), relations `server()`/`group()`, scopes `active`/`withLocalDelivery`/`forServer`, `boot()` fallback `sys_userid = auth()->id() ?? 1`
- [x] T005 Datalog behavior confirmed for the entity: `BaseModel::save()`/`delete()` produce `sys_datalog` rows with `dbtable=mail_domain`, `dbidx=domain_id:<pk>`, actions `i`/`u`/`d`, serialized `{new, old}` payloads (diff-only on update, skip when diff empty) via `app/Services/DatalogService.php` — format matches legacy `db_mysql.inc.php::datalogSave()`

**Checkpoint**: Foundation ready

---

## Phase 3: User Story 1 - Provision a mail domain (Priority: P1) 🎯 MVP

**Goal**: `POST /api/v1/mail/domains` creates a domain via datalog

**Independent Test**: POST with `X-API-Key`; expect 201 + created record (no `relay_pass`) + `sys_datalog` `i` row

- [x] T006 [US1] `store()` in `app/Http/Controllers/Api/V1/MailDomainController.php`: `Validator::make()` against `MailDomain::getValidationRules()`; extra DKIM gate (`dkim === 'y'` → `validateDkimPrivateKey()`); `DB::beginTransaction()`/`commit`/`rollBack` + `Log::error`; returns **201** (`Response::HTTP_CREATED`) with the model, 422 `{message, errors}` on validation failure, 500 `{message, error}` on exception
- [x] T007 [US1] Route `POST mail/domains` registered in `routes/web.php:105` inside the `api.auth` group

---

## Phase 4: User Story 2 - List and inspect mail domains (Priority: P2)

**Goal**: filtered/sorted/paginated list + show by id

**Independent Test**: GET list with `domain`/`active`/`local_delivery`/`dkim`/`sort`/`per_page` params; GET one by id; 404 for missing id

- [x] T008 [US2] `index()` in `app/Http/Controllers/Api/V1/MailDomainController.php`: filters `domain` (`*`→`%` LIKE), `active`, `local_delivery`, `dkim`; `sort` default `domain` with `-` prefix for desc; `paginate(min(per_page, 100))`; returns `{data, pagination:{total, per_page, current_page, last_page}}` (matches the mail YAML envelope; see Gaps for parameter-name mismatches)
- [x] T009 [US2] `show($id)`: `find()` + 404 `{"message": "Mail domain not found"}` when absent; 200 with the record otherwise (`relay_pass` hidden)
- [x] T010 [US2] Routes `GET mail/domains` (`routes/web.php:104`) and `GET mail/domains/{id}` (`:106`) — static route precedes `{id}` route, no shadowing

---

## Phase 5: User Story 3 - Update a mail domain (Priority: P3)

**Goal**: partial update via PUT, datalog `u` diff

**Independent Test**: PUT a single field; expect 200 + diff-only datalog entry

- [x] T011 [US3] `update()`: 404 guard; `getValidationRules($id)` (all `required`→`sometimes`, unique scoped to exclude `domain_id`); conditional DKIM key re-validation (only when both `dkim=y` and `dkim_private` present); `fill()` + `save()` in transaction; returns **200** with the updated model
- [x] T012 [US3] Route `PUT mail/domains/{id}` registered in `routes/web.php:107` (`PutPatchInputMiddleware` applies group-wide for form-encoded bodies)

---

## Phase 6: User Story 4 - Delete a mail domain (Priority: P4)

**Goal**: delete via datalog `d`

**Independent Test**: DELETE existing id → 204 empty body + datalog `d` row with full old record; missing id → 404

- [x] T013 [US4] `destroy()`: 404 guard; `delete()` in transaction; returns **204** (`Response::HTTP_NO_CONTENT`); 500 `{message, error}` + rollback + `Log::error` on failure
- [x] T014 [US4] Route `DELETE mail/domains/{id}` registered in `routes/web.php:108`

---

## Phase 7: Polish & Cross-Cutting

- [x] T015 Route ordering re-verified across the whole `api.auth` group — the Mail Domain block (`routes/web.php:104-108`) does not shadow or get shadowed by neighboring `dns/*` / `monitor/*` routes
- [x] T016 Write status codes verified against the OpenAPI spec and constitution v1.0.1: 201 create / 200 update / 204 delete — this controller does **not** repeat the client-era 202 deviation
- [x] T017 No ad-hoc `y`/`n` handling in the controller — flag normalization delegated to `app/Casts/YesNoBoolean.php`; `relay_pass` excluded from all responses via model `$hidden`

---

## Dependencies & Execution Order

- Setup (T001–T003) → Foundational (T004–T005) → stories in priority order (US1 create, US2 read, US3 update, US4 delete) → Polish. All complete. `routes/web.php` edits were sequential (single shared file).

---

## Gaps

*Unchecked items = verified missing or deviating as of `main` (commit `8a4d502`). These are honest findings, not planned work; each would need its own decision (fix code, fix YAML, or accept and document).*

### Tests

- [ ] G01 No feature tests: `tests/MailDomainApiTest.php` does not exist (`tests/` holds only `ClientApiTest.php`, `ExampleTest.php`, `TestCase.php`). Follow the `tests/ClientApiTest.php` pattern if tests are commissioned.

### Legacy behaviors not mirrored (Principle III)

- [ ] G02 `dkim_public` never generated: legacy `mail_domain_edit.php::onSubmit()` derives it from `dkim_private` via `openssl_pkey_get_details()`; the API neither accepts nor computes it (`dkim_public` not in `$fillable`), yet `MailDomain.yaml` documents it as an auto-generated read-only response field
- [ ] G03 No DKIM DNS side effects: legacy creates/refreshes the `v=DKIM1` TXT record in `dns_rr` and bumps the `dns_soa` serial when `active=y && dkim=y` (insert and update, incl. DMARC record rewrite on rename); `DnsSerialService` exists in this codebase and could be reused
- [ ] G04 No spamfilter policy handling: legacy upserts `spamfilter_users` for `@domain` (posted `policy` field); the API has no `policy` field at all
- [ ] G05 No delete cascade: legacy `mail_domain_del.php` datalog-deletes dependent `mail_forwarding`, `mail_get`, `mail_user`, `spamfilter_users` (+ `spamfilter_wblist`), `mail_mailinglist` rows — the API deletes only `mail_domain`, **although `api/modules/mail/domains.yaml` explicitly promises the cascade** ("Any associated mailboxes, aliases, and forwarders will also be deleted")
- [ ] G06 No client/reseller `limit_maildomain` quota checks, despite the POST description in the YAML claiming "User must be within their allowed limits"
- [ ] G07 `server_id` under-validated: rule is `exists:server,server_id`; legacy restricts to `mail_server = 1 AND mirror_server_id = 0` servers and forbids changing `server_id` on update — the API allows any server and free server changes
- [ ] G08 Domain rename unrestricted and non-cascading: YAML says "Domain name cannot be changed after creation"; legacy allows admin-only renames with cascades to `mail_user`/`mail_forwarding`/`mail_get`/`mail_mailinglist`/`spamfilter_*`; the API allows any rename with no cascade
- [ ] G09 No IDN punycode (`IDNTOASCII`) or lowercase (`TOLOWER`) normalization of `domain` on save — `MailDomain.yaml` promises both in its description
- [ ] G10 Missing `validate_isnot_mailtransport` check (legacy rejects domains colliding with `mail_transport` entries)

### Contract mismatches (Principle I / V)

- [ ] G11 List parameters: YAML references shared `limit`/`offset`/`order` params (and its description documents `field[op]=value` operator filtering), but the controller implements `per_page`+`page`+`-sort` only; `limit`, `offset`, `order`, and operator filters are silently ignored
- [ ] G12 Pagination object incomplete: controller returns `total/per_page/current_page/last_page` — 4 of the 11 fields `api/components/schemas/Pagination.yaml` marks `required` (missing `from`, `to`, `path`, `*_page_url`)
- [ ] G13 Response shape vs schema: responses expose `domain_id` (schema declares `id` with `x-db-field` mapping nothing implements) and serialize `active`/`dkim`/`local_delivery` as JSON **booleans** while the schema declares `enum: [y, n]` strings; `server_name` response field never populated
- [ ] G14 `sys_userid`/`sys_groupid` are `required` on create although `MailDomain.yaml` marks them `readOnly` (a schema-compliant POST fails 422); the `boot()` fallback for `sys_userid` is unreachable; schema also misnames the column `sys_perm` (DB: `sys_perm_user`)
- [ ] G15 `dkim` is `required` on create although the schema gives `default: n` and omits it from `required`
- [ ] G16 Declared-but-dead status codes: 403 and 409 appear in the YAML for these endpoints but no code path returns them (duplicates → 422; no per-record permission model)
- [ ] G17 Error body shape: 404 returns `{message}` without `error`; 422 returns `{message, errors: {bag}}` via `Validator::make()` instead of the constitution's `{message, error}` / Lumen `$this->validate()` pattern
- [ ] G18 List envelope `{data, pagination}` conflicts with constitution v1.0.1's `{items,total,limit,offset}` (shared with all DNS-era controllers) — needs a project-level decision: amend the constitution or migrate the YAMLs + controllers

### Other findings

- [ ] G19 Datalog boolean casing: `BaseModel::normalizeBooleanForDatalog()` writes `'Y'`/`'N'` (uppercase) into datalog payloads; legacy ISPConfig uses lowercase `'y'`/`'n'` and server plugins compare case-sensitively — potential silent provisioning mismatch (cross-cutting: affects every `YesNoBoolean` model, surfaced here because `active`/`dkim`/`local_delivery` gate legacy side effects)
- [ ] G20 `str_starts_with()` in `MailDomainController::index()` requires PHP ≥ 8.0 while `composer.json` permits ^7.3 — fatal on PHP 7.x (add a polyfill/`Str::startsWith`, or raise the platform requirement)
- [ ] G21 `dkim_selector` max length 126 (mirrors the legacy form) exceeds the DB column `varchar(63)` — an upstream ISPConfig inconsistency inherited verbatim; values 64–126 chars would be truncated/rejected at the DB layer
