---

description: "Task list for spec 032 — DNSSEC management for scoped keys"
---

# Tasks: DNSSEC Management For Scoped Keys

**Input**: Design documents from `/specs/032-dnssec-management/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1186 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Add `api/components/schemas/DnsSoaDnssec.yaml` (state, availability, flags, `ds_records[]`, `dnskey_records[]`) and register it in `api/components/schemas/_index.yaml`
- [x] T002 [P] Add the `/dns/soa/{id}/dnssec` path to `api/modules/dns/soa.yaml`, describe the mirror rule on PUT, and mark `dnssec_info` administrator-only in `api/components/schemas/DnsSoa.yaml` (per contracts/dnssec.md)
- [x] T003 Register `/dns/soa/{id}/dnssec` in `api/openapi.yaml`
- [x] T004 Verify the spec parses and is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Story 1 — The DS record for the registrar (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T005 [US1] New `tests/Feature/DnsSoaDnssecApiTest.php`: the four states (`off`, `pending`, `signed`, `unavailable`) with every response field, `last_signed` null when 0 and an ISO timestamp otherwise
- [x] T006 [P] [US1] Parsing of the real isp-test notes (research R4): DS with a digest the server wrapped across a line, `IN\tDS`, `;` comments ignored, KSK 257 → `ksk` and ZSK 256 → `zsk`, a PowerDNS `== Raw log ==` section ignored, and empty or unparsable notes yielding empty arrays without failing
- [x] T007 [P] [US1] Scoping: another client's zone 404, unknown id 404, no key 401, admin and reseller keys read their zones

### Implementation

- [x] T008 [US1] Add `app/Services/DnssecStatusService.php`: availability (mirror count on the zone's server), state derivation (data-model.md) and the DS/DNSKEY parser
- [x] T009 [US1] Add `dnssec()` to `app/Http/Controllers/Api/V1/DnsSoaController.php` and register `GET dns/soa/{dnsSoa}/dnssec` in `routes/api/dns.php` next to the zone routes

**Checkpoint**: US1 tests green

---

## Phase 3: User Story 3 — Not offered where ISPConfig cannot sign (P2)

### Tests (write first, must fail)

- [x] T010 [US3] Mirror rule on write: `dnssec_wanted: true` on a zone whose DNS server has a mirror → 422 with `errors.dnssec_wanted` and `error_types.dnssec_wanted` = `feature-not-allowed`, no journal entry; `false` and re-sending the stored value accepted; unmirrored zones unaffected; same outcome for client, reseller and admin keys

### Implementation

- [x] T011 [US3] Add the rule to `app/Http/Requests/DnsSoaRequest.php` `after()`, beside the `update_acl` and `origin` rules

**Checkpoint**: US3 tests green

---

## Phase 4: User Story 2 — Switching signing on and off (P1)

### Tests (write first, must fail)

- [x] T012 [US2] Guard the existing write path: a client key enabling and disabling `dnssec_wanted` journals the change and round-trips; `dnssec_algo` outside the two supported algorithms → 422; switching off leaves `dnssec_initialized` and the notes untouched (bind parity, research R5)

**Checkpoint**: no implementation needed — the write path already works (research R1); the test documents it

---

## Phase 5: User Story 4 — No key material or server logs for customers (P2)

### Tests (write first, must fail)

- [x] T013 [US4] `dnssec_info` is `null` for client and reseller keys on show and list, unchanged for administrator keys, while `dnssec_wanted`, `dnssec_algo`, `dnssec_initialized` and `dnssec_last_signed` stay visible to every key; the sub-resource response contains no path, command text or private key

### Implementation

- [x] T014 [US4] Mask `dnssec_info` for non-admin scopes in `app/Models/DnsSoa.php` serialization

**Checkpoint**: all user stories green

---

## Phase 6: Polish

- [x] T015 [P] Document the sub-resource, the mirror rule and the masking in the DNS paragraph of `README.md`
- [x] T016 Run Pint on the changed files and the full suite in Docker on PHP 8.3 (expect baseline 1186 + the new tests)

---

## Phase 7: Deployment & verification

- [x] T017 Deploy to isp-test (`ispconfig-rest update && ispconfig-rest status`) and confirm the running commit
- [x] T018 Run quickstart.md §2 with a temporary client: sign a zone, compare `ds_records` with the server's `dsset-` file and the DNSKEY files, check masking per key type, switch off and on again, and exercise the mirror rule with a temporary mirror row **restored byte-identically afterwards**; then clean up and verify no leftovers
- [x] T019 Record the verification results in this file and commit

## Results

Verified on isp-test (deployed commit `b2b2225`) on 2026-09-16 with two temporary clients (`QA032-…`, ids 38 and
39) and their own client-scoped keys. Everything created for the run was removed afterwards.

| # | Check | Result |
|---|---|---|
| 1 | The key really is client-scoped | `GET /me` → scope `client`, `client_id` 38 |
| 2 | Zone created with the spec 029 wizard | 201 (a zone needs records before ISPConfig will sign it) |
| 3 | Before enabling | `state` `off`, `available` true, both record lists empty |
| 4 | Enable with a client key | 200; read immediately → `state` `pending` |
| 5 | After the server signed | `state` `signed`, `initialized` true, `last_signed` `2026-09-16T02:02:02+02:00`, DS `key_tag` 38304 / `algorithm` 13 / `digest_type` 2, two DNSKEY records (`ksk` and `zsk`) |
| 6 | DS digest vs the server's `dsset-` file | **identical** after whitespace removal (SC-002) |
| 7 | KSK public key vs the `K….key` file | **identical** |
| 8 | Masking | client key: `dnssec_info` `null`; admin key: the raw notes; the client still sees `dnssec_wanted`, `dnssec_initialized` and `dnssec_algo` |
| 9 | Switch signing off | 200 → `state` `off` while `initialized` stays true and both key files remain on disk (bind parity, research R5) |
| 10 | Switch it back on | `state` `signed` with the **same DS digest** — the entry already given to a registrar stays valid (SC-005) |
| 11 | Another client's key | 404 |
| 12 | Mirrored DNS server (temporary row) | `state` `unavailable`, `available` false; enabling refused with 422 `feature-not-allowed` for the client key **and** for the admin key (legacy hides the block for every user type); disabling still 200 |
| 13 | Journal during the mirror checks | exactly one `dns_soa` entry — the deliberate *disable* call; both refusals wrote nothing |

**Cleanup**: the zone and both clients were deleted through the API; after the server finished processing, no
`qa032` rows, bind zone files, DNSSEC key files or `named.conf.local` references remained, the QA keys were deleted
by SQL, and the temporary mirror row was removed — the `server` table checksum is identical to before the run.
Keys 1, 2, 20, 27, 50 and clients C1, C2, WHMCS-2 are pre-existing and untouched.

**Note on the first attempt**: it aborted before any check because the client id was read from a `client_id` field
that `Client.yaml` does not expose (the id is `id`), which left `--client-id` empty and minted an admin key. Two
temporary clients were left behind and deleted immediately afterwards; the script now asserts the id and confirms
the key's scope through `/me` before it starts.
