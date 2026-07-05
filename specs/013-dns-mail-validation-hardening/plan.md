# Implementation Plan: DNS & Mail Validation Hardening

**Branch**: `013-dns-mail-validation-hardening` | **Date**: 2026-07-05 | **Spec**: `specs/013-dns-mail-validation-hardening/spec.md`
**Input**: Feature specification from `/specs/013-dns-mail-validation-hardening/spec.md`

## Summary

Validation-only feature (plus one relaxation): (P1) reject on create/update any DNS record whose composed `data` would make BIND refuse the zone — DS/TLSA/SSHFP hex with per-digest-type lengths, DNSKEY structure+base64, NAPTR delimited-regexp with flag semantics, embedded-quote/CR-LF rejection for TXT/DKIM/CAA/HINFO, LOC grammar; (P2) port the 3.3.1p1 zone-level checks our 3.3.0p1-era port lacks (CNAME conflict/apex/target, A-AAAA-ALIAS duplicates, SRV target length, DMARC prerequisites) plus a POSIX-ERE compile check for `op=regex` mail filters; (P3) #6877 — drop the `required_with` relay credential chain on mail domains. All rules attach to *submitted fields only* so `{"active": false}` always deactivates a legacy-garbage record (the incident recovery flow), and the update recompose path is fixed to never rewrite stored `data` it cannot parse. No endpoint, schema-structure or status-code changes; two description-only contract edits (relay).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker
**Storage**: MySQL — ISPConfig's `dbispconfig` (schema owned by ISPConfig; all writes via `sys_datalog`). This feature adds read-only `dns_rr`/`dns_soa` lookups inside validation.
**Testing**: PHPUnit feature tests (`tests/Feature/`), per constitution v2 — this feature is dominated by validation matrices seeded from the four real incident payloads
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: N/A (validation closures + up to 2 extra indexed SELECTs per DNS write for zone-level checks)
**Constraints**: async write semantics via `sys_datalog`; behavioral parity with `source_code/interface/web/{dns,mail}/` **except the documented stricter-than-legacy deviations** (spec: Parity section); stored-`data` byte-compatibility for all currently-valid input
**Scale/Scope**: 6 existing endpoints touched (dns/records POST+PUT, mail user-filters POST+PUT, mail/domains POST+PUT); 1 new Concern (shared rdata validators); ~22 FRs; 0 new endpoints

## Constitution Check

- [x] **Spec-first (I)**: no path/method/status changes; `api/modules/dns/records.yaml` + `mail/user-filters.yaml` already declare 422 on POST/PUT (records.yaml:139,218); the only contract edits are description texts (`MailDomain.yaml:94,104`, `domains.yaml:76-77`) authored *before* the request changes (T003)
- [x] **Datalog-only writes (II)**: feature writes nothing new; validation runs before `BaseModel::save()`, so rejected requests produce zero datalog rows (asserted in tests)
- [x] **Legacy parity (III)**: every P2 rule cites its legacy file:line in the spec table; P1/regex-compile/relay-clear deviations are enumerated under "Intentional deviations"
- [x] **Route discipline (IV)**: no route changes
- [x] **HTTP contract (V)**: all rejections are 422 RFC 9457 problem+json with per-field `errors` (existing FormRequest → problem+json pipeline); success codes unchanged
- [x] **No schema changes**: no migrations

## Project Structure

### Documentation (this feature)

```text
specs/013-dns-mail-validation-hardening/
├── spec.md              # Feature spec (validation-rules table = source of truth)
├── plan.md              # This file
└── tasks.md             # Task list
```

(No separate research.md/data-model.md — the legacy research is embedded in spec.md's table with file:line citations; no new entities exist.)

### Source Code (repository root)

```text
api/
├── components/schemas/MailDomain.yaml        # relay_user/relay_pass description tweak ONLY (#6877)
├── components/schemas/DnsRecord.yaml         # optional: enrich hash/digest/regexp descriptions (no structure)
├── components/schemas/MailUserFilter.yaml    # optional: searchterm POSIX-ERE note for op=regex
└── modules/mail/domains.yaml                 # POST description lines 76-77 relay wording

app/
├── Http/Requests/
│   ├── Concerns/ValidatesDnsRdata.php        # NEW: shared closure factories — hexRule(), base64Rule(),
│   │                                         #      naptrRegexpRule(), noZoneBreakingCharsRule(), locRule()
│   ├── DnsRecordRequest.php                  # typeRules(): attach new per-field rules (DS/TLSA/SSHFP/
│   │                                         #   DNSKEY/NAPTR/TXT/CAA/HINFO/LOC/SRV); keep $req semantics
│   ├── StoreDnsRecordRequest.php             # after(): zone-level checks (CNAME conflict/apex/target,
│   │                                         #   A-AAAA-ALIAS duplicates, DMARC prerequisites)
│   ├── UpdateDnsRecordRequest.php            # after(): same checks, only when name/type/zone/data-affecting
│   │                                         #   fields are submitted (tolerance)
│   ├── MailUserFilterRequest.php             # commonRules(): POSIX-ERE compile rule when op=regex
│   ├── StoreMailDomainRequest.php            # drop relay required_with chain (FR-021)
│   └── UpdateMailDomainRequest.php           # drop relay required_with chain (FR-021)
├── Http/Controllers/Api/V1/DnsRecordController.php
│                                             # update(): FR-013 — skip recompose when stored data
│                                             #   unparseable AND no meta fields submitted
└── Services/DnsRecordMetaService.php         # NO validation added here (see Structure Decision);
                                              #   optional: expose parses() helper for FR-013

tests/Feature/
├── DnsRecordHardeningTest.php                # NEW: per-type valid/invalid matrices + incident payloads
│                                             #   + deactivation tolerance + CNAME/DMARC zone-level
├── MailUserFilterApiTest.php (extend or NEW MailUserFilterHardeningTest.php)  # regex-op matrix
└── MailDomainApiTest.php                     # extend: relay_host-only 201 (was 422)
```

**Structure Decision — where each rule lands (single source of truth preserved)**:

| Rule class | Lands in | Why |
|------------|----------|-----|
| Per-field format rules (hex/length, base64, NAPTR regexp grammar, quote/CR-LF bans, LOC, SRV length) | `DnsRecordRequest::typeRules()` via closures from the new `Concerns/ValidatesDnsRdata` trait | typeRules() is already the one map "type → field rules" used by both Store and Update with `$req = required|sometimes` — adding rules there automatically inherits create-strict/update-tolerant semantics (FR-012). `DnsRecordMetaService` stays a pure composer/parser: it must keep accepting stored garbage for reads and recompose (US2), so it must NOT validate. |
| Cross-field rules within one record (NAPTR regexp XOR replacement, flag-dependent constraints; DS/TLSA/SSHFP digest-length depending on `*_type`) | closures in `typeRules()` reading the *effective* sibling value: submitted value, else the stored record's decomposed meta (`currentRecord()` already exists in `DnsRecordRequest`) | keeps rules per-field (error names the field) while honoring partial updates: submitting only `digest_type=2` against a stored 40-char digest must 422 on `digest_type`, not pass. |
| Zone-level checks (CNAME conflict both directions, apex, target existence, A/AAAA/ALIAS duplicates, DMARC DKIM/SPF prerequisites) | `after()` hooks on `StoreDnsRecordRequest` / `UpdateDnsRecordRequest` (pattern precedent: `DnsSoaRequest::after()` slave-collision check) | needs DB queries + the post-`prepareForValidation` name (after `@`/`*` rewrite); update variant runs only when `name`, `type`, `zone` or the type's data fields are submitted — an `{"active": false}` body skips them entirely (FR-012). |
| Unparseable-stored-data preservation (FR-013) | `DnsRecordController::update()` recompose guard | it is compose-flow logic, not validation; guard: if `meta($stored)` is empty for a structured type and the request contains none of that type's meta fields → keep stored `aux`/`data` verbatim. |
| Mail filter POSIX-ERE compile (FR-019) | `MailUserFilterRequest::commonRules()` — closure on `searchterm` active only when effective `op` (submitted, else stored) is `regex` | same shared-abstract-request pattern as DNS; Update inherits tolerance because the closure only fires when `searchterm`/`op` are in play. |
| Relay relaxation (FR-021) | `StoreMailDomainRequest` / `UpdateMailDomainRequest` rule arrays | pure rule deletion; `MailDomainRequest::payload()` already maps null→`''` (:84-88). |

## Legacy Research (Phase 0 focus)

Complete — captured in spec.md's validation table with file:line citations. Key findings that drove the design:

- **DS/TLSA/SSHFP**: legacy validators cannot catch the incident payloads (`dns_ds.tform.php:105` TODO + `:108-114`; `dns_tlsa.tform.php:107-109`; `dns_sshfp.tform.php:108-113`); admin sessions get even weaker NOTEMPTY-only validators (`dns_ds.tform.php:160-165`) — deliberately not mirrored.
- **CNAME conflict** lives in the shared edit base, not tforms: `dns_edit_base.php:43-49` (`checkDuplicate()`, three name spellings via SQL `replace`/`concat`, zone-scoped, id-excluded, **no `active` filter**) invoked at `:130`; per-type overrides in `dns_cname_edit.php:48-54`, `dns_a_edit.php:48-53`, `dns_aaaa_edit.php:48`, `dns_alias_edit.php:47`, `dns_dname_edit.php:48`.
- **BIND emission**: `server/conf/bind_pri.domain.master` writes `data` raw for every type and wraps TXT in literal quotes (:63); `bind_plugin.inc.php:313-315` chunk-splits >255-char TXT — hence the embedded-quote/CR-LF bans (FR-008/FR-009) and why validation (not escaping) is the right fix: escaping would silently alter stored semantics vs legacy.
- **Mail filter**: 3.3.1p1 has NO regex compile check (`mail_user_filter.tform.php:99-113`, `mail_user_filter_edit.php` whole file, `mail_user_filter_plugin.inc.php:162-171`) — FR-019 is a documented deviation.
- **#6877**: relay fields validator-free (`mail_domain.tform.php:144-167`); Postfix map needs only `relay_host != ''` (`install/tpl/mysql-virtual_sender-relayhost.cf.master`); update-time restore-if-empty (`mail_domain_edit.php:315-317`) intentionally not mirrored (API: omission preserves, `""` clears).

## Update-Tolerance Mechanism (FR-012/FR-013)

1. **Field rules**: `UpdateDnsRecordRequest` already calls `typeRules($type, strict: $this->isTypeChange())` → every per-type rule is `sometimes` unless the type changes. New closures are appended to the same per-field rule arrays, so they run **only when the field is present in the request**. Laravel's `sometimes` guarantees a `{"active": false}` body triggers zero type-specific rules.
2. **Cross-field closures** resolve the sibling operand as: request input → stored decomposed meta → default. They attach to the *submitted* field, so untouched stored garbage is never re-validated.
3. **Zone-level `after()` checks** in the Update request are gated on `$this->hasAny(['name', 'type', 'zone', ...dataFieldsFor($type)])` — pure-flag updates skip them.
4. **Recompose guard** (controller): for structured types, when `meta($stored)` returns `[]` (unparseable) and the request supplies no meta fields for the type, `aux`/`data` are carried over verbatim instead of composed — otherwise today's code silently rewrites e.g. a 2-token SRV to `"0 0 ."` on deactivation.
5. **Regression pin**: a test seeds all four incident rows raw into `dns_rr` and asserts `PUT {"active": false}` → 200 with byte-identical `data` (SC-002).

## Test Strategy

- **Per-type valid/invalid matrices** (`tests/Feature/DnsRecordHardeningTest.php`), seeded from the four real incident payloads as the canonical invalid rows:
  - DS: invalid = base64 digest (`+`/`/`/`=` chars), odd-length hex, 63-char hex with `digest_type=2`; valid = 40/64/96-hex for types 1/2/4.
  - TLSA: invalid = `somehashstring`, 63-hex with mt=1; valid = 64-hex mt=1, 128-hex mt=2, arbitrary even-hex mt=0.
  - SSHFP: invalid = `fingerprinthash`; valid = 40-hex ht=1, 64-hex ht=2.
  - NAPTR: invalid = `regexp="sip:info@example.com"`, mismatched delimiters, both regexp+replacement, flag=U with empty regexp; valid = empty regexp + replacement, `!^.*$!sip:info@example.com!` (+`i` variant), flag=S with replacement.
  - DNSKEY: invalid = free text, protocol≠3, broken base64; valid = `257 3 13 <base64>`.
  - TXT/CAA/HINFO: embedded `"` and `\n` payloads → 422; clean values → 2xx byte-compat asserted against current compose output.
  - Every 422 asserts the error key names the offending field and that no `sys_datalog` row exists; every 2xx asserts composed `data` unchanged from pre-feature behavior (byte-compat).
- **Tolerance suite**: raw-seeded garbage rows × `PUT {"active": false}` → 200 + data preserved; garbage row + submitted bad field → 422; garbage row + type change → full strict rules.
- **Zone-level suite**: CNAME conflict in both directions across the three name spellings (relative, FQDN-with-origin, stripped), apex variants (``, `@`, origin, origin-no-dot), CNAME relative-target existence, A duplicate (same data) vs allowed second A (different data), DMARC without DKIM / without SPF / with two SPF.
- **Mail suites**: filter regex matrix (compiling ERE 201, `[`/`(?i)` 422, other ops with metachars 201, deactivate-with-stored-bad-pattern 200) in the filter test; relay matrix (host-only 201, host+user 201, user-only 201, all-empty 201, explicit `""` clears) extending `tests/Feature/MailDomainApiTest.php`.
- **Manual gate (SC-003)**: render one test zone containing every valid-matrix row through the legacy template shape and run `named-checkzone` — documented as a verification step in tasks, not CI.

## Complexity Tracking

> No constitution violations. Zone-level validation queries read `dns_rr`/`dns_soa` directly from Form Request `after()` hooks — reads are unrestricted (precedent: `DnsSoaRequest::after()` slave-collision check), so no exception is needed.
