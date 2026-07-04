# Tasks: DNS Management (zones, records, slave zones, templates)

**Input**: Design documents from `/specs/002-dns-management/`
**Prerequisites**: plan.md, spec.md

**Status**: Brownfield migration — every task below is checked because the work already shipped on `main` (commits `bf42794`, `9e7d0f0`, `1b98a56`, `d19ec31`, `ae5f4a5`, `1aeb9e0`, `bfce708`, `7dcc2ff`, `df6ebc7`, `6a7b923`). Unchecked items live only in **Gaps** at the end.

**Tests**: None were requested by the (retroactive) spec and none exist for DNS — see Gaps.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup (contract + legacy research)

- [x] T001 Author zone endpoint spec `api/modules/dns/soa.yaml` (GET/POST `/dns/soa`, GET/PUT/DELETE `/dns/soa/{id}`; 200/201/204; 400-on-delete-with-records documented) and register in `api/modules/dns/_index.yaml` + root `api/openapi.yaml`
- [x] T002 [P] Author record endpoint spec `api/modules/dns/records.yaml` (per-type field requirements in POST description) and register it
- [x] T003 [P] Author slave endpoint spec `api/modules/dns/slave.yaml` and register it
- [x] T004 [P] Author template endpoint spec `api/modules/dns/template.yaml` and register it
- [x] T005 [P] Author shared schemas `api/components/schemas/DnsSoa.yaml`, `DnsRecord.yaml` (common + meta-field properties, type discriminator), `DnsSlave.yaml`, `DnsTemplate.yaml`; reuse `Pagination.yaml`, `components/parameters/*`, `components/responses/*`
- [x] T006 [P] Extract legacy behavior from `source_code/interface/web/dns/` — tform validators/defaults per record type, `dns_edit_base.php` serial/stamp/server_id side effects, `validate_dns::increase_serial()`, `dns_soa_del.php` cascade semantics, template `$field_values` whitelist (captured in spec.md → ISPConfig Parity)

---

## Phase 2: Foundational (models + services)

- [x] T007 Create `app/Models/DnsSoa.php` extending `BaseModel` — `$table='dns_soa'`, pk `id`, `$fillable` incl. `xfer`/`also_notify`/`update_acl`, `YesNoBoolean` cast on `active`, integer casts, defaults (`active=y`, `sys_perm_*`, serial/timers), static rules + `getValidationRules($id)` (update relaxation + unique-except-self, commit `df6ebc7`), `records()`/`server()`/`group()` relations, `incrementSerial()`
- [x] T008 [P] Create `app/Models/DnsRecord.php` extending `BaseModel` — `$table='dns_rr'`, pk `id`, `$metaFields` map for 12 type keys, per-type `validate<TYPE>Record()` rule methods (A, AAAA, CNAME, TXT, NS, MX, SRV, TLSA, SSHFP, CAA, HINFO, SPF, DMARC, NAPTR, DS), `getValidationRules($type,$id,$forUpdate)`, appended `meta` accessor, `processMetaFields()`, boot hooks: `updating` → stamp+serial refresh; `created`/`updated`/`deleted` → parent-zone `incrementSerial()`
- [x] T009 [P] Create `app/Models/DnsSlave.php` extending `BaseModel` — `$table='dns_slave'`, legacy-identical origin regex + unique rule, `YesNoBoolean` on `active`
- [x] T010 [P] Create `app/Models/DnsTemplate.php` extending `BaseModel` — `$table='dns_template'`, pk `template_id`, `$validFields = [DOMAIN, IP, IPV6, NS1, NS2, EMAIL, DKIM, DNSSEC]`, `getCustomValidationRules()` for the comma-separated `fields` whitelist
- [x] T011 [P] Create `app/Services/DnsSerialService.php` — `getNextSerialNumber()` (legacy `YYYYMMDDnn` algorithm), `getCurrentTimestamp()`
- [x] T012 [P] Create `app/Services/DnsRecordMetaService.php` — `metaToData()`/`dataToMeta()` for MX, SRV, TLSA, SSHFP, CAA, HINFO, SPF, DMARC, NAPTR, DS; `aux` carries MX/SRV priority and NAPTR order (commit `ae5f4a5`); quoting of NAPTR flags/service/regexp incl. double-backslash unescape on read (commit `1aeb9e0`), CAA value and HINFO cpu/os quoting; trailing-dot handling; TXT→SPF/DMARC sniffing via `guessType()`
- [x] T013 Confirm datalog behavior for all four entities — correct table/pk reach `sys_datalog` via `BaseModel::save()/delete()` + `DatalogService` (`dbidx="<pk>:<value>"`, update payloads are changed-fields diffs)

**Checkpoint**: Models datalog correctly; user-story controllers can build on them

---

## Phase 3: User Story 1 - Manage authoritative DNS zones (Priority: P1) — MVP

- [x] T014 [US1] Implement `index`/`show` in `app/Http/Controllers/Api/V1/DnsSoaController.php` — filters `origin` (`*`→`%`), `active`; `-`-prefixed `sort` (default `origin`); `per_page` cap 100; response `{data, pagination:{total,per_page,current_page,last_page}}`; 404 `{message}` for unknown id
- [x] T015 [US1] Implement `store`/`update`/`destroy` — `Validator::make` with `DnsSoa::getValidationRules()`, 422 `{message, errors}`; destroy uses `withCount('records')` and returns 400 when the zone has records (intentional deviation from legacy cascade delete); writes via `save()`/`delete()` in DB transactions with rollback + `\Log::error`; 201/200/204
- [x] T016 [US1] Register SOA routes in `routes/web.php:76–80` inside the `api.auth` + `API_PREFIX` group
- [x] T017 [US1] Verify against Swagger UI: `/dns/soa` paths render and match `api/modules/dns/soa.yaml`

**Checkpoint**: Zone CRUD independently usable (MVP)

---

## Phase 4: User Story 2 - Manage DNS records with type-aware meta fields (Priority: P2)

- [x] T018 [US2] Implement `index`/`show` in `app/Http/Controllers/Api/V1/DnsRecordController.php` — filters `zone`, `type` (upper-cased), `name` (`*`→`%`), `data` (substring), `active`; every response row carries the computed `meta` object
- [x] T019 [US2] Implement `store` — type-driven rules via `DnsRecord::getValidationRules($type, null, false)`; 400 `{message:"Zone not found"}` for missing zone; `processMetaFields()` composes `aux`/`data` from top-level meta fields (commit `bfce708` moved meta fields to the top level); `server_id` inherited from the zone; 201
- [x] T020 [US2] Implement `update`/`destroy` — update relaxes `required` on type rules, re-validates new `zone` and re-inherits `server_id` on zone change, 200; destroy 204; both in transactions
- [x] T021 [US2] Wire legacy side effects — `DnsRecord::boot()` refreshes `stamp` + record `serial` on update (commit `1b98a56`) and bumps the parent SOA serial on create/update/delete via `DnsSoa::incrementSerial()` (extra `dns_soa` datalog `u` per record write, matching `dns_edit_base.php`)
- [x] T022 [US2] Register record routes in `routes/web.php:97–101`
- [x] T023 [US2] Verify against Swagger UI: `/dns/records` paths render and match `api/modules/dns/records.yaml`

**Checkpoint**: Zones + records fully functional together

---

## Phase 5: User Story 3 - Manage slave zones (Priority: P3)

- [x] T024 [P] [US3] Implement `app/Http/Controllers/Api/V1/DnsSlaveController.php` — index filters `origin`/`active`/`server_id`; store/update/destroy with `DnsSlave::getValidationRules($id)`; 201/200/204/404/422/500 (commit `7dcc2ff`)
- [x] T025 [US3] Register slave routes in `routes/web.php:83–87`
- [x] T026 [US3] Verify against Swagger UI: `/dns/slaves` paths match `api/modules/dns/slave.yaml`

---

## Phase 6: User Story 4 - Manage DNS templates (Priority: P4)

- [x] T027 [P] [US4] Implement `app/Http/Controllers/Api/V1/DnsTemplateController.php` — index filters `name`/`visible`; store/update run both `getValidationRules()` and the custom `fields` whitelist check (update only when `fields` present); pk `template_id`; 201/200/204/404/422/500 (commit `6a7b923`)
- [x] T028 [US4] Register template routes in `routes/web.php:90–94`
- [x] T029 [US4] Verify against Swagger UI: `/dns/templates` paths match `api/modules/dns/template.yaml`

---

## Phase 7: Polish & Cross-Cutting

- [x] T030 Fix validation relaxation on SOA update — `required`→`sometimes` + unique-except-self (commit `df6ebc7` "Fixed validation rules on update dns soa")
- [x] T031 [P] `YesNoBoolean` cast reused for all `active`/`visible` flags (no ad-hoc `y`/`n` handling in DNS controllers); boolean normalization in `BaseModel` datalog diffs (commit `b26f459`)
- [x] T032 Re-verify legacy parity for serial bump + aux/quoting cases against `source_code/interface/web/dns/` (documented in spec.md Parity; residual drift listed below)

---

## Gaps

Genuine unfinished/incorrect items found during migration — **not** checked off. File paths point at where the fix belongs.

### Defects (code)

- [ ] G01 **SOA update serial bump crashes**: `app/Models/DnsSoa.php:230` calls `$model->getNextSerialNumber()`, which is not defined anywhere (service method is static and takes the current serial). Any `PUT /dns/soa/{id}` that changes a field without also sending a changed `serial` → `BadMethodCallException` → 500. Fix: `$model->serial = \App\Services\DnsSerialService::getNextSerialNumber($model->getOriginal('serial'))`.
- [ ] G02 **SPF/DKIM/DMARC (and SOA) type values are not storable**: `DnsRecord` validation accepts `type` ∈ {…, SOA, SPF, DKIM, DMARC} but the `dns_rr.type` enum (legacy `install/sql/ispconfig3.sql`) has no such members — MySQL strict mode rejects the insert. Legacy stores these as `TXT` rows; the read path (`DnsRecordMetaService::guessType`) already expects that. Fix in `app/Models/DnsRecord.php` / `DnsRecordController::store` (rewrite type to `TXT` on save, as `dns_spf_edit.php`/`dns_dmarc_edit.php` do).
- [ ] G03 **NAPTR `pref` is silently ignored on write**: validation and `$metaFields['NAPTR']` use `pref`, but `DnsRecordMetaService::formatNaptrData()` reads `preference` (`app/Services/DnsRecordMetaService.php:647`), so the validated `pref` defaults to 0; `parseNaptrData()` returns `preference`. Unify the field name across `app/Models/DnsRecord.php` (rules + `$metaFields`) and the service.
- [ ] G04 **`DnsRecord::zone()` relation keys swapped**: `belongsTo(DnsSoa::class, 'id', 'zone')` should be `belongsTo(DnsSoa::class, 'zone', 'id')` (`app/Models/DnsRecord.php:404-407`). Latent — controllers use `DnsSoa::find()` and the boot hooks read the `zone` attribute — but any eager load breaks.
- [ ] G05 **DKIM meta handling missing**: `DKIM` is an accepted type with a legacy form (`form/dns_dkim.tform.php`) but has no entry in `$metaFields`, no `validateDKIMRecord()`, and no meta formatter — only generic base rules apply (also affected by G02).
- [ ] G06 **Record types in legacy/DB but not creatable via API**: `DNAME` (legacy `form/dns_dname.tform.php`, in DB enum) and `DNSKEY` (in DB enum and in `records.yaml`'s filter enum) are rejected by the model's `in:` rule; `LOC`/`RP`/`ALIAS` are accepted but get no type-specific validation while legacy forms validate them.
- [ ] G07 **Unvalidated `sort` input**: an unknown `sort` column or crafted value in any of the four `index()` methods produces an uncaught SQL error → 500 (list endpoints have no try/catch). Whitelist sortable columns.
- [ ] G08 **DNSSEC zone fields unmanageable**: `dnssec_wanted`/`dnssec_algo`/`dnssec_info` are in `DnsSoa.yaml` and the table but absent from `DnsSoa::$fillable` and validation — GET returns them, writes silently drop them. Decide: support or mark `readOnly` in the schema.
- [ ] G09 **`dns_template.name` length**: DB column is varchar(64); `DnsTemplate::$rules` allows `max:255` — values 65–255 chars fail at the DB layer as a 500 instead of a 422.

### Contract drift (spec-only fixes in `api/`)

- [ ] G10 **Dangling discriminator refs**: `api/components/schemas/DnsRecord.yaml` maps 19 `DnsRecord<TYPE>` schemas that exist nowhere; Swagger renders but `$ref` resolution is broken for validating tooling. Either author the per-type schemas or drop the discriminator.
- [ ] G11 **Pagination/params mismatch**: DNS YAMLs reference `limit`/`offset`/`order` parameters the controllers ignore (code uses `page`/`per_page`/`-sort`); controllers return 4 of `Pagination.yaml`'s 11 required properties; constitution Principle V expects `{items,total,limit,offset}`. Reconcile spec + code + constitution in one pass (breaking change — coordinate with consumers).
- [ ] G12 **Stale/false endpoint prose**: `field[op]=value` operator filtering advertised in all four module YAMLs is unimplemented; wildcard documented as `%` but code translates `*`; `records.yaml` POST description still documents the pre-meta-field `aux` convention (e.g., "MX: aux required") contradicted by the schema and code.
- [ ] G13 **Nonexistent columns in schemas**: `created_at`/`updated_at` declared in `DnsRecord.yaml` and `DnsSlave.yaml` — `dns_rr`/`dns_slave` have no such columns (`dns_rr` has `stamp`/`serial`, which are undocumented in the schema). Missing meta-field properties in `DnsRecord.yaml`: NAPTR `order`/`pref`/`service`/`regexp`/`replacement`, HINFO `cpu`/`os`, SPF `allow_mx`/`allow_a`, DMARC `pct`/`rua`/`ruf`/`sp`/`adkim`/`aspf`, SSHFP `algorithm`/`hash_type` are present but DS `algorithm` shares the same property — audit the full field list against `DnsRecord::getBaseRules()` + `$metaFields`.
- [ ] G14 **Declared-but-unreachable statuses**: 403 (no permission model) and 409 (duplicates return 422) appear in every DNS write's responses; align YAML with behavior or implement the behavior.

### Legacy-parity drift (decide: adopt or document as intended)

- [ ] G15 **SOA field validation weaker than legacy**: origin regex forbids the trailing dot legacy stores (`example.com.`) and skips IDN/lowercase filters; `mbox` dot-termination not enforced; timers allow `min:0` vs legacy `60:`; `xfer`/`also_notify` accept arbitrary strings vs legacy comma-separated-IP validation; model defaults (refresh 28800/retry 7200/minimum 86400/ttl 86400) match neither legacy form defaults (7200/540/3600/3600) nor DB defaults (28800/7200/3600/3600).
- [ ] G16 **Missing legacy record-pipeline behaviors**: duplicate-record check (`dns_edit_base.php::checkDuplicate`), stripping accidental quotes around `data`, and copying `sys_groupid` from the SOA to new records (`onAfterInsert`).
- [ ] G17 **Serial day-start off by one**: `DnsSerialService` starts a new day at `YYYYMMDD00`; legacy at `YYYYMMDD01`.
- [ ] G18 **No permission enforcement**: `sys_perm_*`/`sys_groupid` stored but never checked (legacy `{AUTHSQL}`/`checkPerm`); depends on the cross-cutting `ApiAuthMiddleware` TODO (`app/Http/Middleware/ApiAuthMiddleware.php:34`).

### Missing coverage

- [ ] G19 **No DNS tests**: `tests/` contains only `ClientApiTest.php`/`ExampleTest.php`/`TestCase.php`. Highest-value additions: `tests/DnsSoaApiTest.php` (update-500 regression for G01, delete-with-records 400) and `tests/DnsRecordApiTest.php` (meta↔data round-trips for all 10 structured types, serial bump side effects), following the `ClientApiTest.php` pattern.
- [ ] G20 **No REST counterpart for the zone wizard/import** (`dns_wizard.php`, `dns_import.php`) — templates are storable (US4) but nothing expands them into zones; `dns_ssl_ca` (schema `DnsCaConfig.yaml` exists) has no endpoints. Confirm out-of-scope or spec as new features.

---

## Dependencies & Execution Order (as built)

- Setup (T001–T006) → Foundational (T007–T013) → US1 (T014–T017) → US2 (T018–T023, depends on US1's zones) → US3/US4 (independent, shipped later) → Polish (T030–T032).
- Git history confirms the order: `bf42794` (SOA+records) → `1b98a56`/`ae5f4a5`/`1aeb9e0`/`bfce708` (record refinements) → `7dcc2ff` (slaves) → `df6ebc7` (SOA update validation) → `6a7b923` (templates).
- `routes/web.php` was edited sequentially per story (never in parallel); resource-distinct segments keep ordering safe.

## Notes

- Every write path goes through `BaseModel::save()`/`delete()` — verified; no constitution-violating direct writes in the DNS module.
- Gaps G01–G03 are the ones that break real consumer flows today (SOA update 500, SPF/DMARC create failure, NAPTR pref loss); prioritize in that order.
