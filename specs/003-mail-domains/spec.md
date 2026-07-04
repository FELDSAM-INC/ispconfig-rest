# Feature Specification: Mail Domains

**Feature Branch**: `003-mail-domains` — *no such branch exists; the feature was built directly on `main` (commit `8a4d502` "mail/domains", the newest commit in the repo). This document is a brownfield migration of that already-shipped code into spec-kit format.*
**Created**: 2026-07-04
**Status**: Migrated
**Module**: mail
**Input**: Reverse-engineered from `app/Http/Controllers/Api/V1/MailDomainController.php`, `app/Models/MailDomain.php`, `routes/web.php` (lines 104–108), `api/modules/mail/domains.yaml`, `api/components/schemas/MailDomain.yaml`, and the legacy ISPConfig source under `source_code/interface/web/mail/`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Provision a mail domain (Priority: P1)

An API consumer (hosting automation, control panel, migration script) creates a new mail domain by POSTing the domain name, target server, and optional DKIM/relay configuration. The API validates the input, records the row, and queues the change in `sys_datalog` so ISPConfig's server daemons provision the domain asynchronously.

**Why this priority**: A mail domain is the root entity of the entire mail module — every mailbox, alias, and forward requires one. Provisioning is the reason the API exists.

**Independent Test**: `POST /api/v1/mail/domains` with `X-API-Key` and a valid body; assert HTTP 201, response echoes the created record (without `relay_pass`), a new `mail_domain` row exists, and a `sys_datalog` row with `dbtable=mail_domain`, `action=i`, `dbidx=domain_id:<new id>` was written.

**Acceptance Scenarios**:

1. **Given** a valid `server_id`, unique `domain`, `active=y`, `local_delivery=y`, `dkim=n`, and valid `sys_userid`/`sys_groupid`, **When** POSTing to `/api/v1/mail/domains`, **Then** the API returns 201 with the created record and logs a datalog `i` entry.
2. **Given** `dkim=y` with a `dkim_private` value that `openssl_pkey_get_private()` cannot parse, **When** POSTing, **Then** the API returns 422 with `{"message": "Validation failed", "errors": {"dkim_private": [...]}}` and writes nothing.
3. **Given** a `domain` that already exists in `mail_domain`, **When** POSTing, **Then** the API returns 422 (unique-rule violation). *Note: the OpenAPI file also declares 409 Conflict for POST, but the implementation never returns 409 — duplicates surface as 422.*
4. **Given** `relay_host` is set but `relay_user` is omitted, **When** POSTing, **Then** the API returns 422 (`relay_user` is `required_with:relay_host`, `relay_pass` is `required_with:relay_user`).

---

### User Story 2 - List and inspect mail domains (Priority: P2)

An API consumer lists mail domains — filtered by domain name pattern, active flag, local-delivery flag, or DKIM flag, and sorted — or fetches a single domain by ID, e.g. to render a dashboard or check provisioning inputs before creating mailboxes.

**Why this priority**: Read access is required by virtually every integration, but it delivers value only once domains exist.

**Independent Test**: Seed a `mail_domain` row, call `GET /api/v1/mail/domains?domain=exa*&active=y&sort=-domain` and `GET /api/v1/mail/domains/{id}`; verify 200 responses, filter/sort behavior, and that `relay_pass` is absent.

**Acceptance Scenarios**:

1. **Given** existing mail domains, **When** GETting `/api/v1/mail/domains`, **Then** the API returns 200 with `{"data": [...], "pagination": {"total", "per_page", "current_page", "last_page"}}` (page size defaults to 20, capped at 100 via `per_page`).
2. **Given** a `domain` filter containing `*`, **When** listing, **Then** `*` is translated to SQL `%` and matched with `LIKE`.
3. **Given** `sort=-domain`, **When** listing, **Then** results are ordered by `domain` descending (leading `-` = desc; the shared `order` query parameter declared in the YAML is ignored by the implementation).
4. **Given** a nonexistent ID, **When** GETting `/api/v1/mail/domains/{id}`, **Then** the API returns 404 with `{"message": "Mail domain not found"}`.

---

### User Story 3 - Update a mail domain (Priority: P3)

An API consumer toggles a domain's flags (`active`, `local_delivery`), rotates DKIM keys, or changes relay credentials with a PUT. All fields are optional on update (every `required` rule is relaxed to `sometimes`), the `domain` unique check excludes the current record, and only changed fields are written to the datalog diff.

**Why this priority**: Day-2 operations; depends on P1.

**Independent Test**: PUT `{"active": "n"}` to an existing domain; assert 200, response reflects the change, and the `sys_datalog` `u` entry contains only the diffed field in `new`/`old`.

**Acceptance Scenarios**:

1. **Given** an existing domain, **When** PUTting a partial body, **Then** the API returns 200 with the updated record and logs a datalog `u` entry containing only changed attributes.
2. **Given** a PUT that changes no attribute values, **When** saved, **Then** no datalog entry is written (BaseModel skips empty diffs) and the API still returns 200.
3. **Given** `dkim=y` and an invalid `dkim_private` in the same request, **When** PUTting, **Then** the API returns 422.
4. **Given** a nonexistent ID, **When** PUTting, **Then** the API returns 404.

> **Deviation note**: the OpenAPI description states "Domain name cannot be changed after creation", but the implementation permits renaming `domain` (unique rule scoped to exclude the current `domain_id`). Legacy ISPConfig allows renames only for admins and cascades the rename to mail users/forwards/spamfilters — the API does neither the restriction nor the cascade.

---

### User Story 4 - Delete a mail domain (Priority: P4)

An API consumer decommissions a mail domain with DELETE; the row is removed and a datalog `d` entry (carrying the full old record) is queued for the server daemons.

**Why this priority**: Least frequent operation; destructive.

**Independent Test**: DELETE an existing domain; assert 204 with empty body, row gone, and datalog `d` entry with the full old attributes.

**Acceptance Scenarios**:

1. **Given** an existing domain, **When** DELETEing, **Then** the API returns 204 No Content and logs a datalog `d` entry.
2. **Given** a nonexistent ID, **When** DELETEing, **Then** the API returns 404.

> **Deviation note**: the OpenAPI description claims "Any associated mailboxes, aliases, and forwarders will also be deleted". The implementation deletes **only** the `mail_domain` row. Legacy `mail_domain_del.php` cascades deletion to `mail_forwarding`, `mail_get`, `mail_user`, `spamfilter_users` (+ `spamfilter_wblist`), and `mail_mailinglist` — none of that is implemented here.

---

### Edge Cases

- **Missing/invalid `X-API-Key`** → 401 from `ApiAuthMiddleware` (all five routes are inside the `api.auth` group).
- **Nonexistent resource ID** on show/update/delete → 404 with `{"message": "..."}` only — the `error` key required by the project error shape is absent.
- **Validation failure** → 422 with `{"message": "Validation failed", "errors": {field: [msgs]}}` — a Laravel error *bag* under `errors`, not the constitution's `{message, error}` string shape. Validation uses `Validator::make()` rather than Lumen's `$this->validate()`.
- **`y`/`n` flag asymmetry**: input for `active`/`dkim`/`local_delivery` must be the strings `y`/`n` (rule `in:y,n`), but responses serialize these fields as JSON booleans via the `YesNoBoolean` cast — the OpenAPI schema declares `enum: [y, n]` strings for both directions, so responses deviate from the contract.
- **Primary key naming**: responses expose `domain_id` (raw column); the schema declares `id` (with `x-db-field: domain_id`) — nothing performs that mapping.
- **`sys_userid`/`sys_groupid` on create**: the schema marks them `readOnly`, but validation makes both `required` (+`exists:sys_user`/`exists:sys_group`) — a schema-compliant POST without them fails with 422. `sys_userid` has a model-boot fallback (`auth()->id() ?? 1`) that is unreachable because validation rejects first.
- **`dkim` on create**: schema gives it `default: n` and does not list it as required; validation makes it `required`, so omitting it → 422.
- **Empty `dkim_private` with `dkim=y`**: caught by `required_if:dkim,y`; `MailDomain::validateDkimPrivateKey()` itself returns `true` for empty strings.
- **Unexpected exception during a write** → transaction rollback, `Log::error(...)` with context, 500 with `{message, error}`.
- **403 and 409** are declared in the YAML but no code path ever returns them (no per-record `sys_perm_*` enforcement, no conflict detection).
- **Datalog casing**: `BaseModel` normalizes boolean-cast fields to uppercase `'Y'`/`'N'` in datalog payloads, while legacy ISPConfig uses lowercase `'y'`/`'n'`; server-side plugins doing case-sensitive `== 'y'` comparisons may not match.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/mail/domains.yaml` — existed before the implementation (registered in `api/modules/mail/_index.yaml` under `domains:`); implementation followed it with the deviations noted below.
- **Shared schemas**: `api/components/schemas/MailDomain.yaml` (existing; commit `8a4d502` trimmed 15 lines from it), `api/components/schemas/Pagination.yaml`, shared parameters `api/components/parameters/{limit,offset,sort,order}.yaml`, shared responses `api/components/responses/{BadRequest,Unauthorized,Forbidden,NotFound,Conflict,UnprocessableEntity,InternalServerError}.yaml`.
- **Endpoints** (success codes and error codes exactly as the YAML declares them):

| Method | Path | Purpose | Success code | Declared errors |
|--------|------|---------|--------------|-----------------|
| GET | `/api/v1/mail/domains` | List (filter: `domain` wildcard, `active`, `local_delivery`, `dkim`; paginated `data`/`pagination`) | 200 | 400, 401, 403, 500 |
| POST | `/api/v1/mail/domains` | Create (via datalog `i`) | 201 | 400, 401, 403, 409, 422, 500 |
| GET | `/api/v1/mail/domains/{id}` | Show | 200 | 401, 403, 404, 500 |
| PUT | `/api/v1/mail/domains/{id}` | Update (via datalog `u`, diff-only) | 200 | 400, 401, 403, 404, 409, 422, 500 |
| DELETE | `/api/v1/mail/domains/{id}` | Delete (via datalog `d`) | 204 | 400, 401, 403, 404, 500 |

**Known contract deviations in the implementation** (verified against code):

1. List response is `{data, pagination:{total, per_page, current_page, last_page}}` — matches the YAML's `data`+`pagination` envelope but supplies only 4 of the 11 fields `Pagination.yaml` marks `required`; it also does not use the constitution's `{items,total,limit,offset}` shape (the YAML itself predates that rule and follows the DNS-era `data`/`pagination` convention).
2. The YAML references shared `limit`/`offset`/`sort`/`order` parameters, while its own description documents `page`/`per_page` and `field[op]=value` operator filtering. The controller implements `per_page` + Laravel `page` and `-`-prefixed `sort`; `limit`, `offset`, `order`, and the operator-filter syntax are **not** implemented.
3. `403` and `409` are declared but never returned; duplicate domains produce 422.
4. Response field names/types: `domain_id` instead of `id`; booleans instead of `y`/`n` enums; `dkim_public` and `server_name` (declared read-only response fields) are never populated; schema names the first permission field `sys_perm` although the DB column is `sys_perm_user`.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/mail/form/mail_domain.tform.php` (form/validators/defaults), `source_code/interface/web/mail/mail_domain_edit.php` (limit checks, DKIM public-key extraction, spamfilter policy, DKIM DNS side effects, rename cascades), `source_code/interface/web/mail/mail_domain_del.php` (delete cascades), `source_code/interface/lib/classes/validate_dkim.inc.php` (`check_private_key`), `source_code/install/sql/ispconfig3.sql` (table definition).
- **Legacy behaviors mirrored**:
  - Field set and defaults: `dkim=n`, `dkim_selector='default'`, `active=y`, `local_delivery=y` (model `$attributes`); permission presets `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` exactly match the tform `auth_preset`.
  - `dkim_selector` validation regex `/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/` and maxlength 126 — copied verbatim from the tform (note: the DB column is `varchar(63)`; the 126 limit is a discrepancy ISPConfig itself ships with).
  - DKIM private-key validation via `openssl_pkey_get_private()` mirrors `validate_dkim::check_private_key` (legacy also checks key strength ranges; the API checks parseability only).
  - Domain non-empty + format validation (Lumen `required` + regex approximating legacy `ISDOMAIN`) and uniqueness.
- **Legacy behaviors NOT mirrored** (honest gaps — see tasks.md "Gaps"):
  - `IDNTOASCII` (punycode) and `TOLOWER` save-filters on `domain` — the schema *description* promises both; neither is implemented.
  - `validate_isnot_mailtransport` (domain must not collide with a `mail_transport` entry).
  - `dkim_public` extraction from `dkim_private` via `openssl_pkey_get_details()` (legacy `onSubmit`).
  - DKIM DNS automation: on insert/update with `active=y, dkim=y`, legacy finds the enclosing `dns_soa` zone, replaces the `v=DKIM1` TXT `dns_rr` record, and bumps the SOA serial (datalog writes to `dns_rr` + `dns_soa`); also rewrites DMARC records on rename.
  - Spamfilter policy: legacy accepts a `policy` field and datalog-inserts/updates `spamfilter_users` for `@domain`.
  - Client/reseller `limit_maildomain` quota checks and `server_id` restriction to `mail_server = 1 AND mirror_server_id = 0` servers (API accepts any existing `server_id` via `exists:server,server_id`).
  - `onBeforeUpdate` guards: legacy forbids changing `server_id` and restricts domain renames to admins (with cascading updates to `mail_user`, `mail_forwarding`, `mail_get`, `mail_mailinglist`, `spamfilter_users`/`wblist`); the API allows both freely with no cascade.
  - Delete cascades from `mail_domain_del.php` (see US4 note).
- **Tables written (via datalog only)**: `mail_domain` — actions `i` (full new record), `u` (diff-only `{new, old}`), `d` (full old record). Payload shape `serialize(['new' => ..., 'old' => ...])` matches legacy `db_mysql.inc.php::datalogSave()`. Legacy additionally writes `spamfilter_users`, `spamfilter_wblist`, `dns_rr`, `dns_soa`, `mail_user`, `mail_forwarding`, `mail_get`, `mail_mailinglist` for the side effects listed above — this API writes **none** of those.
- **System fields handling**: `sys_perm_user/group/other` default `riud`/`riud`/`''` via model `$attributes`; `sys_userid` fallback `auth()->id() ?? 1` in `MailDomain::boot()` (unreachable in practice — validation requires the field); `sys_userid`/`sys_groupid` must be supplied by the consumer and exist in `sys_user`/`sys_group`; `server_id` supplied by the consumer. Datalog rows carry `server_id` and `sys_userid` from the record.
- **Intentional deviations from legacy**: the relay credential chain (`relay_user` required with `relay_host`, `relay_pass` required with `relay_user`) is stricter than legacy (plain optional varchars) and is documented in the YAML — treated as intentional. All other divergences above are gaps, not agreed deviations.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST list mail domains with equality filters `active`, `local_delivery`, `dkim` (values `y`/`n`) and a `domain` filter where `*` is translated to SQL `%` for `LIKE` matching.
- **FR-002**: System MUST sort the list by any column via `sort` (default `domain`), using a leading `-` for descending order, and paginate via `per_page` (default 20, hard cap 100) returning `{data, pagination:{total, per_page, current_page, last_page}}`.
- **FR-003**: System MUST return a single mail domain by `domain_id`, and 404 with `{"message": "Mail domain not found"}` when absent (show, update, delete alike).
- **FR-004**: System MUST create mail domains returning 201 with the created record, and update them returning 200 with the updated record; on update every `required` rule is relaxed to `sometimes` (partial updates) and the `domain` unique rule excludes the current `domain_id`.
- **FR-005**: System MUST delete mail domains returning 204 No Content.
- **FR-006**: Every write MUST go through `BaseModel::save()`/`delete()` so a `sys_datalog` entry (`dbtable=mail_domain`, `dbidx=domain_id:<pk>`, action `i`/`u`/`d`, serialized `{new, old}` payload, record's `server_id` and `sys_userid`) is produced; updates with an empty diff produce no datalog entry. Success responses confirm the datalog entry, not the applied change (async).
- **FR-007**: System MUST validate `domain` as required, ≤255 chars, matching `/^[a-zA-Z0-9\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/`, and unique in `mail_domain.domain`.
- **FR-008**: System MUST require `dkim` ∈ {y, n}; when `dkim=y`, `dkim_private` is required and MUST parse via `openssl_pkey_get_private()` (create: always checked; update: checked only when both `dkim` and `dkim_private` are present in the request).
- **FR-009**: System MUST validate `dkim_selector` (nullable) against `/^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$/`, max 126 chars.
- **FR-010**: System MUST enforce the relay chain: `relay_user` required with `relay_host`; `relay_pass` required with `relay_user`; all ≤255 chars.
- **FR-011**: System MUST require `server_id` to reference an existing `server.server_id`, and (on create) `sys_userid`/`sys_groupid` to reference `sys_user.userid`/`sys_group.groupid`; `sys_perm_*` fields, when supplied, must match `/^[riud]*$/` (max 5 chars).
- **FR-012**: System MUST never emit `relay_pass` in any response (model `$hidden`).
- **FR-013**: System MUST default `active=y`, `local_delivery=y`, `dkim=n`, `dkim_selector='default'`, `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` for created records where not supplied (subject to the required-field rules above).
- **FR-014**: System MUST wrap create/update/delete in a DB transaction with rollback and `Log::error` context on failure, returning 500 `{message, error}`.
- **FR-015**: All endpoints MUST require a valid `X-API-Key` (`api.auth` middleware), returning 401 otherwise.
- **FR-016**: System MUST handle `y`/`n` flags via the `App\Casts\YesNoBoolean` cast (accepts y/n/true/false on input, stores `Y`/`N`, serializes booleans) — no ad-hoc conversion in the controller. *(As-built: note the response-type deviation from the schema recorded in the API Contract section.)*

### Key Entities

- **MailDomain**: an email domain that can send/receive mail, with DKIM signing and outbound-relay configuration — table `mail_domain` (PK `domain_id`; columns per `ISPConfig-DB-Structure.txt` line 222 and `source_code/install/sql/ispconfig3.sql`: sys fields, `server_id`, `domain`, `dkim`, `dkim_selector`, `dkim_private`, `dkim_public`, `relay_host`, `relay_user`, `relay_pass`, `active`, `local_delivery`), schema `api/components/schemas/MailDomain.yaml`, model `app/Models/MailDomain.php` (extends `BaseModel`; relations `server()` → `App\Models\Server`, `group()` → `App\Models\Group`; scopes `active`, `withLocalDelivery`, `forServer`). Note: `dkim_public` exists in the table and schema but is not in `$fillable` and is never written or returned by the API.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All five endpoints in `api/modules/mail/domains.yaml` respond with their declared success codes (200 list/show/update, 201 create, 204 delete) for valid requests.
- **SC-002**: Every successful create/update/delete produces exactly one well-formed `sys_datalog` row (`dbtable=mail_domain`, correct `dbidx`, action `i`/`u`/`d`, unserializable `{new, old}` payload) that a stock ISPConfig server processes without error.
- **SC-003**: Swagger UI (`/api/documentation`) renders the "Mail Domains" tag with all 5 operations and "Try it out" succeeds against a dev install.
- **SC-004**: The documented validation cases (empty domain, malformed domain, duplicate domain, invalid DKIM key with `dkim=y`, `relay_host` without `relay_user`, bad `dkim_selector`) each return 422 and match legacy ISPConfig's accept/reject decision.
- **SC-005**: `relay_pass` appears in zero response bodies across all endpoints.
- **SC-006**: List filtering/sorting returns correct result sets for each of the four filters and both sort directions; `per_page` never exceeds 100.

## Assumptions

- **Module boundary**: Mail domains is the **only implemented** resource of the mail module. The remaining mail resources (users, forwards, alias domains, access rules, content filters, transports, relay domains/recipients, fetchmail, spamfilter config/policies/users/wblist, etc.) are fully specced in `api/modules/mail/*.yaml` but have no PHP implementation; they are out of scope here and covered by a separate draft spec.
- Authentication is solely the existing `X-API-Key` middleware (`api.auth`); no per-record ISPConfig `sys_perm_*`/group permission model is enforced by the API (all rows are visible to any valid key), and no client quota (`limit_maildomain`) is checked.
- A populated `dbispconfig` database (with `server`, `sys_user`, `sys_group` rows) is available; datalog entries are consumed by an external ISPConfig installation — this API never verifies application of changes.
- Legacy behavior was verified against the `source_code/` ISPConfig snapshot currently vendored in the repo (read-only, untracked).
- API consumers pass `sys_userid` and `sys_groupid` explicitly on create (the schema's `readOnly` marking notwithstanding) — this is how the code actually behaves.
- The `data`/`pagination` list envelope of the mail YAML is accepted as-is for this migrated feature, even though the constitution (v1.0.1) prescribes `{items,total,limit,offset}`; reconciling the two is future work, tracked as a gap.
