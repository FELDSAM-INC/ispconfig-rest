# Feature Specification: Zone and Record Rule Parity for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: dns  
**Input**: User description: "Zone and record rule parity for scoped keys: customer keys can set `update_acl` (admin-only in legacy) and other admin-only SOA/record fields (verify `xfer`, `also_notify`, `dnssec_*`, `server_id` on update already handled by 016) — refuse with 422 typed `feature-not-allowed` or ignore per legacy; add legacy record validations missing for all keys where legacy enforces them for everyone (CNAME must not coexist with other records of the same name, duplicate records, MX/SRV target formats) — check validate_dns and the record forms; list which were already covered by spec 013."

## Context

The ISPConfig DNS forms hide two things from customers: the dynamic-update ACL of a zone (`update_acl`, removed from
the form for every non-administrator) and renaming a zone (a client user who has no clients of its own gets "The Zone
(SOA) can not be changed. Please contact your administrator to change the zone."). The API accepts both from any key.

The record forms also refuse duplicates that the API still accepts: an identical MX, TLSA or DKIM record, and a second
SPF record for the same host name. Spec 013 ported the CNAME, A/AAAA/ALIAS/DNAME, CAA and DMARC rules; these four were
not part of it.

This feature closes both gaps: the two administrator-only zone fields and the four missing duplicate rules.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Customer keys cannot change administrator-only zone settings (Priority: P1)

A customer's panel sends a zone update. It cannot set the dynamic-update ACL, and a plain customer cannot rename a
zone; the refusal names the field and its reason, so the panel can hide or disable the input.

**Why this priority**: `update_acl` controls who may change the zone's contents over the network — a customer must
not be able to open that up. Renaming a zone the administrator created breaks the hosting it belongs to.

**Independent Test**: send `update_acl` and a changed `origin` with client, reseller and admin keys; check the status,
the problem body, that nothing was written, and that re-sending the stored values is accepted.

**Acceptance Scenarios**:

1. **Given** a zone of a client, **When** its key sends `PUT /dns/soa/{id}` with `update_acl`, **Then** 422 with
   `errors.update_acl` `The dynamic update ACL can only be changed with an administrator key.`, `error_types.update_acl`
   = `feature-not-allowed`, and nothing is written.
2. **Given** the same zone, **When** the key sends `update_acl` with exactly the stored value (or `""` when it is
   empty), **Then** the request is accepted (re-sending the current value is not a change, as with `server_id` in
   spec 016).
3. **Given** a client key that is not a reseller, **When** it sends a different `origin`, **Then** 422 with
   `errors.origin` `The zone name cannot be changed. Please contact your administrator to change the zone.` and
   `error_types.origin` = `feature-not-allowed`; re-sending the stored origin (in any accepted spelling) is accepted.
4. **Given** a reseller key, **Then** it may rename its own and its clients' zones, but `update_acl` is still refused.
5. **Given** an admin key, **Then** both are accepted.
6. **Given** `POST /dns/soa` with `update_acl` from a client or reseller key, **Then** the same 422 (legacy never
   offers the field); `xfer`, `also_notify` and the DNSSEC fields stay writable for every key (legacy shows them to
   all user types).

---

### User Story 2 - The API refuses the duplicates the panel refuses (Priority: P1)

A panel or script adds a record that already exists. The API answers with the same refusal the ISPConfig form gives,
for every key type.

**Why this priority**: duplicate MX and SPF records break mail delivery and SPF evaluation; ISPConfig users expect the
panel's guard rails through the API.

**Independent Test**: create the duplicates through the API for each of the four types with client and admin keys,
and check that the legitimate variants still pass.

**Acceptance Scenarios**:

1. **Given** an MX record `mail.example.com.` at `@` with priority 10, **When** the same host name is added at the
   same name (any priority), **Then** 422 `errors.name` `An identical MX record already exists for this name in the
   zone.` and nothing is written; a different host name is accepted.
2. **Given** a TLSA record, **When** an identical TLSA (same name and composed data) is added, **Then** 422; a
   different hash or name is accepted.
3. **Given** a DKIM record, **When** an identical DKIM (same name and data) is added, **Then** 422.
4. **Given** an SPF (`v=spf1…` TXT) record at a name, **When** another SPF record is added for that name, **Then**
   422 `errors.name` `An SPF record already exists for this name in the zone.`; editing the existing record, or
   adding an SPF for a different name, is accepted.
5. **Given** any of these updates that keep the record unchanged (the record itself is excluded from the check),
   **Then** they are accepted.
6. **Given** an admin key, **Then** the same four rules apply (legacy checks them for every user type).

---

### User Story 3 - Existing behaviour is untouched (Priority: P2)

Everything spec 013 already enforces, and the fields legacy shows to customers, keep working unchanged.

**Acceptance Scenarios**:

1. **Given** the spec 013 rules (CNAME conflict/apex/target, A/AAAA/ALIAS/DNAME duplicates, CAA duplicates, DMARC
   prerequisites, SRV formats, BIND-safety rules), **Then** they behave as before.
2. **Given** a client key, **Then** `xfer`, `also_notify`, `dnssec_wanted` and `dnssec_algo` are still accepted, and
   `server_id` keeps the spec 016 behaviour.

### Edge Cases

- `update_acl` sent as `null` against a stored empty value is not a change (both mean "no ACL").
- Origin comparison uses the normalized value (IDN-encoded, lower-cased, dot-terminated), so `Example.COM` equals the
  stored `example.com.`.
- A client key that is a reseller (its plan allows clients) may rename, exactly as legacy's `has_clients()`.
- The duplicate checks compare the composed stored `data`, so MX priority is not part of the comparison (legacy
  compares `data` only — the same host name at another priority is a duplicate).
- The duplicate checks are zone-scoped and exclude the record being updated.
- Legacy's `validate_dns::validate_rr()` and `validate_soa()` (label lengths, character sets, wildcard placement, PTR
  trailing dot, ttl bounds) have **no call site** in ISPConfig 3.3.1p1 — the panel never runs them, so they are not
  added here (research R4).

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/soa.yaml` (POST/PUT description, 422), `api/modules/dns/records.yaml`
  (duplicate rules).
- **Shared schemas**: `api/components/schemas/DnsSoa.yaml` (`update_acl`, `origin` descriptions).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| POST/PUT | `/api/v1/dns/soa`, `/api/v1/dns/soa/{id}` | `update_acl` and zone renames are administrator-only (422 `feature-not-allowed` per field) | 201/200 |
| POST/PUT | `/api/v1/dns/records`, `/api/v1/dns/records/{id}` | + MX, TLSA, DKIM duplicate and single-SPF rules for every key | 201/200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `dns/form/dns_soa.tform.php` 344 (`if(!$app->auth->is_admin())
  unset($form["tabs"]['dns_soa']['fields']['update_acl'])`), `dns/templates/dns_soa_edit.htm` 139–142 (`update_acl`
  inside `tmpl_if is_admin`) and 132–137 (`xfer`, `also_notify` for everyone); `dns/dns_soa_edit.php` 333–344
  (`onBeforeUpdate`: a non-admin without clients cannot change `origin`; the old value is restored),
  `lib/lang/en_dns_soa.lng` 42 (`soa_cannot_be_changed_txt`); `dns/dns_mx_edit.php` 50–66 (duplicate `zone`, `name`,
  `type`, `data`, self-excluded on update; `en_dns_mx.lng` 16 `Duplicate MX record.`); `dns/dns_tlsa_edit.php`
  110–130 (same shape); `dns/dns_dkim_edit.php` 128–131 (`zone`, `type`, `data`, `name`; `record_exists_txt`);
  `dns/dns_spf_edit.php` 165–188 (one `TXT` with `data LIKE 'v=spf1%'` per `zone` + `name`; `spf_record_exists_txt`,
  `spf_record_exists_multiple_txt`); `lib/classes/validate_dns.inc.php` (dead code, research R4).
- **Legacy behaviors to mirror**: administrator-only zone fields; the four duplicate rules for every user type.
- **Tables written (via datalog only)**: unchanged; refusals write nothing.
- **System fields handling**: unchanged.
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-16):
  - Legacy silently ignores `update_acl` from a non-administrator (the field is not in the form) and silently
    restores a changed `origin`; the API refuses instead, with the typed 422 the other admin-only fields use
    (spec 025 `custom_mailfilter`). Re-sending the stored value is accepted, as spec 016 does for `server_id`.
  - The SPF rule is a plain refusal; legacy's message offers a link to the existing record.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `POST /dns/soa` and `PUT /dns/soa/{id}` MUST refuse `update_acl` from non-admin keys with 422,
  `errors.update_acl` and `error_types.update_acl` = `feature-not-allowed`, unless the submitted value equals the
  stored one (empty and `null` count as equal on create and for an empty stored value).
- **FR-002**: `PUT /dns/soa/{id}` MUST refuse a changed `origin` from a client key that is not a reseller, with 422,
  `errors.origin` and `error_types.origin` = `feature-not-allowed`; reseller and admin keys may rename. Comparison
  uses the normalized origin.
- **FR-003**: `xfer`, `also_notify`, `dnssec_wanted`, `dnssec_algo` MUST stay writable for every key; `server_id`
  keeps the spec 016 rules.
- **FR-004**: `POST`/`PUT /dns/records` MUST refuse, for every key type, an MX, TLSA or DKIM record identical to
  another record of the same type in the zone (same `name` and composed `data`, the record itself excluded), and a
  second SPF record (`TXT` with `v=spf1…`) for the same `name`.
- **FR-005**: Refusals MUST write nothing (no `dns_rr`, no serial bump, no `sys_datalog`).
- **FR-006**: Contract first; feature tests for both zone fields across key types (including the accepted no-change
  case) and for the four duplicate rules including the legitimate variants; spec 013's rules keep their tests.

### Key Entities

- **Zone** (`dns_soa`): `update_acl` (administrator-only), `origin` (renaming administrator/reseller-only).
- **Record** (`dns_rr`): MX, TLSA, DKIM identical-record rule; SPF one-per-name rule.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 dynamic-update ACLs set and 0 zones renamed by customer keys in the test matrix.
- **SC-002**: The four duplicate cases the ISPConfig form refuses are refused by the API for every key type, and 0
  legitimate variants are refused.
- **SC-003**: No spec 013 or spec 016 behaviour changes (their test suites stay green unchanged).

## Assumptions

- The WHMCS module (spec 005) sends neither `update_acl` nor `origin` on updates; the refusals are defence in depth.
- Panels that PUT a whole zone object back are unaffected as long as they do not change these fields.
