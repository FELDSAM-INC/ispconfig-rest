# Feature Specification: DNS & Mail Validation Hardening

**Feature Branch**: `013-dns-mail-validation-hardening`
**Created**: 2026-07-05
**Status**: Draft
**Module**: dns + mail
**Input**: User description: "Harden DNS record and mail validation so that no API-accepted value can take a BIND zone offline (incident 2026-07-05: test.cz failed to load over four hand-entered records — a DS with a base64 DNSKEY in its hex digest field, a TLSA with literal 'somehashstring', an SSHFP with 'fingerprinthash', and a NAPTR whose regexp was not a delimited substitution expression). Additionally close the validation gaps between our 3.3.0p1-era port and the now-vendored ISPConfig 3.3.1p1 (CNAME conflict detection, SRV/DMARC/CAA improvements, mail filter regex op), and adopt the #6877 per-domain relay change (relay_host without relay_user/relay_pass)."

> **Incident context (motivating P1)**: one syntactically bad record makes BIND refuse the **whole zone** (`named-checkzone` → "bad hex encoding" / "syntax error" → zone not loaded → every record of the domain offline). Legacy 3.3.1p1 validates almost none of this — `source_code/interface/web/dns/form/dns_ds.tform.php:105` literally carries `//TODO Regex validation does not take place obviously - why ever...`, the TLSA data validator (`dns_tlsa.tform.php:105-110`) accepts any `[a-zA-Z0-9]*` "hash", and SSHFP (`dns_sshfp.tform.php:108-113`) is NOTEMPTY-only. **All four incident payloads pass our current Form Requests too** (`app/Http/Requests/DnsRecordRequest.php:207,212,253-255,266`). P1 is therefore an **intentional, documented deviation from legacy**: we validate *stricter* than ISPConfig, with BIND's own acceptance rules as ground truth. The recovery path that fixed the incident — `PUT {"active": false}` on the bad record without touching its other fields — is protected as an explicit requirement (FR-012/FR-013).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - BIND-safety validation: a record the API accepts never breaks the zone (Priority: P1)

An API consumer (control panel, ACME/DANE automation, DNSSEC tooling) creates and updates DNS records through `POST/PUT /api/v1/dns/records`. Whenever the composed `data` of the record would make BIND's zone parser refuse the zone file, the request is rejected with **422 problem+json naming the offending field** — instead of being journaled to `sys_datalog`, rendered into the zone file, and taking the entire zone offline. Covered types: DS, TLSA, SSHFP, DNSKEY, NAPTR (the incident classes), plus the quoted-string types TXT/DKIM/CAA/HINFO and LOC (audit findings, see FR-008..FR-010).

**Why this priority**: This is the incident. One malformed record = whole zone dark = all services of that domain (web, mail, everything) unresolvable. Legacy offers no protection; the API is the only place the check can live before the datalog fans the record out to the DNS servers.

**Independent Test**: Replay the four incident payloads against `POST /dns/records` (valid zone, valid `X-API-Key`) and verify each returns 422 with an `errors` key naming `digest` / `hash` / `hash` / `regexp` respectively and **no** `sys_datalog` row; then submit the corrected well-formed variants and verify 201 with byte-exact composed `data`.

**Acceptance Scenarios**:

1. **Given** a zone, **When** `POST /dns/records` with `type=DS, key_tag=2371, algorithm=13, digest_type=2` and a `digest` containing base64 characters (`+`, `/`, `=`) or any non-hex character or an odd/wrong length, **Then** 422 with `errors.digest` (BIND: "bad hex encoding"); **When** `digest` is 64 hex chars, **Then** 201 and `data = "2371 13 2 <digest>"`.
2. **Given** a zone, **When** `POST /dns/records` with `type=TLSA, matching_type=1, hash="somehashstring"`, **Then** 422 with `errors.hash`; **When** `hash` is 64 hex chars, **Then** 201.
3. **Given** a zone, **When** `POST /dns/records` with `type=SSHFP, hash_type=1, hash="fingerprinthash"`, **Then** 422 with `errors.hash`; **When** `hash` is 40 hex chars, **Then** 201.
4. **Given** a zone, **When** `POST /dns/records` with `type=NAPTR` and `regexp="sip:info@example.com"` (no delimiters), **Then** 422 with `errors.regexp` (BIND: "syntax error"); **When** `regexp="!^.*$!sip:info@example.com!"`, **Then** 201.
5. **Given** a zone, **When** `POST /dns/records` with `type=DNSKEY` and `data` that is not `<flags> 3 <algorithm> <base64>`, **Then** 422 with `errors.data`.
6. **Given** a zone, **When** `POST /dns/records` with `type=TXT` and `data` containing an unescaped `"` or a raw newline, **Then** 422 with `errors.data` (the BIND template wraps TXT data in literal quotes — `source_code/server/conf/bind_pri.domain.master:63` — an interior quote unbalances the zone file).
7. **Given** a zone, **When** `PUT /dns/records/{id}` submits a malformed meta field (same payloads as above), **Then** 422 — update is validated with the same per-field rules as create.
8. **Given** any of the rejections above, **Then** the response is RFC 9457 `application/problem+json`, status 422, and no `dns_rr` or `dns_soa` datalog row is written.

---

### User Story 2 - Deactivate-to-recover: updates never blocked by garbage in untouched fields (Priority: P1, cross-cutting)

An operator discovers a zone-breaking record that predates this feature (hand-entered via legacy, or created through the API before hardening). The recovery flow is `PUT /dns/records/{id}` with body `{"active": false}` — exactly what resolved the 2026-07-05 incident. This must **always** succeed: new validation applies only to fields present in the request, never to stored values of untouched fields, and the update path must not corrupt stored `data` it cannot parse.

**Why this priority**: Without it, the hardening would trap operators: the very records the feature exists to prevent would become un-deactivatable (every update 422s on the stored garbage), forcing raw SQL surgery during an outage.

**Independent Test**: Seed the four incident rows directly into `dns_rr` (bypassing the API, as legacy did), then `PUT /dns/records/{id}` with `{"active": false}` for each → 200, `active=n`, stored `aux`/`data` byte-identical, zone serial bumped.

**Acceptance Scenarios**:

1. **Given** a stored DS row whose digest is base64 garbage, **When** `PUT {"active": false}`, **Then** 200, the record is deactivated, and `data` is unchanged byte-for-byte.
2. **Given** a stored structured record whose `data` does not decompose (e.g. an SRV with fewer than 3 tokens), **When** `PUT` submits none of that type's meta fields, **Then** stored `aux`/`data` are preserved verbatim (the update MUST NOT re-compose from empty meta and silently rewrite `data` — see FR-013; this is a latent defect in the current recompose path `app/Http/Controllers/Api/V1/DnsRecordController.php:174-181`).
3. **Given** a stored garbage record, **When** `PUT` submits a *changed* value for a hardened field (e.g. a new `digest`), **Then** that submitted value IS validated (422 if still malformed).
4. **Given** a stored garbage record, **When** `PUT` changes the record `type`, **Then** the new type's full strict rule set applies (existing `isTypeChange()` semantics, `app/Http/Requests/UpdateDnsRecordRequest.php:46-63`).

---

### User Story 3 - 3.3.1 validation parity for DNS records (Priority: P2)

A consumer's writes are checked the same way ISPConfig 3.3.1p1's forms check them, so records created via the API behave identically to panel-created ones and cannot produce RFC-violating zone states legacy now prevents: CNAME conflict detection (a CNAME cannot coexist with any other record at the same name — RFC 1034), CNAME-at-apex prohibition (RFC 1912), CNAME relative-target existence, A/AAAA/ALIAS duplicate detection, and the SRV field checks 3.3.1 added.

**Why this priority**: Parity is a constitutional principle (III). These are correctness rules that BIND partially tolerates (a CNAME+A conflict is a `named-checkzone` **error** too) but that primarily protect resolution semantics; less catastrophic than P1 (most produce per-record brokenness or checkzone warnings, not always a dead zone).

**Independent Test**: Create `www CNAME target` in a zone, then `POST /dns/records` with `type=A, name=www` → 422 ("CNAME conflict"); create `mail A 192.0.2.1`, then `POST type=CNAME, name=mail` → 422; `POST type=CNAME, name=@` → 422 (apex).

**Acceptance Scenarios**:

1. **Given** an active CNAME at `www` in zone Z, **When** any other record is created/renamed to `www` in Z, **Then** 422 (legacy `dns_edit_base.php:43-49` + `:130`, name matched in all three forms: as-sent, with `.<origin>` suffix stripped, and with `.<origin>` appended).
2. **Given** any record at `www` in zone Z, **When** a CNAME is created/renamed to `www`, **Then** 422 (legacy `dns_cname_edit.php:48-54`).
3. **Given** zone Z with origin `example.com.`, **When** a CNAME is created with `name` empty, `@`, `example.com.` or `example.com`, **Then** 422 (legacy `dns_cname_edit.php:61-65`, RFC 1912).
4. **Given** a CNAME whose `data` has no trailing dot and names no existing record in the zone, **When** created, **Then** 422 (legacy `dns_cname_edit.php:72-84`); `data = "@"` is replaced by the zone origin (`:67-70`).
5. **Given** an existing A record `name=www, data=192.0.2.1` in Z, **When** an identical A (same name+data) is created, **Then** 422 (legacy `dns_a_edit.php:48-53`); same for AAAA (`dns_aaaa_edit.php:48`) and ALIAS (`dns_alias_edit.php:47`).
6. **Given** an SRV record, **When** `hostname` (legacy `target`) exceeds 255 chars or fails `/^[a-zA-Z0-9\.\-\_]{1,255}$/`, **Then** 422 (legacy `dns_srv_edit.php:85-90`; our current rule caps at 64 — FR-018).
7. **Given** a DMARC create in a zone with no active DKIM record (`TXT v=DKIM%` or CNAME at `%._domainkey%`), **Then** 422 (legacy `dns_dmarc_edit.php:229-236`); with zero or more than one active SPF record, **Then** 422 (`:239-251`).

---

### User Story 4 - Mail filter regex op safety (Priority: P2)

A webmail portal creates a mailbox filter with `op=regex`. The API validates the `searchterm` is a compilable POSIX-ERE pattern before it is written into the mailbox's sieve script — because an invalid pattern makes Dovecot reject the **entire** `custom_mailfilter` sieve script, disabling every filter of that mailbox (the same one-bad-record-kills-all failure mode as P1).

**Why this priority**: Same blast-radius argument as P1, mail-scoped. **Evidence note**: the vendored 3.3.1p1 source contains NO compile check — `mail_user_filter.tform.php:99-113` validates `searchterm` NOTEMPTY only and `mail_user_filter_edit.php` adds nothing; 3.3.1 only added the `regex`/`localpart`/`domain` ops (`tform:97`), sieve quote-escaping (`interface/lib/plugins/mail_user_filter_plugin.inc.php:162-171`) and a POSIX-ERE UI hint (`web/mail/lib/lang/en_mail_user_filter.lng:19-20`). The compile check is therefore a **documented stricter-than-legacy deviation**, not a parity item.

**Independent Test**: `POST /mail/users/{id}/filters` with `op=regex, searchterm="["` → 422 naming `searchterm`; with `searchterm="^\[SPAM\]"` → 201.

**Acceptance Scenarios**:

1. **Given** `op=regex`, **When** `searchterm` does not compile as a POSIX-ERE (e.g. `[`, `(`, `a{2,1}`), **Then** 422 with `errors.searchterm`.
2. **Given** `op=regex`, **When** `searchterm` uses a PCRE inline flag (`(?i)` etc. — invalid in POSIX ERE, per the legacy hint text), **Then** 422.
3. **Given** any other `op`, **When** `searchterm` contains regex metacharacters, **Then** accepted unchanged (they are escaped at render time — `app/Services/MailUserFilterService.php:162-178`).
4. **Given** an existing filter with a stored bad pattern, **When** `PUT {"active": false}`, **Then** 200 (same deactivation tolerance as US2).

---

### User Story 5 - Per-domain relay without authentication, #6877 (Priority: P3)

An operator routes a mail domain's outbound mail through an IP-allowlisted smarthost that needs no SASL credentials: `POST/PUT /mail/domains` with `relay_host` set and `relay_user`/`relay_pass` empty or omitted succeeds. This matches ISPConfig 3.3.1p1, where the relay fields carry no validators at all (`mail_domain.tform.php:144-167`) and the Postfix map only requires `relay_host != ''` (`install/tpl/mysql-virtual_sender-relayhost.cf.master`).

**Why this priority**: Small, isolated relaxation; unblocks a real deployment pattern but nothing else depends on it.

**Independent Test**: `POST /mail/domains` with `{server_id, domain, relay_host: "smarthost.example.net"}` and no relay credentials → 201 (today it 422s via `required_with` — `app/Http/Requests/StoreMailDomainRequest.php:54-56`).

**Acceptance Scenarios**:

1. **Given** a valid mail domain payload with `relay_host` only, **When** `POST /mail/domains`, **Then** 201, `relay_user`/`relay_pass` stored as `''`.
2. **Given** an existing domain, **When** `PUT /mail/domains/{id}` sets `relay_host` without credentials, **Then** 200 (current `UpdateMailDomainRequest.php:61-63` chain removed).
3. **Given** `relay_user` set without `relay_pass` (or any other combination), **Then** accepted — legacy has no validators on any relay field.
4. **Given** the request sends explicit `relay_user: ""`, **Then** the stored value is cleared to `''` — documented deviation from legacy's update behavior, which *restores* the stored value when an empty string is submitted (`mail_domain_edit.php:315-317`); the API's explicit-value-wins semantics is kept (omission preserves, empty string clears).

---

### Edge Cases

- **Legacy admin relaxation is NOT mirrored**: 3.3.1p1 *removes* the data validators for admins on DS/SSHFP/LOC/TXT (e.g. `dns_ds.tform.php:160-165`). The API has no admin/user distinction here and always validates — deviation justified by the incident (the bad records were admin-entered).
- **TLSA `matching_type=0`** (exact certificate): the hex blob has no fixed length — validated as non-empty even-length hex only.
- **DS `digest_type` outside {1,2,4}**: `3` (GOST, deprecated) and unassigned values have no deterministic length; length checks apply only to 1/40, 2/64, 4/96 — others get charset/even-length checks and pass through [NEEDS CLARIFICATION: reject unknown digest_type values outright, or accept with hex-only check? BIND ≥9.16 rejects GOST; recommend restricting to `in:1,2,4`].
- **NAPTR flag semantics** (RFC 3403 §4.1, RFC 2915 §2): `regexp` and `replacement` are mutually exclusive (both non-empty = invalid); flag `U` ⇒ `regexp` required, `replacement` empty (stored as `.`); flags `S`/`A` ⇒ `regexp` empty, `replacement` required; flag `P`/empty ⇒ either form. The delimiter rule follows BIND's `txt_valid_regex` (lib/dns/rdata/in_1/naptr_35.c): empty, or `<delim><ERE><delim><replacement><delim>` with one single-byte delimiter that is not a digit, backslash or flag char, used exactly three times, unescaped occurrences forbidden inside, optional trailing `i` flag, backrefs `\1`–`\9` only.
- **`@`/`*` name normalization vs CNAME apex**: `prepareForValidation` already rewrites `@` → origin (`DnsRecordRequest.php:83-90`) — the apex check must run *after* that rewrite so `name=@` and `name=<origin>` are caught identically.
- **CNAME-conflict scope**: the legacy check compares raw `name` strings within one zone (three spelling variants) and only counts `dns_rr` rows — it does not consider `active`. Mirror exactly (an inactive CNAME still blocks — matches `dns_edit_base.php:46`, which has no `active` predicate).
- **CAA name handling**: legacy 3.3.1 forces every CAA `name` to `<origin>` or `<additional>.<origin>` (`dns_caa_edit.php:135,167-173`) and rejects duplicates (`:176-177`); it has no `iodef` support (the API's `iodef`/`additional` meta is a spec-002 documented deviation). The API keeps free-form `name` (contract) — parity limited to the duplicate check being out of scope [NEEDS CLARIFICATION: adopt the CAA duplicate-check (`name`+`data` unique) or leave to consumers?].
- **DMARC `fo`/`rf`/`ri` tags**: legacy 3.3.1 composes them (`dns_dmarc_edit.php:294-303,311-316,322-323`); our compose/parse (`DnsRecordMetaService::composeDmarc/parseDmarc`) does not. Supporting them **adds new meta properties to the contract** — flagged as the only additive schema change candidate, deferred: [NEEDS CLARIFICATION: add `fo`/`rf`/`ri` DMARC meta fields (additive DnsRecord.yaml change) in this feature or a follow-up?]. Not required for validation hardening.
- **Missing/invalid `X-API-Key`** → 401 problem+json (existing `api.auth` group, unchanged).
- **`Size` filter source**: `searchterm` is `intval()`-ed with `k/K/m` suffix at render (`mail_user_filter_plugin.inc.php:151-157`) — non-numeric input silently becomes `0`; not validated by legacy; unchanged here (documented, not hardened).

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/records.yaml` [existing — **no changes**: 422 already declared on POST (line 139) and PUT (line 218)], `api/modules/mail/user-filters.yaml` [existing — **no changes**: 422 declared], `api/modules/mail/domains.yaml` [existing — **description-only change**: lines 76-77 currently promise "If relay_host is set, relay_user is required; if relay_user is set, relay_pass is required" — reworded to "relay credentials are optional; leave empty for IP-authorized smarthosts" per #6877].
- **Shared schemas**: `api/components/schemas/DnsRecord.yaml` [existing — **no structural changes**: `hash` (:297-302) and `digest` (:521-525) are already documented "hex encoded", `regexp`'s example (:488-494) is already a delimited expression; optional description enrichment only], `api/components/schemas/MailDomain.yaml` [existing — **description-only change**: drop "Required if relay_host is set" (:94) and "Required if relay_host and relay_user are set" (:104)], `api/components/schemas/MailUserFilter.yaml` [existing — optional description note on `searchterm` POSIX-ERE for `op=regex`].
- **Contract-compat verdict**: **compatible**. All tightening lands inside already-declared 422 responses; every affected field's documented format already matches the new rules (the schema promised hex/delimited formats the code never enforced). The single relaxation (#6877) turns previously-422 requests into 2xx — non-breaking. The only contract edits are the two relay description tweaks (documentation-only). Exception flagged: DMARC `fo`/`rf`/`ri` support would be an additive schema change — deferred behind a NEEDS CLARIFICATION above.
- **Endpoints** (all existing; no new endpoints, no method/path/status changes):

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| POST | `/api/v1/dns/records` | Create record — hardened validation | 201 |
| PUT | `/api/v1/dns/records/{id}` | Update record — hardened, submitted-fields-only | 200 |
| POST | `/api/v1/mail/users/{id}/filters` | Create filter — regex-op compile check | 201 |
| PUT | `/api/v1/mail/users/{id}/filters/{filter_id}` | Update filter — same, submitted-fields-only | 200 |
| POST | `/api/v1/mail/domains` | Create mail domain — relay chain relaxed | 201 |
| PUT | `/api/v1/mail/domains/{id}` | Update mail domain — relay chain relaxed | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/dns/form/dns_{a,aaaa,alias,caa,cname,dkim,dmarc,dname,ds,hinfo,loc,mx,naptr,ns,ptr,rp,spf,srv,sshfp,tlsa,txt}.tform.php` (all record-type validators enumerated in the table below), `dns_edit_base.php` (CNAME conflict :43-49,:130; `@`/`*` rewrite :120-128; accidental-quote strip :132-136), `dns_{a,aaaa,alias,cname,dname}_edit.php` (checkDuplicate overrides), `dns_cname_edit.php` (apex :61-65, target existence :72-84), `dns_srv_edit.php` (:61-93), `dns_dmarc_edit.php` (:229-251,:254-331), `dns_caa_edit.php` (:130-178); `web/mail/form/mail_user_filter.tform.php` (:93-130), `interface/lib/plugins/mail_user_filter_plugin.inc.php` (:140-205), `web/mail/form/mail_domain.tform.php` (:144-167), `mail_domain_edit.php` (:217-241,:311-317); BIND ground truth: `server/conf/bind_pri.domain.master` (quoting of emitted records), `server/plugins-available/bind_plugin.inc.php` (:310-325 TXT splitting / CAA TYPE257).
- **Legacy behaviors to mirror (P2)**: enumerated per-row in the validation table and User Story 3; legacy checks are ported verbatim including their name-variant matching and lack of `active` predicates.
- **Intentional deviations from legacy (documented)**:
  1. **P1 BIND-safety rules are stricter than legacy** — legacy accepts all four incident payloads; justification: BIND refuses the whole zone (RFC/BIND citations per rule below).
  2. **No admin relaxation** of data validators (legacy `dns_ds.tform.php:160-165` etc.).
  3. **Mail-filter regex compile check** — absent from 3.3.1p1; justification: one bad pattern disables the mailbox's whole sieve script.
  4. **Relay explicit-empty-clears** on update vs legacy restore-if-empty (`mail_domain_edit.php:315-317`).
  5. **CAA free-form `name`** retained (legacy forces zone-derived names) — pre-existing spec-002 deviation, unchanged.
- **Tables written (via datalog only)**: `dns_rr` (i/u — validation only changes *whether* a write happens, never *how*), `dns_soa` (u — serial bump, unchanged), `mail_user_filter` (i/u), `mail_user` (u — custom_mailfilter regen, unchanged), `mail_domain` (i/u). No new datalog surfaces.
- **System fields handling**: unchanged (server_id/sys_groupid inherited from the parent zone per `DnsRecordController.php:126-130`).

## Requirements *(mandatory)*

### Functional Requirements

**P1 — BIND-safety (stricter than legacy; each rule cites its ground truth)**

- **FR-001**: The system MUST reject, on create AND on update (when the field is submitted), any DNS record whose composed `data` would make BIND's zone parser refuse the zone, returning 422 `application/problem+json` whose `errors` object names the offending request field. No `sys_datalog` row may be written for a rejected request.
- **FR-002 (DS)**: `digest` MUST be hexadecimal (`/^[0-9a-fA-F]+$/`) with even length; when `digest_type` is 1/2/4 the length MUST be exactly 40/64/96 (SHA-1/SHA-256/SHA-384 — RFC 4034 §5.1.4, RFC 4509, RFC 6605; BIND `ds_43.c` enforces per-type lengths). `key_tag` 0–65535 and `algorithm` 0–255 remain (unchanged). Legacy: `dns_ds.tform.php:108-114` regex `/^\d{1,5}\s\d{1,2}\s\d{1,2}\s.+$/` — accepts any tail (and the `:105` TODO admits it never worked).
- **FR-003 (TLSA)**: `hash` MUST be hexadecimal with even length; when `matching_type` is 1 the length MUST be 64, when 2 it MUST be 128 (SHA-256/SHA-512 — RFC 6698 §2.1.3, §7.4); `matching_type=0` (exact match) requires even-length hex only. `cert_usage` 0–3, `selector` 0–1, `matching_type` 0–2 remain (unchanged). Legacy: `dns_tlsa.tform.php:107-109` `/^\d \d \d [a-zA-Z0-9]*$/` — 'somehashstring' passes.
- **FR-004 (SSHFP)**: `hash` MUST be hexadecimal with even length; when `hash_type` is 1 the length MUST be 40, when 2 it MUST be 64 (SHA-1/SHA-256 — RFC 4255 §3.1.2, RFC 6594 §4). `algorithm` 0–4, `hash_type` 0–2 remain (unchanged). Legacy: `dns_sshfp.tform.php:108-113` NOTEMPTY only.
- **FR-005 (DNSKEY)**: `data` MUST match `<flags> <protocol> <algorithm> <base64-key>` where flags is an integer 0–65535, protocol is exactly `3` (RFC 4034 §2.1.2 — BIND rejects other values), algorithm 0–255, and the remainder is valid, non-empty base64 (whitespace between chunks allowed, as zone files split keys). Legacy: no form exists for DNSKEY (not creatable via panel); current API: `max:65535` only (`DnsRecordRequest.php:191`).
- **FR-006 (NAPTR)**: `regexp` MUST be empty or a valid delimited substitution expression per BIND's `txt_valid_regex` (see Edge Cases for the exact grammar; RFC 3403 §4.1, RFC 2915 §3). `regexp` and `replacement` MUST NOT both be non-empty (RFC 3403 §4.1). Flag-dependent rules: `naptr_flag=U` ⇒ `regexp` required + `replacement` empty; `S` or `A` ⇒ `regexp` empty + `replacement` required; `P` or absent ⇒ either. Legacy: `dns_naptr.tform.php:113-117` validates only the composed shape `'pref "flags" "service" "regexp" replacement.'` — any regexp content passes.
- **FR-007 (NAPTR service)**: `service` MUST match the RFC 3403 §4.1 grammar characters (`/^[a-zA-Z0-9+\-.:]*$/`) — currently `max:32` free-form (`DnsRecordRequest.php:252`); a `"` in service would unbalance the stored quoting despite zoneFileEscape (escaped quotes are legal, but validating avoids consumer surprise). *(Source: BIND-safety)*
- **FR-008 (TXT/DKIM)**: after accidental-quote stripping, `data` MUST NOT contain a double quote or CR/LF. Justification: the BIND template emits `TXT "{data}"` (`bind_pri.domain.master:63`; >255-char values are chunk-quoted at `bind_plugin.inc.php:313-315`) — an interior unescaped `"` or newline unbalances the zone file. Legacy TXT validators (`dns_txt.tform.php:108-127`) only exclude `v=DKIM`/`v=DMARC1; `/`v=spf` payloads (already mirrored by `plainTxtRule`, unchanged); DKIM data is NOTEMPTY-only (`dns_dkim.tform.php:108-110`).
- **FR-009 (CAA/HINFO quoted values)**: CAA `ca_issuer`/`additional` and HINFO `cpu`/`os` MUST NOT contain double quotes, backslashes or CR/LF — `DnsRecordMetaService::quote()` (:573-580) wraps them verbatim without escaping, and the zone template emits `data` raw (`bind_pri.domain.master:24,36`), so an embedded `"` breaks the zone. Additionally CAA `ca_issuer` MUST match the issuer-domain[;parameters] shape `/^[a-zA-Z0-9\.\-]*(;\s*[a-zA-Z0-9_\-]+=[^";]+)*$/` and `additional` (iodef) MUST be a `mailto:`/`http:`/`https:` URL (RFC 8659 §4.5-4.7). Legacy: CAA data has no tform validator (`dns_caa.tform.php`), the edit action composes from a curated CA list + option regex `/^(\w+|d\+)=(\w+|d\+)/` (`dns_caa_edit.php:152-163`).
- **FR-010 (LOC)**: `data` MUST match the RFC 1876 presentation grammar (`d1 [m1 [s1]] {N|S} d2 [m2 [s2]] {E|W} alt["m"] [siz["m"] [hp["m"] [vp["m"]]]]`). Legacy: validator commented out with a TODO (`dns_loc.tform.php:113-118`); current API: `max:65535` only. *(Source: BIND-safety — BIND `loc_29.c` refuses malformed LOC.)*
- **FR-011 (audited, unchanged)**: MX/SRV/NS/PTR/ALIAS/CNAME/DNAME hostname regexes, A `ipv4`/AAAA `ipv6` rules, SPF/DMARC token composition and CAA/SRV/MX/HINFO integer ranges were audited: their existing character-class regexes already exclude quotes/whitespace/control characters, so no zone-breaking value passes — retained as-is (table below).

**Cross-cutting — existing-data tolerance**

- **FR-012**: `PUT /dns/records/{id}` MUST validate only the fields present in the request (`sometimes` semantics). In particular a body of exactly `{"active": false}` (or any subset of `name`/`ttl`/`active`/`sys_groupid`) MUST succeed against a record whose stored type-specific fields violate FR-002..FR-010 — this is the incident recovery flow. The same tolerance applies to `PUT /mail/users/{id}/filters/{filter_id}` (FR-019) and `PUT /mail/domains/{id}`.
- **FR-013**: When a structured record's stored `data` cannot be decomposed by `DnsRecordMetaService::meta()` (parse returns no fields) and the request submits none of that type's meta fields, the update MUST preserve stored `aux`/`data` byte-for-byte instead of re-composing from defaults (fixes the latent rewrite hazard at `DnsRecordController.php:174-181`, where e.g. a 2-token SRV would silently become `"0 0 ."`).

**P2 — 3.3.1p1 parity (legacy check verbatim → current behavior → required change)**

- **FR-014 (CNAME conflict, both directions)**: creating or updating any non-CNAME record MUST fail 422 when a CNAME exists in the same zone at the same name; creating or updating a CNAME MUST fail 422 when *any* record exists at that name. Name equality uses legacy's three spellings (`name`, `name` minus `.<origin>`, `name` plus `.<origin>`), zone-scoped, excluding the record itself, ignoring `active`. Legacy: `dns_edit_base.php:43-49` + call site `:130`; CNAME-side override `dns_cname_edit.php:48-54`. Current: no check anywhere.
- **FR-015 (CNAME apex)**: a CNAME whose post-normalization name is empty, `@`, `<origin>` or `<origin>` without trailing dot MUST fail 422. Legacy: `dns_cname_edit.php:61-65` (RFC 1912). Current: no check.
- **FR-016 (CNAME target)**: CNAME `data` of `@` is replaced with the zone origin; a relative target (no trailing dot) MUST name an existing record in the zone (matched as `name` or `name.<origin>`) or fail 422. Legacy: `dns_cname_edit.php:67-84`. Current: no check.
- **FR-017 (A/AAAA/ALIAS duplicates)**: an A record MUST fail 422 when an identical A (same zone+name+data) or any CNAME/ALIAS at the same name exists (`dns_a_edit.php:48-53`); analogous rules for AAAA (`dns_aaaa_edit.php:48`) and ALIAS (`dns_alias_edit.php:47`). Current: no check. (The CNAME leg overlaps FR-014; the same-data duplicate leg is new.)
- **FR-018 (SRV)**: align `hostname` to legacy target validation `/^[a-zA-Z0-9\.\-\_]{1,255}$/` (`dns_srv_edit.php:88`) — current rule truncates at 64 (`DnsRecordRequest.php:198`). `priority`/`weight`/`port` 0–65535 already match (`dns_srv_edit.php:70-83`, `dns_srv.tform.php:120-125`) — unchanged.
- **FR-019 (mail filter regex op)**: when `op=regex`, `searchterm` MUST compile as a POSIX-ERE (and MUST NOT rely on PCRE-only inline flags); other ops unchanged. Deviation note per User Story 4 (no legacy compile check exists — `mail_user_filter.tform.php:99-113`).
- **FR-020 (DMARC prerequisites)**: creating a DMARC record MUST fail 422 when the zone has no active DKIM record (`TXT` data `v=DKIM%` or `CNAME` at name like `%._domainkey%` — `dns_dmarc_edit.php:229-236`) or when the zone does not have exactly one active SPF TXT (`:239-251`). Current: no checks.

**P3 — #6877 per-domain relay**

- **FR-021**: `relay_host`, `relay_user` and `relay_pass` MUST each be independently optional on `POST`/`PUT /mail/domains` — remove `required_with:relay_host` from `relay_user` and `required_with:relay_user` from `relay_pass` (`StoreMailDomainRequest.php:54-56`, `UpdateMailDomainRequest.php:61-63`). Legacy ground truth: no validators on any relay field (`mail_domain.tform.php:144-167`); Postfix consumes `relay_host` alone (`install/tpl/mysql-virtual_sender-relayhost.cf.master`). No new columns/fields exist in 3.3.1p1 for this.
- **FR-022**: update the two contract descriptions that promise the old requirement (`api/components/schemas/MailDomain.yaml:94,104`; `api/modules/mail/domains.yaml:76-77`) — documentation-only edit.

### Validation-rules table per record type

Source legend: **B** = BIND-safety (stricter than legacy, this feature), **P** = 3.3.1-parity (this feature), **U** = unchanged (already correct).

| Type | Field | Rule | Source |
|------|-------|------|--------|
| A | `data` | `ipv4` (legacy `dns_a.tform.php:110` ISIPV4) | U |
| A | zone-level | no identical A / no CNAME / no ALIAS at name (`dns_a_edit.php:48-53`) | P |
| AAAA | `data` | `ipv6` (`dns_aaaa.tform.php:103` ISIPV6) | U |
| AAAA | zone-level | duplicate/CNAME/ALIAS check (`dns_aaaa_edit.php:48`) | P |
| ALIAS | `data` | `/^[a-zA-Z0-9\.\-]{1,255}$/` (`dns_alias.tform.php:117-122`) | U |
| ALIAS | zone-level | duplicate check (`dns_alias_edit.php:47`) + CNAME conflict | P |
| CNAME / DNAME | `data` | `/^[a-zA-Z0-9\.\-\_]{1,255}$/` (`dns_cname.tform.php:115-120`) | U |
| CNAME | `name` | not apex (`dns_cname_edit.php:61-65`); no other record at name (`:48-54`) | P |
| CNAME | `data` | `@`→origin; relative target must exist in zone (`:67-84`) | P |
| NS / PTR | `data` | `/^[a-zA-Z0-9\.\-]{1,256}$/` (`dns_ns.tform.php:115-120`, `dns_ptr.tform.php:115-120`) | U |
| RP | `data` | `/^[\w\.\-\s]{1,128}$/` (`dns_rp.tform.php:108-113`) | U |
| MX | `hostname` | `/^[a-zA-Z0-9\.\-]{1,255}$/`, `priority` 0–65535 (`dns_mx.tform.php:116-121`) | U |
| SRV | `hostname` | widen to `/^[a-zA-Z0-9\.\-\_]{1,255}$/` (`dns_srv_edit.php:88`; current cap 64) | P |
| SRV | `priority`/`weight`/`port` | 0–65535 (`dns_srv_edit.php:70-83`) | U |
| TXT / DKIM | `data` | no `"`, no CR/LF (zone template `bind_pri.domain.master:63`) | B |
| TXT | `data` | no `v=DKIM`/`v=DMARC1; `/`v=spf` payloads (`dns_txt.tform.php:113-126`) | U |
| LOC | `data` | RFC 1876 grammar (legacy validator commented out, `dns_loc.tform.php:113-118`) | B |
| DNSKEY | `data` | `<flags 0-65535> 3 <algo 0-255> <base64>` (RFC 4034 §2; no legacy form) | B |
| DS | `key_tag`/`algorithm`/`digest_type` | 0–65535 / 0–255 / restrict per NEEDS-CLARIFICATION | U |
| DS | `digest` | hex, even length; type 1→40, 2→64, 4→96 (RFC 4034 §5.1.4; `dns_ds.tform.php:105` TODO) | B |
| TLSA | `cert_usage`/`selector`/`matching_type` | 0–3 / 0–1 / 0–2 (contract) | U |
| TLSA | `hash` | hex, even length; mt 1→64, 2→128 (RFC 6698 §2.1.3; legacy `/^\d \d \d [a-zA-Z0-9]*$/`) | B |
| SSHFP | `algorithm`/`hash_type` | 0–4 / 0–2 (contract) | U |
| SSHFP | `hash` | hex, even length; ht 1→40, 2→64 (RFC 4255 §3.1.2; legacy NOTEMPTY only) | B |
| CAA | `caa_flag`/`caa_type` | 0–255 / issue,issuewild,iodef | U |
| CAA | `ca_issuer`/`additional` | no `"`/`\`/CR-LF; issuer-domain[;params] / iodef URL (RFC 8659; `quote()` doesn't escape) | B |
| HINFO | `cpu`/`os` | no `"`/`\`/CR-LF (same quoting hazard) | B |
| SPF | all meta | token/IP/email list rules (`DnsRecordRequest.php:230-238`) | U |
| DMARC | all meta | enums/pct/mailto lists (`:239-247`) | U |
| DMARC | zone-level | active DKIM required; exactly one active SPF (`dns_dmarc_edit.php:229-251`) | P |
| NAPTR | `order`/`pref` | 0–65535 | U |
| NAPTR | `naptr_flag` | in U,S,A,P (nullable) | U |
| NAPTR | `service` | `/^[a-zA-Z0-9+\-.:]*$/` (RFC 3403 §4.1) | B |
| NAPTR | `regexp` | empty or BIND-valid delimited substitution; XOR with `replacement`; flag-dependent (RFC 3403/2915) | B |
| all types | `name` | legacy tform regexes (`DnsRecordRequest::NAME_PATTERNS`) | U |
| all types | update | submitted-fields-only; `{"active": false}` never blocked; unparseable stored data preserved verbatim | B (cross-cutting) |

### Key Entities

- **DnsRecord**: resource record — table `dns_rr`, schema `api/components/schemas/DnsRecord.yaml`, model `app/Models/DnsRecord.php`; validation in `app/Http/Requests/{Store,Update}DnsRecordRequest.php` + `DnsRecordRequest.php`; compose/parse in `app/Services/DnsRecordMetaService.php`.
- **DnsSoa**: parent zone (read-only here: origin for name normalization, CNAME/DMARC zone-level queries) — table `dns_soa`.
- **MailUserFilter**: mailbox filter rule — table `mail_user_filter`, schema `api/components/schemas/MailUserFilter.yaml`, requests `app/Http/Requests/{Store,Update}MailUserFilterRequest.php` + `MailUserFilterRequest.php`, rendering `app/Services/MailUserFilterService.php`.
- **MailDomain**: mail domain incl. relay fields — table `mail_domain`, schema `api/components/schemas/MailDomain.yaml`, requests `app/Http/Requests/{Store,Update}MailDomainRequest.php`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Each of the four incident records (DS/base64-digest, TLSA/'somehashstring', SSHFP/'fingerprinthash', NAPTR/undelimited-regexp) is rejected on `POST /dns/records` with 422 whose `errors` object names the offending field (`digest`, `hash`, `hash`, `regexp`), and no `sys_datalog` row is written.
- **SC-002**: Each of the four incident records, seeded directly into `dns_rr`, can be deactivated via `PUT {"active": false}` → 200, stored `data` byte-identical, zone serial bumped — the exact incident recovery flow.
- **SC-003**: For every hardened type, the documented valid/invalid matrix passes: all valid rows → 2xx with composed `data` accepted by BIND's zone parser (spot-verified with `named-checkzone` against a rendered test zone); all invalid rows → 422.
- **SC-004**: CNAME conflict, apex and target rules reject in both directions per the FR-014..FR-016 scenarios; behavior matches legacy for the documented cases (same three name spellings).
- **SC-005**: `POST /mail/domains` with `relay_host` only returns 201 (was 422); Swagger UI shows the corrected relay descriptions.
- **SC-006**: `op=regex` filters with non-compiling patterns → 422; valid POSIX-ERE patterns → 201 and the rendered sieve block is unchanged from the current implementation.
- **SC-007**: Full `vendor/bin/phpunit` suite passes; no OpenAPI structural diff (paths/methods/status codes/property names) other than the two relay description texts.

## Assumptions

- Scope is validation + the #6877 relaxation only: no new endpoints, no new writable columns, no changes to compose/parse output for *valid* input (byte-compatibility of stored `data` is preserved).
- The vendored `source_code/` (ISPConfig 3.3.1p1) is the parity source of truth; where the feature brief's assumptions contradict it (mail-filter regex compile check; #6877 having "new fields"), the vendored source wins and the discrepancy is documented in the relevant story.
- BIND is the deployed nameserver (the ISPConfig BIND plugin renders `bind_pri.domain.master`); rules are calibrated to BIND's parser via the cited RFCs and BIND rdata sources. Other backends (PowerDNS) are at least as strict or unaffected.
- Zone-level checks (CNAME conflict, DMARC prerequisites, duplicate detection) read `dns_rr`/`dns_soa` directly — read-only queries, permitted (no datalog involvement).
- Per-record `sys_perm_*` enforcement remains out of scope (spec 002/011 posture, unchanged).
- Existing invalid rows in `dbispconfig` are tolerated forever on partial update (FR-012/FR-013); no migration or cleanup of stored data is performed.
