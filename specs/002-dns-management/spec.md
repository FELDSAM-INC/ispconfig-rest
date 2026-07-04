# Feature Specification: DNS Management (zones, records, slave zones, templates)

**Feature Branch**: `002-dns-management` — no such branch exists; the feature was built directly on `main` before spec-kit adoption (commits `bf42794` "DNS Soa and Records" through `6a7b923` "Implemented dns/templates"). This spec is a brownfield migration (reverse-engineered from the shipped code).
**Created**: 2026-07-04
**Status**: Migrated
**Module**: dns
**Input**: Reverse-engineered from `app/Http/Controllers/Api/V1/{DnsSoaController,DnsRecordController,DnsSlaveController,DnsTemplateController}.php`, `app/Models/{DnsSoa,DnsRecord,DnsSlave,DnsTemplate}.php`, `app/Services/{DnsSerialService,DnsRecordMetaService}.php`, `api/modules/dns/*.yaml`, `routes/web.php` (lines 76–101), and legacy `source_code/interface/web/dns/`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage authoritative DNS zones (SOA) (Priority: P1)

An API consumer (hosting automation, control panel) provisions and maintains authoritative DNS zones. It creates a zone (`POST /api/v1/dns/soa`) with origin, primary NS, mbox and SOA timers; lists/filters zones by `origin` (wildcard `*`) and `active`; fetches one by ID; updates zone settings; and deletes an empty zone. Every write is journaled to `sys_datalog` (action `i`/`u`/`d` on table `dns_soa`) for ISPConfig's server daemons to apply asynchronously. Zones are the parent aggregate — nothing else in the module is useful without them.

**Why this priority**: All record management (P2) requires an existing `dns_soa` row; zone CRUD alone is a viable MVP (an empty zone is already served by BIND with its SOA/NS data rendered from `dns_soa`).

**Independent Test**: `POST /api/v1/dns/soa` with a valid `X-API-Key` and a full payload (`server_id`, `origin`, `ns`, `mbox`, `serial`, timers, `active`, `sys_userid`, `sys_groupid`); verify 201 with the zone JSON, then verify the `sys_datalog` row (`dbtable=dns_soa`, `action=i`) via `GET /monitor/data-logs?dbtable=dns_soa`.

**Acceptance Scenarios**:

1. **Given** no zone `example.com` exists, **When** `POST /dns/soa` with a valid payload, **Then** 201 with the created zone (defaults applied: `active=y`, `sys_perm_user=riud`, `sys_perm_group=riud`, `sys_perm_other=""`) and one `sys_datalog` insert entry.
2. **Given** the same `origin` already exists in `dns_soa`, **When** `POST /dns/soa` again, **Then** 422 `{"message": "Validation failed", "errors": {...}}` (unique rule on `origin`).
3. **Given** an existing zone, **When** `GET /dns/soa?origin=exa*&active=y`, **Then** 200 with `{data: [...], pagination: {total, per_page, current_page, last_page}}` containing the zone (`*` is translated to SQL `%`).
4. **Given** an existing zone, **When** `PUT /dns/soa/{id}` with a partial body, **Then** 200 with the updated zone and a `u` datalog entry containing only the changed fields (`{"new": diff, "old": diff}`), and the zone serial is expected to be bumped (see Edge Cases — the shipped serial-bump hook is defective).
5. **Given** a zone with zero `dns_rr` records, **When** `DELETE /dns/soa/{id}`, **Then** 204 and a `d` datalog entry.
6. **Given** a nonexistent id, **When** `GET|PUT|DELETE /dns/soa/{id}`, **Then** 404 `{"message": "DNS zone not found"}`.

---

### User Story 2 - Manage DNS records with type-aware meta fields (Priority: P2)

A consumer manages resource records inside a zone (`/api/v1/dns/records`). For simple types (A, AAAA, CNAME, TXT, NS, PTR) it sends `zone`, `name`, `type`, `data`. For structured types it sends human-friendly **meta fields at the top level of the request** instead of hand-assembling the wire-format `data` string: MX (`priority`, `hostname`), SRV (`priority`, `weight`, `port`, `hostname`), TLSA (`cert_usage`, `selector`, `matching_type`, `hash`), SSHFP (`algorithm`, `hash_type`, `hash`), CAA (`caa_flag`, `caa_type`, `ca_issuer`/`additional`), HINFO (`cpu`, `os`), SPF (`allow_mx`, `allow_a`, `ipv4_address`, `ipv6_address`, `hostname`, `include`, `policy`), DMARC (`policy`, `pct`, `rua`, `ruf`, `sp`, `adkim`, `aspf`), NAPTR (`order`, `pref`, `naptr_flag`, `service`, `regexp`, `replacement`), DS (`key_tag`, `algorithm`, `digest_type`, `digest`). `DnsRecordMetaService::metaToData()` composes `aux` + `data` (quoting NAPTR/CAA/HINFO string parts, appending trailing dots to hostnames); every GET response appends a computed `meta` object decomposed from `aux`/`data` (`dataToMeta()`, including sniffing stored TXT records for `v=spf1`/`v=DMARC1`). Each record write also updates the record's `stamp`/`serial` and bumps the parent zone's SOA serial, mirroring legacy `dns_edit_base.php`.

**Why this priority**: This is the bulk of day-to-day DNS work and the most subtle logic in the module (aux-field usage, quoting, serial propagation — see commits `ae5f4a5`, `1aeb9e0`, `1b98a56`), but it is only exercisable once zones (P1) exist.

**Independent Test**: Create a zone, then `POST /dns/records` with `{zone, name, type: "MX", priority: 10, hostname: "mail.example.com", sys_userid, sys_groupid}`; verify 201 with `aux=10`, `data="mail.example.com."`, a `meta` object echoing `priority`/`hostname`, `server_id` inherited from the zone, and that `GET /dns/soa/{zone}` shows an incremented serial plus `dns_soa`/`dns_rr` datalog entries.

**Acceptance Scenarios**:

1. **Given** an existing zone, **When** `POST /dns/records` with type `A` and `data: "192.0.2.1"`, **Then** 201, record saved via datalog (`dns_rr`, action `i`), defaults `ttl=3600`, `active=y`, and the zone SOA serial is incremented (separate `dns_soa` `u` datalog entry).
2. **Given** a `zone` id that does not exist in `dns_soa`, **When** `POST /dns/records`, **Then** 400 `{"message": "Zone not found", "error": "The specified zone does not exist"}` (422 if the `exists:dns_soa,id` rule fires first).
3. **Given** type `MX` without `priority`/`hostname`, **When** `POST /dns/records`, **Then** 422 (type-specific rules from `DnsRecord::getValidationRules()`).
4. **Given** a NAPTR record request, **When** created, **Then** `aux` holds `order` and `data` is `"<preference> "flags" "service" "regexp" replacement."` with double quotes added around flags/service/regexp when absent (see Edge Cases for the `pref`/`preference` naming defect).
5. **Given** an existing record, **When** `PUT /dns/records/{id}` changes `data` or meta fields, **Then** 200; `stamp` is refreshed and the record `serial` is bumped (`DnsSerialService`), and the zone serial is bumped again.
6. **Given** an existing record, **When** `DELETE /dns/records/{id}`, **Then** 204, a `d` datalog entry for `dns_rr`, and the zone serial is bumped.
7. **Given** a stored TXT record whose data starts with `v=spf1`, **When** `GET /dns/records/{id}`, **Then** the response `meta` contains the parsed SPF fields (`allow_mx`, `ipv4_address`, `policy`, …).
8. **Given** records exist, **When** `GET /dns/records?zone=1&type=a&name=www*`, **Then** 200 filtered list (`type` is upper-cased; `*`→`%` on `name`; `data` filter is substring match).

---

### User Story 3 - Manage slave (secondary) zones (Priority: P3)

A consumer configures the panel's DNS servers as secondaries for zones mastered elsewhere: `POST /api/v1/dns/slaves` with `server_id`, `origin`, `ns` (the master's IP/host to transfer from) and optional `xfer` ACL; list/filter by `origin`/`active`/`server_id`; update; delete. Writes journal to `dns_slave` via datalog.

**Why this priority**: Independent of zones/records and less frequently used; delivers value for mixed-master setups (commit `7dcc2ff`).

**Independent Test**: `POST /dns/slaves` with a valid payload → 201; `GET /dns/slaves?server_id=1` shows it; `DELETE` → 204; each write visible in `sys_datalog` (`dbtable=dns_slave`).

**Acceptance Scenarios**:

1. **Given** a valid payload, **When** `POST /dns/slaves`, **Then** 201 with defaults `active=y`, `sys_perm_*` presets, and an `i` datalog entry.
2. **Given** a duplicate `origin`, **When** `POST /dns/slaves`, **Then** 422 (unique rule on `dns_slave.origin`; legacy-identical origin regex `/^[a-zA-Z0-9\.\-\/]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/` allowing trailing dot and `/` for reverse zones).
3. **Given** a nonexistent id, **When** `GET|PUT|DELETE /dns/slaves/{id}`, **Then** 404 `{"message": "DNS slave zone not found"}`.

---

### User Story 4 - Manage DNS zone templates (Priority: P4)

An operator maintains the templates used by ISPConfig's zone wizard: `POST /api/v1/dns/templates` with `name`, `fields` (comma-separated placeholder list validated against `DOMAIN, IP, IPV6, NS1, NS2, EMAIL, DKIM, DNSSEC`), `template` (the zone-file text with `[ZONE]`/`[A]`/… sections) and `visible`; list/filter by `name`/`visible`; update; delete. Writes journal to `dns_template` via datalog. The API stores templates only — it does **not** implement the wizard that expands a template into a zone (legacy `dns_wizard.php` has no REST counterpart).

**Why this priority**: Pure content management supporting a UI feature outside this API's scope; last implemented (commit `6a7b923`).

**Independent Test**: `POST /dns/templates` with `fields: "DOMAIN,IP,NS1,NS2,EMAIL"` → 201; the same request with `fields: "DOMAIN,BOGUS"` → 422 with the custom `fields` error listing the allowed values.

**Acceptance Scenarios**:

1. **Given** a valid payload, **When** `POST /dns/templates`, **Then** 201 with default `visible=y` and an `i` datalog entry (primary key `template_id`).
2. **Given** `fields` containing a token outside the allowed set, **When** `POST` or `PUT` (with `fields` present), **Then** 422 via `DnsTemplate::getCustomValidationRules()`.
3. **Given** an existing template, **When** `PUT /dns/templates/{id}` with a partial body, **Then** 200 (all rules relaxed `required`→`sometimes`).

---

### Edge Cases

- **Missing `X-API-Key`**: 401 from the `api.auth` group. Note: `ApiAuthMiddleware` contains a `TODO` — any non-empty key is currently accepted and mapped to ISPConfig user 1 (dev key `dev-api-key` in local env). Key validation is a cross-cutting gap, not DNS-specific.
- **SOA update serial bump is broken**: `DnsSoa::boot()`'s `updating` hook calls `$model->getNextSerialNumber()`, a method that does not exist on `DnsSoa` or `BaseModel` (the real API is `DnsSerialService::getNextSerialNumber($serial)` / `$model->incrementSerial()`). Any `PUT /dns/soa/{id}` that dirties a field *without also sending a changed `serial`* throws `BadMethodCallException`, caught by the controller → 500 `{"message": "Failed to update DNS zone", ...}`. Updates only succeed when the client sends a changed `serial` itself (making `serial` dirty skips the hook) — or when nothing is dirty. Record-triggered zone bumps are unaffected (`incrementSerial()` dirties only `serial`, so the hook is skipped). Recorded in tasks.md Gaps.
- **Zone deletion with records**: `DELETE /dns/soa/{id}` on a zone with `dns_rr` rows returns 400 `{"message": "Cannot delete zone that contains DNS records", "error": "Zone has N associated records"}` — the YAML declares this 400. **Intentional deviation from legacy**, which deactivates the zone and cascade-datalog-deletes all its records (`dns_soa_del.php::onBeforeDelete`). The API forces consumers to empty the zone first.
- **Record types the DB cannot store**: the API accepts `type` values `SOA`, `SPF`, `DKIM`, `DMARC` but the `dns_rr.type` enum (legacy `install/sql/ispconfig3.sql`) only allows `A,AAAA,ALIAS,CNAME,DNAME,CAA,DS,HINFO,LOC,MX,NAPTR,NS,PTR,RP,SRV,SSHFP,TXT,TLSA,DNSKEY`. Legacy stores SPF/DMARC/DKIM as `TXT` rows (`dns_spf_edit.php` queries `type = 'TXT' AND data LIKE 'v=spf1%'`); the API's write path composes correct SPF/DMARC `data` but never rewrites `type` to `TXT`, so MySQL rejects the row (strict mode) or truncates the enum. Conversely `DNAME` and `DNSKEY` exist in the DB enum (and `DNSKEY` in the YAML filter enum) but are rejected by the model's `in:` rule.
- **NAPTR `pref` vs `preference`**: validation requires `pref`, but `DnsRecordMetaService::formatNaptrData()` reads `$data['preference']` — a validated `pref` value is silently dropped and preference defaults to `0`; only an (unvalidated) `preference` key is honored. `parseNaptrData()` returns `preference`, so a GET→PUT round-trip works, but the documented `pref` field does not. NAPTR `regexp` double-backslashes are unescaped on read (commit `1aeb9e0`).
- **Serial arithmetic vs legacy**: `DnsSerialService::getNextSerialNumber()` starts a fresh day at `YYYYMMDD00` and increments past `…99` by plain `+1` (rolling numerically into the next date); legacy `validate_dns::increase_serial()` starts at `YYYYMMDD01` and rolls the date part explicitly. Functionally compatible, off-by-one on the first bump of a day.
- **Filter wildcards**: controllers translate `*` to SQL `%` for `origin`/`name` filters, while every YAML description advertises `%` wildcards and a `field[op]=value` operator syntax (`eq`, `like`, `in`, …) that is **not implemented** — only plain equality/wildcard filters exist.
- **Pagination params**: the YAML `parameters:` blocks reference shared `limit`/`offset`/`sort`/`order`, but the controllers implement Laravel's `page` + `per_page` (default 20, capped 100) and encode direction as a `-` prefix on `sort` (`sort=-origin`); `limit`, `offset` and `order` are ignored. Response bodies return only `pagination.{total,per_page,current_page,last_page}` — a subset of `Pagination.yaml`'s required fields (no `from`/`to`/`path`/`*_page_url`).
- **Invalid `sort` column**: not validated → SQL error surfaces as an uncaught 500 (list endpoints have no try/catch).
- **`y`/`n` flags**: `active`/`visible` use the `YesNoBoolean` cast — JSON responses show booleans, the DB stores `y`/`n`, and validation accepts only literal `y`/`n` input.
- **Permissions**: `sys_perm_*` are stored and defaulted (`riud`/`riud`/``) but **never enforced** — any valid API key can read/write any zone regardless of `sys_userid`/`sys_groupid` (legacy checks `{AUTHSQL}` / `checkPerm` on every operation).
- **`DnsRecord::zone()` relation is miswired**: `belongsTo(DnsSoa::class, 'id', 'zone')` has foreign/owner keys swapped. Harmless at runtime today because `$record->zone` resolves to the integer column (attributes shadow relations) and controllers use `DnsSoa::find()` directly, but eager-loading `zone` would fail.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/soa.yaml`, `api/modules/dns/records.yaml`, `api/modules/dns/slave.yaml`, `api/modules/dns/template.yaml` — all existing, indexed by `api/modules/dns/_index.yaml`, all eight path items registered in root `api/openapi.yaml` (lines 188–204).
- **Shared schemas**: `api/components/schemas/DnsSoa.yaml`, `DnsRecord.yaml`, `DnsSlave.yaml`, `DnsTemplate.yaml`, `Pagination.yaml` (all existing). `DnsRecord.yaml` declares a `discriminator` mapping to per-type schemas (`DnsRecordA` … `DnsRecordNAPTR`) that are **defined nowhere** — dangling refs (see Gaps).
- **Endpoints** (exactly as the YAML declares; success codes verified in code):

| Method | Path | Purpose | Success code | Error codes (per YAML) |
|--------|------|---------|--------------|------------------------|
| GET | `/api/v1/dns/soa` | List zones (`{data, pagination}`); filters `origin`, `active` | 200 | 401, 403, 500 |
| POST | `/api/v1/dns/soa` | Create zone (via datalog) | 201 | 400, 401, 403, 409, 422, 500 |
| GET | `/api/v1/dns/soa/{id}` | Show zone | 200 | 401, 403, 404, 500 |
| PUT | `/api/v1/dns/soa/{id}` | Update zone, partial body (via datalog) | 200 | 400, 401, 403, 404, 409, 422, 500 |
| DELETE | `/api/v1/dns/soa/{id}` | Delete empty zone (via datalog) | 204 | 400 (has records), 401, 403, 404, 500 |
| GET | `/api/v1/dns/records` | List records; filters `zone`, `type`, `name`, `data`, `active` | 200 | 400, 401, 403, 500 |
| POST | `/api/v1/dns/records` | Create record, type-specific meta fields (via datalog) | 201 | 400, 401, 403, 409, 422, 500 |
| GET | `/api/v1/dns/records/{id}` | Show record incl. computed `meta` | 200 | 401, 403, 404, 500 |
| PUT | `/api/v1/dns/records/{id}` | Update record (via datalog) | 200 | 400, 401, 403, 404, 409, 422, 500 |
| DELETE | `/api/v1/dns/records/{id}` | Delete record (via datalog) | 204 | 400, 401, 403, 404, 500 |
| GET | `/api/v1/dns/slaves` | List slave zones; filters `origin`, `active`, `server_id` | 200 | 401, 403, 500 |
| POST | `/api/v1/dns/slaves` | Create slave zone (via datalog) | 201 | 400, 401, 403, 409, 422, 500 |
| GET | `/api/v1/dns/slaves/{id}` | Show slave zone | 200 | 401, 403, 404, 500 |
| PUT | `/api/v1/dns/slaves/{id}` | Update slave zone (via datalog) | 200 | 400, 401, 403, 404, 409, 422, 500 |
| DELETE | `/api/v1/dns/slaves/{id}` | Delete slave zone (via datalog) | 204 | 401, 403, 404, 500 |
| GET | `/api/v1/dns/templates` | List templates; filters `name`, `visible` | 200 | 401, 403, 500 |
| POST | `/api/v1/dns/templates` | Create template (via datalog) | 201 | 400, 401, 403, 409, 422, 500 |
| GET | `/api/v1/dns/templates/{id}` | Show template | 200 | 401, 403, 404, 500 |
| PUT | `/api/v1/dns/templates/{id}` | Update template (via datalog) | 200 | 400, 401, 403, 404, 409, 422, 500 |
| DELETE | `/api/v1/dns/templates/{id}` | Delete template (via datalog) | 204 | 400, 401, 403, 404, 500 |

- **Contract accuracy caveats** (spec text vs shipped behavior): 403 and 409 are declared but no code path produces them (no permission checks; duplicate `origin` yields 422, not 409). `records.yaml`'s long POST description documents an older field convention (`aux` for MX priority, `data` for hostname) whereas the implementation and `DnsRecord.yaml` schema use meta fields (`priority`, `hostname`, …). `DnsRecord.yaml` lacks the NAPTR (`order`, `pref`, `service`, `regexp`, `replacement`), HINFO (`cpu`, `os`), DMARC (`pct`, `rua`, `ruf`, `sp`, `adkim`, `aspf`) and SPF (`allow_mx`, `allow_a`) meta properties the code accepts, and declares nonexistent `created_at`/`updated_at` columns (as does `DnsSlave.yaml`).

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/dns/` — `form/dns_soa.tform.php`, `form/dns_slave.tform.php`, `form/dns_template.tform.php`, per-record-type `form/dns_{a,aaaa,alias,caa,cname,dkim,dmarc,dname,ds,hinfo,loc,mx,naptr,ns,ptr,rp,spf,srv,sshfp,tlsa,txt}.tform.php`, `dns_edit_base.php` (record submit pipeline), `dns_soa_del.php` (zone deletion), `dns_spf_edit.php`/`dns_dmarc_edit.php` (TXT composition), plus `interface/lib/classes/validate_dns.inc.php` (`increase_serial`, IP/hostname validators).
- **Legacy behaviors mirrored**:
  - Record writes stamp the RR (`stamp = now`, `serial = increase_serial(old)`) and bump the parent SOA serial on insert/update/delete — `dns_edit_base.php:141–169` ≙ `DnsRecord::boot()` hooks + `DnsSoa::incrementSerial()` + `DnsSerialService` (date-based `YYYYMMDDnn` serial format).
  - Record `server_id` is forced to the parent zone's `server_id` (`dns_edit_base.php` ≙ `DnsRecordController::store/update`).
  - `aux` column semantics per type: MX priority, SRV priority, NAPTR order (`form/dns_mx.tform.php`, `dns_srv.tform.php`, `dns_naptr.tform.php` ≙ `DnsRecordMetaService::metaToData`); NAPTR/CAA/HINFO quoting of string segments; trailing dots on hostname targets; SPF/DMARC text composition from structured fields (`dns_spf_edit.php`/`dns_dmarc_edit.php` UI logic ≙ `formatSpfData`/`formatDmarcData`).
  - `dns_slave.origin` validation is byte-identical to the legacy regex incl. unique + not-empty; template `fields` whitelist matches legacy `$field_values` (`DOMAIN, IP, IPV6, NS1, NS2, EMAIL, DKIM, DNSSEC`); `sys_perm_*` presets `riud`/`riud`/`` match `auth_preset`.
- **Legacy behaviors NOT mirrored** (unplanned deviations unless noted):
  - `dns_soa.origin` regex differs (API: `/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/` — lowercase, **no trailing dot**; legacy allows trailing dot and `/`, and stores origins dot-terminated). `ns`/`mbox` regexes (legacy requires dot-terminated `mbox`) are not enforced. IDN→punycode (`IDNTOASCII`) and lowercasing SAVE-filters are absent.
  - SOA timer minimums (legacy RANGE `60:`) → API allows `min:0`; API model defaults (`refresh 28800, retry 7200, minimum 86400, ttl 86400`) differ from legacy form defaults (`7200, 540, 3600, 3600`) and from DB defaults (`28800, 7200, 3600, 3600`).
  - `xfer`/`also_notify` are free strings (legacy validates comma-separated IPs via `validate_dns::validate_ip`).
  - Legacy duplicate-RR check (`checkDuplicate`) and "strip accidental quotes around data" normalization are not implemented.
  - Legacy sets the record's `sys_groupid` from the zone after insert; the API requires the client to send `sys_userid`/`sys_groupid` explicitly on every create (all four resources) instead of deriving them from the session/key.
  - SPF/DMARC/DKIM are stored as `TXT` rows in legacy; the API keeps the raw type (broken — see Edge Cases). Legacy types DNAME (and enum value DNSKEY) are not creatable. LOC/RP/ALIAS/DKIM get only generic validation (legacy forms have specific validators).
  - DNSSEC zone options (`dnssec_wanted`, `dnssec_algo`, `dnssec_info`) and `rendered_zone` are exposed read-only in `DnsSoa.yaml`/table but are not in `DnsSoa::$fillable` — unmanageable via API.
  - Zone deletion: **intentional deviation** — refuse (400) instead of cascade delete; documented in `soa.yaml`.
  - No `{AUTHSQL}`/`checkPerm` permission scoping; no zone-wizard (`dns_wizard.php`), import (`dns_import.php`) or `dns_ssl_ca` (DnsCaConfig schema exists, no endpoints).
- **Tables written (via datalog only)**: `dns_soa` (i/u/d), `dns_rr` (i/u/d), `dns_slave` (i/u/d), `dns_template` (i/u/d). All four models extend `App\Models\BaseModel`; `DatalogService` records `{"new": …, "old": …}` diffs with `dbidx = "<pk>:<value>"`. Zone-serial propagation produces an extra `dns_soa` `u` entry per record write.
- **System fields handling**: `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` defaulted in each model's `$attributes`; `sys_userid` falls back to `auth()->id() ?? 1` in `creating` hooks (DnsSoa/DnsSlave/DnsTemplate) but validation makes `sys_userid`+`sys_groupid` required inputs anyway; `server_id` is a required input for zones/slaves and inherited from the zone for records. Datalog entries carry the row's `server_id` and `sys_userid`.
- **Intentional deviations from legacy**: refuse-to-delete non-empty zones (above). All other deviations listed are drift, not decisions — flagged in tasks.md Gaps.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose CRUD endpoints for DNS zones at `/api/v1/dns/soa[/{id}]` returning 200/201/204 per the OpenAPI spec, with 404 for unknown ids and 422 `{message, errors}` for validation failures.
- **FR-002**: System MUST validate zone creation: required `server_id` (existing server), unique well-formed `origin`, `ns`, `mbox`, integer SOA timers, `active` ∈ {y,n}, existing `sys_userid`/`sys_groupid`; on update all rules relax to `sometimes` and the `origin` unique rule excludes the current row (`DnsSoa::getValidationRules($id)` — commit `df6ebc7`).
- **FR-003**: System MUST refuse to delete a zone that still has records (400 with record count) and delete empty zones via datalog with 204.
- **FR-004**: System MUST expose CRUD endpoints for DNS records at `/api/v1/dns/records[/{id}]`, verifying the referenced zone exists (400 otherwise) and inheriting `server_id` from the zone on create and on zone reassignment.
- **FR-005**: System MUST apply per-type validation rules for A, AAAA, CNAME, TXT, NS, MX, SRV, TLSA, SSHFP, CAA, HINFO, SPF, DMARC, NAPTR and DS records (`DnsRecord::validate<TYPE>Record()`), relaxing `required` on update.
- **FR-006**: System MUST accept type-specific meta fields at the top level of record requests and compose the stored `aux` and `data` values (`DnsRecordMetaService::metaToData`), including: `aux` = MX/SRV priority and NAPTR order; double-quoting NAPTR flags/service/regexp, CAA values and HINFO cpu/os; trailing-dot termination of hostname targets; `v=spf1 …` / `v=DMARC1; …` text assembly.
- **FR-007**: System MUST append a computed `meta` object to every serialized record, decomposed from `aux`/`data` per type (`dataToMeta`), classifying stored TXT data beginning `v=spf1`/`v=DMARC1` as SPF/DMARC.
- **FR-008**: System MUST, on every record insert/update/delete, refresh the record's `stamp` and `serial` (update path) and increment the parent zone's SOA serial using the date-based `YYYYMMDDnn` algorithm (`DnsSerialService::getNextSerialNumber`).
- **FR-009**: System MUST expose CRUD endpoints for slave zones at `/api/v1/dns/slaves[/{id}]` validating `origin` against the legacy regex with uniqueness in `dns_slave`.
- **FR-010**: System MUST expose CRUD endpoints for DNS templates at `/api/v1/dns/templates[/{id}]` (primary key `template_id`) and reject `fields` values outside `DOMAIN, IP, IPV6, NS1, NS2, EMAIL, DKIM, DNSSEC` with 422.
- **FR-011**: System MUST route every DNS write through `BaseModel::save()`/`delete()` so `sys_datalog` receives `i`/`u`/`d` entries (update entries containing only changed fields) — no direct table writes.
- **FR-012**: System MUST return list responses as `{data: [...], pagination: {total, per_page, current_page, last_page}}` with `per_page` capped at 100 and `-`-prefixed `sort` for descending order, and support the per-resource filters: soa `origin`/`active`; records `zone`/`type`/`name`/`data`/`active`; slaves `origin`/`active`/`server_id`; templates `name`/`visible`. *(Note: this shape is what the DNS YAML declares via `Pagination.yaml`, but it deviates from the constitution's `{items,total,limit,offset}` contract — see plan.md Constitution Check.)*
- **FR-013**: System MUST default `active`/`visible` to `y`, `ttl` to 3600 (records), and `sys_perm_*` to `riud`/`riud`/`` on create, and serialize `y`/`n` columns as booleans via the `YesNoBoolean` cast.
- **FR-014**: System MUST wrap each write in a DB transaction with rollback and `\Log::error` context, returning `{message, error}` with 500 on unexpected failure.
- **FR-015**: System MUST require a valid `X-API-Key` (401 otherwise) on every DNS endpoint via the `api.auth` middleware group.
- **FR-016**: System MUST handle zone updates by bumping the SOA serial when other fields change [NEEDS CLARIFICATION: intended per legacy parity and attempted in `DnsSoa::boot()`, but the shipped hook calls a nonexistent method and 500s — behavior must be fixed or the requirement re-scoped].

### Key Entities

- **DNS Zone (SOA)**: an authoritative zone with SOA timers, transfer/notify ACLs and DNSSEC columns — table `dns_soa` (pk `id`), schema `api/components/schemas/DnsSoa.yaml`, model `app/Models/DnsSoa.php`. Parent of DNS Records (`records()` hasMany on `dns_rr.zone`); belongs to `server`, `sys_group`.
- **DNS Record**: a resource record in a zone; wire format lives in `type`+`aux`+`data`, API surface adds virtual per-type meta fields — table `dns_rr` (pk `id`), schema `api/components/schemas/DnsRecord.yaml`, model `app/Models/DnsRecord.php`, services `app/Services/DnsRecordMetaService.php` + `app/Services/DnsSerialService.php`.
- **DNS Slave Zone**: a secondary zone transferred from an external master (`ns` = master address, `xfer` = allowed transferees) — table `dns_slave` (pk `id`), schema `api/components/schemas/DnsSlave.yaml`, model `app/Models/DnsSlave.php`.
- **DNS Template**: a zone-wizard template with placeholder `fields` and zone text — table `dns_template` (pk `template_id`), schema `api/components/schemas/DnsTemplate.yaml`, model `app/Models/DnsTemplate.php`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 20 endpoints in `api/modules/dns/*.yaml` are routed (verified: `routes/web.php:76–101`) and respond with the YAML's success codes — 200 list/show/update, 201 create, 204 delete.
- **SC-002**: 100% of DNS writes produce a `sys_datalog` entry (`dns_soa`/`dns_rr`/`dns_slave`/`dns_template`, actions i/u/d) that a stock ISPConfig server processes without error; zero direct UPDATE/INSERT/DELETE statements against those tables outside `BaseModel`.
- **SC-003**: Swagger UI (`/api/documentation`) renders the DNS and DNS Records tags and "Try it out" succeeds for every endpoint with the dev key.
- **SC-004**: For each of the 10 meta-field record types, a create request using only meta fields produces `aux`/`data` byte-identical to what the legacy ISPConfig form would store for the same input, and `GET` returns a `meta` object that round-trips those inputs (known exceptions recorded in Gaps: NAPTR `pref`, SPF/DMARC type column).
- **SC-005**: Every record mutation observably increments the parent zone serial (two datalog entries per record write), matching `dns_edit_base.php` semantics.
- **SC-006**: A zone with N>0 records cannot be deleted (400, message includes N); after deleting all N records it deletes with 204.

## Assumptions

- Only the endpoints specced in `api/modules/dns/` are in scope; legacy wizard/import (`dns_wizard.php`, `dns_import.php`), `dns_ssl_ca` management and DNSSEC toggling have no REST endpoints and are treated as out of scope, not omissions.
- Auth is the existing `X-API-Key` middleware; ISPConfig's `sys_perm_*`/group permission model is stored but deliberately not enforced by this API generation (consistent with every other module).
- A populated `dbispconfig` database with at least one `server` row (`dns_server=1`), `sys_user` id 1 and a `sys_group` is available; `exists:` validation rules read those tables directly (reads are permitted; only writes must go through datalog).
- Legacy parity was assessed against the ISPConfig source vendored in `source_code/` (3.2.x); the `dns_rr.type` enum cited is from `source_code/install/sql/ispconfig3.sql` — the abbreviated `ISPConfig-DB-Structure.txt` (`type[e:A,AAAA,ALIAS,CNAME,etc]`) is not authoritative for the enum members.
- API consumers send `sys_userid`/`sys_groupid` explicitly on create (required by validation); the middleware-provided `ispconfig_user_id` request attribute is not consumed by the DNS controllers.
- The `{data, pagination}` list shape and `page`/`per_page` parameters are treated as the DNS module's shipped contract (they predate the constitution's `{items,total,limit,offset}` rule); reconciliation is tracked in tasks.md Gaps, not silently assumed.
