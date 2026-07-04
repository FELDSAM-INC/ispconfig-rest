# Implementation Plan: DNS Management (zones, records, slave zones, templates)

**Branch**: `002-dns-management` (no git branch — feature shipped on `main`; brownfield migration) | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/002-dns-management/spec.md`

**Note**: Retrospective plan. It documents how the already-shipped implementation is structured and where it stands against the constitution — it does not propose new work (gaps are tracked in [tasks.md](./tasks.md) → Gaps).

## Summary

Full CRUD management of ISPConfig DNS over REST: authoritative zones (`dns_soa`), resource records (`dns_rr`) with type-aware virtual "meta" fields for 10 structured record types, slave zones (`dns_slave`) and zone templates (`dns_template`). All writes flow through `BaseModel` → `DatalogService` → `sys_datalog`. Two dedicated services carry the domain logic: `DnsSerialService` (legacy `YYYYMMDDnn` serial arithmetic) and `DnsRecordMetaService` (bidirectional meta↔`aux`/`data` conversion incl. quoting rules). Record writes replicate legacy side effects: RR `stamp`/`serial` refresh plus parent-SOA serial bump via Eloquent model events.

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp
**Storage**: MySQL — ISPConfig's `dbispconfig` database (tables `dns_soa`, `dns_rr`, `dns_slave`, `dns_template`; schema owned by ISPConfig; all writes via `sys_datalog`)
**Testing**: PHPUnit (`vendor/bin/phpunit`) — **no DNS tests exist**; `tests/` holds only `ClientApiTest.php` + `ExampleTest.php`. Optional per constitution; none were requested.
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: N/A — simple paginated CRUD; heaviest operation is one extra `dns_soa` UPDATE + datalog row per record write (serial bump)
**Constraints**: async write semantics via `sys_datalog` (201 create / 200 update / 204 delete confirm the journal entry, not the applied change); behavioral parity with `source_code/interface/web/dns/`, especially serial bumping and per-type `aux`/`data` encoding
**Scale/Scope**: 4 resources, 20 endpoints, 4 models, 2 services, 4 OpenAPI path files + 4 schemas, ~26 route lines (`routes/web.php:76–101`)

## Constitution Check

*Retrospective gate — actual compliance of the shipped code (constitution v1.0.1).*

- [x] **Spec-first (I)** — *partial*: all 20 endpoints exist in `api/modules/dns/{soa,records,slave,template}.yaml`, are indexed in `_index.yaml` and registered in `api/openapi.yaml` (lines 188–204); bodies reference `api/components/schemas/Dns*.yaml`. **Violations**: `DnsRecord.yaml`'s discriminator maps to 19 per-type schemas (`DnsRecordA`…`DnsRecordNAPTR`) that are defined nowhere; YAML descriptions advertise `field[op]=value` filtering and `%` wildcards not implemented (code uses `*`); declared shared `limit`/`offset`/`order` parameters are ignored by controllers (which use `page`/`per_page`/`-sort`); `records.yaml` POST prose documents a superseded `aux`-based input convention; several meta fields the code accepts are missing from `DnsRecord.yaml`; declared 403/409 responses have no producing code path.
- [x] **Datalog-only writes (II)** — **PASS**: `DnsSoa`, `DnsRecord`, `DnsSlave`, `DnsTemplate` all extend `App\Models\BaseModel`; controllers mutate exclusively via `save()`/`delete()`; zone-serial propagation also goes through `save()` (→ datalog `u`). No direct writes to ISPConfig tables anywhere in the DNS code. `exists:` validation rules perform direct *reads* (permitted).
- [x] **Legacy parity (III)** — *partial*: serial bump pipeline, `aux` semantics, quoting, `server_id` inheritance and slave/template validations mirror `dns_edit_base.php` / tform files (details in spec Parity section). Documented drift: SOA origin/mbox regexes and timer minimums, missing IDN filters, missing duplicate-RR check, SPF/DMARC/DKIM not converted to TXT, record `sys_groupid` not inherited from zone, SOA-update serial hook broken.
- [x] **Route discipline (IV)** — **PASS**: all 20 routes registered inside the `API_PREFIX` + `api.auth` group in `routes/web.php:76–101`; resources use distinct literal segments (`dns/soa`, `dns/slaves`, `dns/templates`, `dns/records`) so no shadowing is possible; `{id}` routes follow their collection routes.
- [ ] **HTTP contract (V)** — *deviation*: write codes are correct (201/200/204, never 202) and errors use `{message, error}` / `{message, errors}` with 400/401/404/422/500. **But** list responses are `{data, pagination:{total,per_page,current_page,last_page}}` with `page`/`per_page` params — not the constitutional `{items,total,limit,offset}` with `limit`/`offset`/`sort`/`order`. The DNS YAML itself declares the `{data, pagination}` shape (`Pagination.yaml`), so code matches the module spec but the module spec deviates from Principle V; additionally the code returns only 4 of `Pagination.yaml`'s 11 required properties. See Complexity Tracking.
- [x] **No schema changes** — **PASS**: no migrations; `database/` untouched by this feature.

## Project Structure

### Documentation (this feature)

```text
specs/002-dns-management/
├── spec.md              # Reverse-engineered feature spec
├── plan.md              # This file
└── tasks.md             # Completed-task record + Gaps list
```

(No `research.md`/`data-model.md`/`contracts/` — brownfield migration; the contract already lives in `api/modules/dns/` and the data model in ISPConfig's schema.)

### Source Code (repository root) — files this feature comprises

```text
api/
├── openapi.yaml                          # lines 188–204: eight /dns/* path refs
├── modules/dns/
│   ├── _index.yaml                       # soa/templates/slaves/records refs
│   ├── soa.yaml                          # /dns/soa, /dns/soa/{id}
│   ├── records.yaml                      # /dns/records, /dns/records/{id}
│   ├── slave.yaml                        # /dns/slaves, /dns/slaves/{id}
│   └── template.yaml                     # /dns/templates, /dns/templates/{id}
└── components/
    ├── schemas/DnsSoa.yaml               # incl. read-only dnssec_*/rendered_zone
    ├── schemas/DnsRecord.yaml            # common + meta fields, (dangling) discriminator
    ├── schemas/DnsSlave.yaml
    ├── schemas/DnsTemplate.yaml
    ├── schemas/Pagination.yaml           # shared (reused)
    ├── parameters/{limit,offset,sort,order}.yaml   # referenced, not honored by code
    └── responses/                        # shared errors (reused)

app/
├── Http/Controllers/Api/V1/
│   ├── DnsSoaController.php              # index/store/show/update/destroy; blocks delete of non-empty zone
│   ├── DnsRecordController.php           # + zone-existence check, server_id inheritance, processMetaFields()
│   ├── DnsSlaveController.php
│   └── DnsTemplateController.php         # + custom `fields` whitelist validation
├── Models/
│   ├── BaseModel.php                     # (pre-existing) datalog save/delete
│   ├── DnsSoa.php                        # rules incl. getValidationRules($id); records() hasMany; incrementSerial()
│   ├── DnsRecord.php                     # per-type rules, $metaFields map, meta accessor, boot() serial/stamp hooks
│   ├── DnsSlave.php
│   └── DnsTemplate.php                   # $validFields whitelist, pk template_id
├── Services/
│   ├── DnsSerialService.php              # getNextSerialNumber(), getCurrentTimestamp()
│   ├── DnsRecordMetaService.php          # metaToData()/dataToMeta() for 10 types + TXT sniffing
│   └── DatalogService.php                # (pre-existing) sys_datalog writer
└── Casts/YesNoBoolean.php                # (pre-existing) y/n ↔ bool

routes/web.php                            # lines 76–101 (SOA, slaves, templates, records blocks)

tests/                                    # ── none for DNS (gap)
```

**Structure Decision**: One controller + model per ISPConfig table, flat under `Api\V1` (no `Dns/` subnamespace — matches the module's era). Cross-record-type logic was correctly pushed into `app/Services/` per Principle IV: serial arithmetic is shared by `DnsSoa` and `DnsRecord`; meta conversion is shared by the controller (write path) and the model's `meta` accessor (read path). Route blocks are grouped per resource inside the single `api.auth` group; distinct first segments make ordering trivially safe.

## Legacy Research (Phase 0 focus)

What `source_code/interface/web/dns/` prescribes, and how the shipped code mirrors it:

- **Form definitions** (`form/dns_soa.tform.php`, `dns_slave.tform.php`, `dns_template.tform.php`, per-type `dns_*.tform.php`):
  - SOA: origin NOTEMPTY+UNIQUE+regex (trailing dot / `/` allowed), IDN-to-ASCII + lowercase SAVE filters, mbox regex requiring trailing dot, timers RANGE `60:`, defaults refresh 7200 / retry 540 / expire 604800 / minimum 3600 / ttl 3600, `xfer`/`also_notify` = comma-separated IPs (`validate_dns::validate_ip`), `active` checkbox default `Y`, DNSSEC fields. → API mirrors NOTEMPTY/UNIQUE and integer typing; regexes, ranges, defaults, IDN filters, IP validation and DNSSEC fields drift (spec → Parity).
  - Slave: origin regex copied verbatim into `DnsSlave::$rules`; template: `fields` CHECKBOXARRAY whitelist copied verbatim into `DnsTemplate::$validFields`.
  - Record forms: each type's tform defines which value lands in `aux` (MX/SRV priority, NAPTR order) vs `data`, mirrored in `DnsRecord::$metaFields` + `DnsRecordMetaService` (commit `ae5f4a5` "some record types use aux field").
- **Actions/lib side effects** (`dns_edit_base.php`, `dns_soa_del.php`, `interface/lib/classes/validate_dns.inc.php`):
  - On every RR submit: force `server_id` from SOA; set `stamp = now`, `serial = increase_serial(old)`; after insert/update: `datalogUpdate('dns_soa', serial)` → mirrored by `DnsRecordController` (`server_id`) and `DnsRecord::boot()` `updating`/`created`/`updated`/`deleted` hooks calling `DnsSoa::incrementSerial()` (commit `1b98a56`).
  - `increase_serial()`: date-prefixed 10-digit serial, `YYYYMMDD01` for a fresh day, 2-digit counter with date rollover — reimplemented as `DnsSerialService::getNextSerialNumber()` (fresh day = `…00`, rollover by plain increment; functionally compatible).
  - After RR insert legacy copies `sys_groupid` from the SOA; **not** mirrored (API requires it as input).
  - Zone delete: legacy deactivates the SOA and datalog-deletes every RR; API intentionally refuses instead (400) — the only agreed deviation, encoded in `soa.yaml`.
  - SPF/DMARC editors compose a TXT record (`type='TXT'`, data `v=spf1…`/`v=DMARC1…`); API composes the same data but keeps `type='SPF'|'DMARC'`, which the `dns_rr.type` enum (see `source_code/install/sql/ispconfig3.sql`, `dns_rr`) does not accept — defect, see Gaps.
- **Permission checks**: every legacy query is scoped with `{AUTHSQL}` / `checkPerm('r'|'u'|'d')`. The API stores `sys_perm_*`/`sys_groupid` but enforces nothing — consistent with the rest of this API generation; noted, not fixed, here.
- **List definitions** (`list/dns_soa.list.php` etc.): filterable columns informed the index filters (`origin`, `active`, `server_id`, `zone`, `type`, `name`, `data`).

## Complexity Tracking

> Constitution Check violations that ship today and why they stand:

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| List shape `{data, pagination}` + `page`/`per_page` instead of Principle V's `{items,total,limit,offset}` (V) | Whole DNS module (YAML + 4 controllers) was built on Laravel's paginator before the constitution codified the newer shape; the YAML and code agree with each other | Changing either side alone breaks the other; migrating both is a coordinated breaking change for existing consumers — deferred to Gaps |
| Dangling discriminator refs + stale prose in `records.yaml`, meta fields missing from `DnsRecord.yaml` (I) | Contract was drafted ahead of the meta-field redesign (commit `bfce708` "DNS - Fix store and update") and never reconciled | Fixing requires spec-only edits (no code) — low-risk cleanup, tracked in Gaps |
| No `sys_perm_*` enforcement (III) | No API-side permission model exists yet in any module (`ApiAuthMiddleware` TODO); DNS could not sensibly enforce alone | Per-module enforcement would diverge from every other controller; needs a cross-cutting feature |
