---

description: "Task list for feature 013 — DNS & Mail Validation Hardening"
---

# Tasks: DNS & Mail Validation Hardening

**Input**: Design documents from `/specs/013-dns-mail-validation-hardening/`
**Prerequisites**: plan.md (required), spec.md (required — the validation-rules table is the implementation source of truth)

**Tests**: REQUIRED (constitution v2). This feature is validation-dominated: every rule ships with a valid/invalid matrix, the four incident payloads are the canonical invalid fixtures, and every 422 asserts (a) the error names the offending field and (b) no `sys_datalog` row was written. Run with `vendor/bin/phpunit`.

**Organization**: US1 = P1 BIND-safety per-field rules, US2 = P1 deactivation tolerance (cross-cutting, tested first-class), US3 = P2 3.3.1 parity (zone-level + SRV), US4 = P2 mail filter regex, US5 = P3 #6877 relay.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1..US5 (or FOUND)
- Include exact file paths.

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| Shared rdata validators (NEW) | `app/Http/Requests/Concerns/ValidatesDnsRdata.php` |
| DNS per-type rules | `app/Http/Requests/DnsRecordRequest.php` (`typeRules()`) |
| DNS zone-level checks | `app/Http/Requests/StoreDnsRecordRequest.php`, `app/Http/Requests/UpdateDnsRecordRequest.php` (`after()`) |
| Recompose guard | `app/Http/Controllers/Api/V1/DnsRecordController.php` (`update()`) |
| Mail filter rule | `app/Http/Requests/MailUserFilterRequest.php` (`commonRules()`) |
| Relay relaxation | `app/Http/Requests/StoreMailDomainRequest.php`, `app/Http/Requests/UpdateMailDomainRequest.php` |
| Contract edits (descriptions only) | `api/components/schemas/MailDomain.yaml`, `api/modules/mail/domains.yaml` (+ optional `DnsRecord.yaml`, `MailUserFilter.yaml`) |
| Tests (REQUIRED) | `tests/Feature/DnsRecordHardeningTest.php` (new), `tests/Feature/MailDomainApiTest.php` (extend), mail filter test (extend or new `tests/Feature/MailUserFilterHardeningTest.php`) |

---

## Phase 1: Setup (contract verification + clarifications)

- [x] T001 [FOUND] Verify contract compatibility: 422 declared on `api/modules/dns/records.yaml` POST/PUT (:139, :218) and on `api/modules/mail/user-filters.yaml` writes (:103, :194); confirm `DnsRecord.yaml` already documents `hash`/`digest` as "hex encoded" (:297-302, :521-525) and the delimited `regexp` example (:488-494). Record the verdict in spec.md if anything diverges. No YAML structure changes. **Verdict: compatible — nothing diverges, no spec.md change needed.**
- [x] T002 [FOUND] Resolve the NEEDS CLARIFICATION items with the owner before wiring the affected rules: (NC-1) DS `digest_type` — restrict to `in:1,2,4` vs keep 0–255 with hex-only for unknown types; (NC-2) adopt legacy CAA duplicate check (`dns_caa_edit.php:176-177`) or leave out; (NC-3) DMARC `fo`/`rf`/`ri` meta fields (additive `DnsRecord.yaml` change) in-scope or follow-up. **Owner decisions: NC-1 = restrict — DS digest_type `in:1,2,4` with exact 40/64/96 hex lengths, SSHFP hash_type `in:1,2` (40/64), TLSA matching_type 0-2 per spec (mt 0 = even-length hex only); NC-2 = ADOPT the CAA duplicate check; NC-3 = DEFERRED to a follow-up feature (no fo/rf/ri meta fields added, no DnsRecord.yaml change).**
- [x] T003 [P] [US5] Author the contract description edits for #6877: `api/components/schemas/MailDomain.yaml` — drop "Required if relay_host is set" (:94) and "Required if relay_host and relay_user are set" (:104), replace with "optional; leave empty when the relay host authorizes by IP (no SASL)"; `api/modules/mail/domains.yaml:76-77` — same rewording. (Spec-first: contract text lands before the rule change.)

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T004 [FOUND] Create `app/Http/Requests/Concerns/ValidatesDnsRdata.php` with closure-factory rules, each documented with its RFC/BIND citation from the spec table (the POSIX-ERE piece extracted to `Concerns/ValidatesPosixEre.php` so the mail filter (US4) shares it without pulling in DNS-named code):
  - `hexRule(?int $exactLength = null)` — `/^[0-9a-fA-F]+$/`, even length, optional exact length;
  - `base64Rule()` — non-empty, whitespace-tolerant base64 (`base64_decode(..., strict)` on whitespace-stripped value);
  - `naptrRegexpRule()` — empty OR `<delim><ERE><delim><repl><delim>[i]` per BIND `txt_valid_regex`: single-byte delimiter not in `[0-9\\i]`, exactly three unescaped occurrences, backrefs `\1`–`\9` only, and the ERE part must compile (POSIX-ERE check);
  - `noZoneBreakingCharsRule()` — rejects `"`, CR, LF (and lone backslash for CAA/HINFO values);
  - `locRule()` — RFC 1876 presentation grammar regex;
  - `posixEreRule()` — pattern compiles as POSIX ERE, rejects PCRE inline-flag constructs (shared with the mail filter, US4).
- [x] T005 [FOUND] Unit-test the trait in isolation (data providers per closure; include the four incident values as failing cases) — `tests/Unit/ValidatesDnsRdataTest.php` (create `tests/Unit/` if absent; wire into `phpunit.xml` if needed).
- [x] T006 [FOUND] Scaffold `tests/Feature/DnsRecordHardeningTest.php` with the shared fixtures: a helper that seeds a zone + raw `dns_rr` rows (bypassing the API) for the four incident payloads — DS `2371 13 2 <base64>`, TLSA `3 1 1 somehashstring`, SSHFP `4 2 fingerprinthash`, NAPTR `100 "u" "E2U+sip" "sip:info@example.com" .` — reused by US1/US2/US3 suites.

**Checkpoint**: shared validators proven; fixtures ready — story phases can begin.

---

## Phase 3: User Story 1 - BIND-safety per-field rules (Priority: P1) 🎯 MVP

**Goal**: the four incident record classes (and the audited quoted-string/LOC/DNSKEY gaps) are rejected with 422 naming the field, on create and on update-when-submitted.

**Independent Test**: replay the four incident payloads against `POST /dns/records` → four 422s naming `digest`/`hash`/`hash`/`regexp`; corrected payloads → 201 with byte-exact `data`.

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T007 [P] [US1] In `tests/Feature/DnsRecordHardeningTest.php`: DS/TLSA/SSHFP matrices (invalid: incident payloads, odd-length hex, wrong length for digest_type/matching_type/hash_type; valid: 40/64/96- and 64/128- and 40/64-hex variants) — assert 422 error key + no datalog row / 201 + composed `data` byte-compat (FR-002..FR-004).
- [x] T008 [P] [US1] Same file: NAPTR matrix (undelimited regexp, mismatched delimiters, both regexp+replacement set, flag `U` without regexp, flag `S` with regexp; valid: empty regexp + replacement, `!^.*$!sip:info@example.com!`, trailing-`i` variant) (FR-006/FR-007) and DNSKEY matrix (free text, protocol≠3, broken base64; valid `257 3 13 <base64>`) (FR-005).
- [x] T009 [P] [US1] Same file: TXT/DKIM embedded `"`/newline → 422; CAA `ca_issuer`/`additional` and HINFO `cpu`/`os` with `"`/`\`/CR-LF → 422; clean values → 2xx with stored `data` identical to pre-feature compose (FR-008/FR-009); LOC matrix (valid RFC 1876 strings vs garbage) (FR-010).

### Implementation for User Story 1

- [x] T010 [US1] Wire `ValidatesDnsRdata` into `app/Http/Requests/DnsRecordRequest.php::typeRules()`: DS `digest` (hex + length-by-`digest_type`, sibling resolved request→stored meta), TLSA `hash` (by `matching_type`), SSHFP `hash` (by `hash_type`), DNSKEY `data` (structure + base64), NAPTR `regexp` (grammar) + `service` charset + regexp⊕replacement + flag-dependent closures, TXT/DKIM `data` no-quote/CR-LF (after `stripAccidentalQuotes` semantics — validate the post-strip value), CAA `ca_issuer`/`additional` + HINFO `cpu`/`os` no-breaking-chars (+ CAA issuer/iodef-URL shapes per FR-009), LOC `data` grammar. Preserve `$req` (`required`/`sometimes`) semantics untouched.
- [x] T011 [US1] Run T007–T009 green; run the full existing `tests/Feature/DnsRecordApiTest.php` to prove no valid-input regression (byte-compat of composed `data`).
- [ ] T012 [US1] Manual gate (SC-003): render every valid-matrix row through the zone-file shapes of `source_code/server/conf/bind_pri.domain.master` and run `named-checkzone` (or `docker run` a bind9 image) against the assembled test zone; attach the command + result to the PR description. **NOT RUN in this environment: no `named-checkzone` binary installed and the Docker daemon is not running — remains a manual pre-merge step (suggested: `docker run --rm -v $PWD/zone:/z internetsystemsconsortium/bind9:9.18 named-checkzone example.com. /z/example.com.zone`).**

**Checkpoint**: the incident cannot recur through the API.

---

## Phase 4: User Story 2 - Deactivation tolerance for existing garbage (Priority: P1, cross-cutting)

**Goal**: `PUT {"active": false}` always works on stored-garbage records; updates never rewrite `data` they cannot parse.

**Independent Test**: raw-seed the four incident rows; deactivate each via the API → 200, `data` byte-identical, serial bumped.

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T013 [P] [US2] In `tests/Feature/DnsRecordHardeningTest.php`: for each raw-seeded incident row — `PUT {"active": false}` → 200 + `active=n` + `data`/`aux` byte-identical + `dns_soa` serial bump (FR-012, SC-002); `PUT` with a still-bad submitted field (e.g. `digest`) → 422; `PUT` with `type` change → new type's strict rules (existing `isTypeChange()` path).
- [x] T014 [P] [US2] Same file: seed a structured record with unparseable `data` (e.g. SRV `data = "5060"`), `PUT {"ttl": 600}` → 200 and `data` still `"5060"` — pins FR-013 against the recompose rewrite at `app/Http/Controllers/Api/V1/DnsRecordController.php:174-181`.

### Implementation for User Story 2

- [x] T015 [US2] Add the recompose guard to `app/Http/Controllers/Api/V1/DnsRecordController.php::update()`: when the effective type is structured, `meta($stored)` is empty, and the request contains none of that type's meta fields → carry `aux`/`data` over verbatim (skip `compose()`). Add a small `dataFieldsFor(string $type)` helper (or const map) shared with T017's gating — natural home: `DnsRecordMetaService` or the request base class.
- [x] T016 [US2] Run T013–T014 green; re-run the US1 suite (tolerance must not weaken create-path strictness).

**Checkpoint**: hardening cannot trap operators — incident recovery flow pinned by tests.

---

## Phase 5: User Story 3 - 3.3.1 parity: zone-level checks + SRV (Priority: P2)

**Goal**: CNAME conflict (both directions), CNAME apex, CNAME target existence, A/AAAA/ALIAS duplicates, DMARC prerequisites, SRV target length — matching legacy file:line behavior.

**Independent Test**: `www CNAME x` then `POST type=A name=www` → 422; and the inverse; `POST type=CNAME name=@` → 422.

### Tests for User Story 3 (REQUIRED) ⚠️

- [x] T017 [P] [US3] In `tests/Feature/DnsRecordHardeningTest.php`: CNAME-conflict matrix across the three legacy name spellings (relative, `name.<origin>`, stripped — `dns_edit_base.php:46`), both directions, including inactive-CNAME-still-blocks and self-exclusion on update; apex variants (``/`@`/origin/origin-no-dot post-`prepareForValidation`, `dns_cname_edit.php:61-65`); CNAME `data='@'`→origin and relative-target-must-exist (`:67-84`) (FR-014..FR-016).
- [x] T018 [P] [US3] Same file: A/AAAA/ALIAS duplicate matrix — identical A (name+data) 422, second A with different data 201, ALIAS-at-name blocks A (`dns_a_edit.php:48-53`, `dns_aaaa_edit.php:48`, `dns_alias_edit.php:47`) (FR-017); SRV hostname 65..255 chars now accepted, 256 rejected (`dns_srv_edit.php:88`) (FR-018); DMARC without DKIM → 422, without SPF → 422, with two SPF → 422, with one of each → 201 (`dns_dmarc_edit.php:229-251`) (FR-020).

### Implementation for User Story 3

- [x] T019 [US3] Implement the zone-level checks as `after()` hooks: full set in `app/Http/Requests/StoreDnsRecordRequest.php`; in `app/Http/Requests/UpdateDnsRecordRequest.php` gated on `hasAny(['name','type','zone', ...dataFieldsFor($type)])` (FR-012 tolerance). Port the legacy SQL semantics (three spellings, zone-scoped, `id != self`, no `active` predicate) as Eloquent/DB reads against `dns_rr` + `dns_soa.origin`.
- [x] T020 [US3] Widen the SRV `hostname` rule in `app/Http/Requests/DnsRecordRequest.php:198` to `/^[a-zA-Z0-9\.\-\_]{1,255}$/` + `max:255`.
- [x] T021 [US3] Run T017–T018 green; full DNS suite green.

**Checkpoint**: API-created records can no longer produce the RFC-violating states 3.3.1 prevents.

---

## Phase 6: User Story 4 - Mail filter regex op safety (Priority: P2)

**Goal**: `op=regex` searchterms must compile as POSIX ERE (documented stricter-than-legacy; no compile check exists in 3.3.1p1 — `mail_user_filter.tform.php:99-113`).

**Independent Test**: `POST /mail/users/{id}/filters` with `op=regex, searchterm="["` → 422; `searchterm="^\[SPAM\]"` → 201.

### Tests for User Story 4 (REQUIRED) ⚠️

- [x] T022 [P] [US4] Extend the mail filter feature test (or new `tests/Feature/MailUserFilterHardeningTest.php`): non-compiling patterns (`[`, `(`, `a{2,1}`) → 422 naming `searchterm`; PCRE inline flag `(?i)x` → 422; valid ERE → 201 with the rendered `custom_mailfilter` block unchanged from current output; other ops with metacharacters → 201; update submitting only `active:false` on a filter with a stored bad pattern → 200 (tolerance); update changing `op` to `regex` while stored searchterm is bad → 422 (FR-019).

### Implementation for User Story 4

- [x] T023 [US4] Add the `posixEreRule()` closure (from `ValidatesDnsRdata` — or extract the shared piece to `app/Http/Requests/Concerns/` if naming warrants) to `searchterm` in `app/Http/Requests/MailUserFilterRequest.php::commonRules()`, active only when the effective `op` (submitted, else the bound filter's stored op) is `regex`.
- [ ] T024 [US4] Optional (with T001's verdict): add a `searchterm` description note for `op=regex` (POSIX ERE, no inline flags) to `api/components/schemas/MailUserFilter.yaml:53-58` — description-only. **SKIPPED per owner instruction: api/ edits for this feature are limited to the two #6877 relay description changes (T003); this optional enrichment is left for a follow-up.**

**Checkpoint**: a bad pattern can no longer disable a mailbox's whole sieve script.

---

## Phase 7: User Story 5 - #6877 per-domain relay without auth (Priority: P3)

**Goal**: `relay_host` accepted without credentials; contract text matches.

**Independent Test**: `POST /mail/domains` with `relay_host` only → 201 (today 422).

### Tests for User Story 5 (REQUIRED) ⚠️

- [x] T025 [P] [US5] Extend `tests/Feature/MailDomainApiTest.php`: relay matrix — host-only → 201 (stored `relay_user`/`relay_pass` = `''`), host+user-no-pass → 201, user-only → 201, all empty → 201, `PUT` setting host-only → 200, `PUT` with explicit `relay_user: ""` clears the stored value (documented deviation from `mail_domain_edit.php:315-317`) (FR-021).

### Implementation for User Story 5

- [x] T026 [US5] Remove the `required_with` chains: `app/Http/Requests/StoreMailDomainRequest.php:55-56` and `app/Http/Requests/UpdateMailDomainRequest.php:62-63` → `['sometimes', 'nullable', 'string', 'max:255']` each. (Contract text already updated in T003.)
- [x] T027 [US5] Verify Swagger UI (`/api/documentation`) renders the updated relay descriptions; mail domain suite green.

**Checkpoint**: all five stories independently shippable.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T028 [P] Optional description enrichment in `api/components/schemas/DnsRecord.yaml` (per T001): note deterministic digest/hash lengths and the NAPTR regexp grammar in the `digest`/`hash`/`regexp` descriptions — descriptions only, no structural diff (SC-007 gate). **SKIPPED per owner instruction: api/ edits for this feature are limited to the two #6877 relay description changes (T003); the existing "hex encoded" / delimited-regexp texts already match the enforced rules. NC-3 (DMARC fo/rf/ri meta fields) is likewise DEFERRED — no additive DnsRecord.yaml change in this feature.**
- [x] T029 Re-verify legacy parity for the documented cases: diff each FR-014..FR-018/FR-020 test expectation against the cited legacy lines once more; confirm the intentional-deviation list in spec.md is complete (admin relaxation, regex compile, relay clear-semantics, CAA free-form name).
- [x] T030 Code cleanup: closures shared not duplicated (one trait), controllers thin (recompose guard is the only controller change), no ad-hoc regex literals outside the trait/`typeRules()`.
- [x] T031 Run `vendor/bin/phpunit` — full suite green; confirm no OpenAPI structural diff (`git diff api/` shows description-only changes).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none — T002's clarifications gate only the specific rules they touch (DS digest_type restriction, CAA duplicate check, DMARC fo/rf/ri).
- **Foundational (Phase 2)**: blocks all stories (T004 trait is used by US1/US2/US4; T006 fixtures by US1/US2/US3).
- **US1 (Phase 3)** → **US2 (Phase 4)**: US2's tests exercise rules US1 installs; run in this order.
- **US3 (Phase 5)**, **US4 (Phase 6)**, **US5 (Phase 7)**: independent of each other; US3 depends on Phase 2 fixtures, US4 on the trait, US5 only on T003.
- **Polish (Phase 8)**: after all desired stories.

### Within Each User Story

- Tests written first and failing before implementation.
- Contract text (T003/T024/T028) before or with the rule change it describes (Principle I).
- Swagger verification is the story's last task where contract text changed (US5).

### Parallel Opportunities

- T003 ∥ T004–T006; T007–T009 ∥ each other; T013–T014 ∥; T017–T018 ∥; T022 ∥ T025.
- US3, US4, US5 can proceed in parallel once Phase 2 lands (different files; no shared edits — `DnsRecordRequest.php` is touched by US1 (T010) and US3 (T020): sequence those two edits).

---

## Implementation Strategy

### MVP First (US1 + US2 only)

1. Phase 1–2 (contract verdict, clarifications, trait + fixtures)
2. Phase 3 (per-field rules) — **the incident classes are now rejected**
3. Phase 4 (tolerance) — **and recovery is never blocked**
4. STOP and VALIDATE: replay the four incident payloads via Swagger UI "Try it out"; run the named-checkzone gate (T012)
5. Ship; US3/US4/US5 follow incrementally

### Incremental Delivery

- Each story only adds/removes validation rules on existing endpoints — no cross-story API surface coupling; the full existing test suite is the regression net at every checkpoint.

---

## Notes

- [P] tasks = different files, no dependencies; T010 and T020 both edit `app/Http/Requests/DnsRecordRequest.php` — never parallel.
- Every rejection must be proven datalog-silent (assert `sys_datalog` row count unchanged) — validation runs before `BaseModel::save()`, so a failure here means a rule landed in the wrong layer.
- `DnsRecordMetaService` must remain validation-free: reads and recompose must keep tolerating stored garbage (US2). Any temptation to validate in `compose()` violates the tolerance FRs.
- NC-1..NC-3 (T002) block only their specific rules: implement DS length checks for types 1/2/4 regardless; the open question is only the treatment of *other* `digest_type` values.
- Commit after each task or logical group.
