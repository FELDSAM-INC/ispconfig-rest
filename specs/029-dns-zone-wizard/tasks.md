---

description: "Task list for spec 029 — DNS zone wizard for scoped keys"
---

# Tasks: DNS Zone Wizard For Scoped Keys

**Input**: Design documents from `/specs/029-dns-zone-wizard/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1158 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Add `api/components/schemas/DnsZoneTemplate.yaml` (`id`, `name`, `fields[]`; no template text, no system fields) and register it in `api/components/schemas/_index.yaml`
- [x] T002 [P] Add `api/components/schemas/DnsZoneFromTemplate.yaml` (`template_id`, `domain`, `ip`, `ipv6`, `ns1`, `ns2`, `email`, `dkim`, `dnssec`, `server_id`, `client_id`) and register it in `api/components/schemas/_index.yaml`
- [x] T003 Add `api/modules/dns/zone-templates.yaml` (GET list, visible-only, readable by every key) and reference it from `api/modules/dns/_index.yaml`
- [x] T004 Add the `/dns/soa/from-template` path to `api/modules/dns/soa.yaml` per contracts/dns-zone-wizard.md (201 + `X-Change-Set-Id`, 403/409/422 cases)
- [x] T005 Register `/dns/zone-templates` and `/dns/soa/from-template` in `api/openapi.yaml`
- [x] T006 Verify the spec parses and is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Story 2 — Template list readable by scoped keys (P1)

### Tests (write first, must fail)

- [x] T007 [US2] New `tests/Feature/DnsZoneWizardApiTest.php`: `GET /dns/zone-templates` returns the admin-owned visible template to client, reseller and admin keys; an invisible template is absent for all; entries carry `id`, `name`, `fields[]` and no `template`/`sys_*`; sorted by name; pagination meta present; unauthenticated 401
- [x] T008 [P] [US2] Assert in the same file that `GET /dns/templates` stays row-scoped for a client key (guards the spec 011 behaviour this feature must not change)

### Implementation

- [x] T009 [US2] Add `app/Http/Controllers/Api/V1/DnsZoneTemplateController.php` (`index`: `visible = true`, no read predicate, `fields` split to an array, sortable `name`/`id`, default `name` asc)
- [x] T010 [US2] Register `GET dns/zone-templates` in `routes/api/dns.php` before the `dns/templates` block

**Checkpoint**: US2 tests green

---

## Phase 3: User Story 1 — Zone creation from a template (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T011 [US1] Expansion happy path in `tests/Feature/DnsZoneWizardApiTest.php`: client key + "Default"-shaped template → 201, zone row (timers from the template, `active` true, dot-terminated lower-cased `origin`/`ns`/`mbox`, `mbox` `@`→`.`, generated serial), and the template's records with placeholders replaced (names, data, `aux`, `ttl`)
- [x] T012 [P] [US1] Journal assertions: `dns_soa` `i` with `active` N, one `dns_rr` `i` per record in template order, `dns_soa` `u` with `active` Y, all sharing one `X-Change-Set-Id`
- [x] T013 [P] [US1] Ownership and placement: records inherit the zone's `server_id` and `sys_groupid`; admin key with `client_id` creates everything under that client's group; `server_id` of an unassigned server → 422 with `error_types.server_id` = `server-not-assigned`; omitted `server_id` uses the account's assigned DNS server
- [x] T014 [P] [US1] Validation: missing declared placeholder → 422 on that field; value/flag the template does not declare → 422; invalid `domain`/`ns1`/`ns2`/`email` per the legacy regexes → 422; unknown, invisible and malformed templates (unknown section, missing required zone key, unknown record type) → 422 on `template_id` and nothing written; duplicate origin → 409 and nothing written
- [x] T015 [P] [US1] IPv6: a template declaring `IPV6` with an `ipv6` value produces the AAAA record

### Implementation

- [x] T016 [US1] Add `app/Services/DnsZoneWizardService.php`: placeholder replacement, `[ZONE]`/`[DNS_RECORDS]` parser (required zone keys, record row shape, short rows inherit the zone TTL and `aux` 0, `dns_rr.type` whitelist), and `create()` writing zone → records → activation inside one `DB::transaction()`
- [x] T017 [US1] Add `app/Http/Requests/StoreDnsSoaFromTemplateRequest.php`: `template_id` restricted to visible templates, placeholder rules derived from the template's `fields`, legacy normalization (IDN + lower-case, `mbox` from `email`), `ResolvesAssignedServer('dns')`, optional `client_id`
- [x] T018 [US1] Add `storeFromTemplate()` to `app/Http/Controllers/Api/V1/DnsSoaController.php` (duplicate-origin 409 as `store()`, ownership via `ResolvesClientOwnership`, 201 with the zone) and register `POST dns/soa/from-template` in `routes/api/dns.php` before `dns/soa/{dnsSoa}`

**Checkpoint**: US1 + US2 tests green — the wizard works end to end

---

## Phase 4: User Story 3 — Limits cannot be bypassed (P1)

### Tests (write first, must fail)

- [x] T019 [US3] Record cap: client with `limit_dns_record` below the template's record count → 403 `limit-reached` with `limit {name: limit_dns_record, scope: client, max, used}`, no `dns_soa`, no `dns_rr`, no journal entry; `-1` unlimited → 201; a cap exactly matching the batch → 201
- [x] T020 [P] [US3] Zone cap: client at `limit_dns_zone` → 403 `limit-reached` with `limit.name = limit_dns_zone` and nothing written; reseller cap enforced as legacy does; admin key unaffected by either cap

### Implementation

- [x] T021 [US3] Add `checkBatchCreate(string $table, int $count)` to `app/Services/ClientLimitService.php` (resolve the table's count specs, deny when `used + count > max`, reseller cap where the spec has one) and call it for `dns_soa` (1) and `dns_rr` (record count) before the first write in `DnsZoneWizardService::create()`

**Checkpoint**: US3 tests green

---

## Phase 5: User Story 4 — DKIM and DNSSEC flags (P2)

### Tests (write first, must fail)

- [x] T022 [US4] `dkim: true` with a readable DKIM-enabled mail domain → the zone gains the `<selector>._domainkey.<domain>.` TXT record with the stripped public key and the zone's TTL; selector defaults to `default`; no readable mail domain (none, DKIM off, or another client's) → zone created without the record and no error; the DKIM record counts towards the record cap
- [x] T023 [P] [US4] `dnssec: true` → zone created with `dnssec_wanted` true; flags refused when the template does not declare them (covered from T014, asserted here for both flags)

### Implementation

- [x] T024 [US4] Add the DKIM lookup (spec 024 `readableQuery('mail_domain')`, `dkim = 'y'`, public key stripped of PEM headers and line breaks) and the `dnssec_wanted` injection to `app/Services/DnsZoneWizardService.php`; add guarded `mail_domain` creation to `tests/Support/DnsSchema.php` if the DKIM test cannot reuse `MailSchema`

**Checkpoint**: all user stories green

---

## Phase 6: Polish

- [x] T025 [P] Document both endpoints in the DNS paragraph of `README.md` (template visibility rule, one-call zone creation, both caps enforced for the batch)
- [x] T026 [P] Mark the "legacy wizard has no REST counterpart" statements in `specs/002-dns-management/spec.md` as superseded by spec 029
- [x] T027 Run Pint on the changed files and the full suite in Docker on PHP 8.3 (expect baseline 1158 + the new tests)

---

## Phase 7: Deployment & verification

- [x] T028 Deploy to isp-test (`ispconfig-rest update && ispconfig-rest status`) and confirm the running commit
- [x] T029 Run quickstart.md §2 with a temporary client (template list, expansion, panel-wizard parity diff, both caps, invisible template, DKIM, DNSSEC, unassigned server), then clean up temporary clients, zones, mail domains and QA keys and verify no leftovers
- [x] T030 Record the verification results in this file and commit

## Results

Verified on isp-test (deployed commit `d3e3754`) on 2026-09-16 with a temporary client (`QA029-…`, client 34), its
own client key and a QA admin key. Every row created for the run was deleted afterwards.

| # | Check | Result |
|---|---|---|
| 1 | `GET /dns/zone-templates` with a client key | 200; the administrator-owned "Default" template listed with its `fields` array |
| 2 | `GET /dns/templates` with the same key | `meta.total` 0 — spec 011 row scoping unchanged |
| 3 | Wizard create | 201 + `X-Change-Set-Id`; `origin`/`ns`/`mbox` dot-terminated and lower-cased, `mbox` `@`→`.`, SOA timers from the template, zone active |
| 4 | Records | exactly the template's seven rows with the placeholders replaced (A apex/www/mail, 2× NS, MX aux 10, SPF TXT) |
| 5 | Journal | `dns_soa i` → 7× `dns_rr i` → `dns_soa u`, all sharing one change set |
| 6 | Server side | processed in 1 s; `pri.<zone>` written for all three zones and `named-checkzone` OK for each; the rendered zone body matches the template |
| 7 | `dnssec: true` | zone created with `dnssec_wanted` true; the server generated KSK + ZSK and a `dsset-` file (deviation 7 — legacy's injected flag is overwritten by the template and does nothing) |
| 8 | `dkim: true` | `default._domainkey.<domain>.` TXT added with the published key and the zone's TTL 3600 (deviation 5 — legacy stores TTL 0) |
| 9 | Record cap (`limit_dns_record` 3) | 403 `limit-reached`, `limit.name` `limit_dns_record`, `max` 3, **no journal entry** — refused before any write (deviation 1) |
| 10 | Zone cap (`limit_dns_zone` 3 with 3 zones) | 403 `limit-reached`, `limit.name` `limit_dns_zone`, no journal entry |
| 11 | Hidden template | absent from `/dns/zone-templates`; the wizard refuses its id with 422 on `template_id` (deviation 3) |
| 12 | Missing placeholder (`ip`) | 422 `errors.ip` — a literal `{IP}` is never written (deviation 2) |
| 13 | `server_id` 99 | 422 with `error_types.server_id` = `server-not-assigned` |
| 14 | Duplicate origin | 409 |

**Panel-wizard comparison** (quickstart §2 step 7): done at code level (research R1) instead of driving the
ISPConfig UI, which needs an interactive session. The API writes the same rows, in the same order, from the same
template text, and the zone file bind rendered was compared against the template line by line.

**Observation, not part of this feature**: `PUT /clients/{id}` rejects `dns_servers`/`mail_servers` sent as JSON
arrays — `Client.yaml` types them as comma-separated strings, so `"1"` is required. The run therefore used the
default servers ISPConfig assigns to a new client, which is why the zones still landed on server 1.

**Cleanup**: the three zones, the mail domain, the temporary template and the client were deleted through the API;
after the server finished processing, no `qa029` rows, bind zone files, DNSSEC key files or `named.conf.local`
references remained, and the QA keys were deleted by SQL. Keys 1, 2, 20, 27, 50 and clients C1, C2, WHMCS-2 are
pre-existing and untouched; `dns_template` holds only the shipped "Default" row.
