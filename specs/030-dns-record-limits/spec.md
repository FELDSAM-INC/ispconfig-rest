# Feature Specification: DNS Record Limit Parity

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: dns (plus usage)  
**Input**: User description: "DNS record limit parity: legacy enforces client `limit_dns_record` for client/reseller users; ispconfig-rest does not, and `/usage/summary` reports no DNS record count. Enforce with 023 `limit-reached` (limit name, scope, max, used) and add the count to `/usage/summary` counts (and `/me/capabilities` if appropriate). Release gate for module DNS writes. Must fit the WHMCS module `specs/005-dns/contracts/ispconfig-rest-calls.md` (`counts.dns_records`)."

## Context

A hosting plan in ISPConfig can cap the number of DNS records of an account (`limit_dns_record`, "Max. number of DNS
records"). The ISPConfig panel refuses a new record once the account reached the cap. The API accepts any number of
records: spec 012 searched for `checkClientLimit('limit_dns_record')`, found no call site and deliberately left the
limit out (012 FR-021, SC-006). The legacy DNS record forms do not use `checkClientLimit()`; they check the limit with
their own inline query in `dns_edit_base.php` and the five record forms with their own `onSubmit`. A customer key can
therefore create more records than its plan allows, and the panel cannot show the record count next to the zone count.

This feature applies the legacy record cap to scoped keys and reports the count in the usage summary. It supersedes
spec 012 FR-021 and SC-006 for `limit_dns_record`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record creation respects the plan's record cap (Priority: P1)

A customer whose plan allows 50 DNS records adds records in the panel. The 51st record is refused with the typed
limit problem, so the panel can say "Your plan allows 50 DNS records" instead of a generic error. Changing or deleting
existing records keeps working at the cap. Administrator keys are not limited.

**Why this priority**: without it customers exceed what they pay for, and the WHMCS module treats the parity as a
release gate for DNS writes.

**Independent Test**: set `limit_dns_record` on a client, create records in its zone with its key until the cap, then
one more; check the status, the problem body and that nothing was journaled; repeat with admin and reseller keys.

**Acceptance Scenarios**:

1. **Given** a client with `limit_dns_record = 2` owning a zone with 2 records, **When** its key sends
   `POST /dns/records`, **Then** 403 `limit-reached` with `limit {name: limit_dns_record, scope: client, max: 2,
   used: 2}`, detail `You have reached the maximum number of DNS records allowed for your account.`, and no
   `sys_datalog` row.
2. **Given** the same client with `limit_dns_record = 3`, **When** it creates a record, **Then** 201.
3. **Given** `limit_dns_record = -1`, **Then** records are created without a cap; **given** `0`, **then** every
   record create is refused (`max: 0`).
4. **Given** the client at its cap, **When** it updates or deletes a record, **Then** 200 / 204 (the cap applies to new
   records only).
5. **Given** an admin key creating a record in the capped client's zone, **Then** 201.
6. **Given** a reseller key, **Then** the reseller's own `limit_dns_record` applies, counted over the records carrying
   the reseller's own group; the zone owner's cap does not apply to the reseller key, and the reseller's cap is not a
   second cap for its clients (legacy counts by the acting user's group and has no reseller cap).
7. **Given** records of another client, **Then** they do not count towards this client's cap.

---

### User Story 2 - Usage summary reports the DNS record count (Priority: P1)

The panel shows "DNS records: 12 of 50" on the DNS page from the usage summary it already reads.

**Why this priority**: the module reads `counts.dns_records` to show the cap before a customer hits it.

**Independent Test**: call `GET /usage/summary` with client, reseller (naming a client) and admin keys and compare
`counts.dns_records` with the records carrying the client's group and the client's `limit_dns_record`.

**Acceptance Scenarios**:

1. **Given** a client with 3 records in its zones and `limit_dns_record = 50`, **When** its key reads the summary,
   **Then** `counts.dns_records` is `{used: 3, limit: 50}`.
2. **Given** `limit_dns_record = -1`, **Then** `limit` is `null` (unlimited, as the other counts).
3. **Given** an admin key naming the client, **Then** the same values are returned.
4. **Then** `used` equals the number the create check counts, so a create is refused exactly when `used >= limit`.

### Edge Cases

- A record's group comes from its zone (legacy and API), so records the administrator added to a client's zone count
  for the client.
- Records whose group differs from the zone's (explicit `sys_groupid` from an admin key) count for the group they
  carry, as in legacy.
- DMARC, SPF, CAA, DKIM and TLSA record types count like every other record (legacy checks them in their own forms).
- The legacy zone wizard (`dns_wizard.php`) and the zone import do not check the record cap; the API has neither
  today. A future zone wizard (spec 029) follows its legacy counterpart.
- Keys whose user has no client row are unlimited (as for every other limit).
- Changing a record's type or name is an update and is never refused by the cap.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/records.yaml` (POST description and 403 `limit-reached`),
  `api/modules/usage/summary.yaml` (description).
- **Shared schemas**: `api/components/schemas/UsageSummary.yaml` (`counts.dns_records`, required).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| POST | `/api/v1/dns/records` | + record cap for client and reseller keys (403 `limit-reached`) | 201 |
| GET | `/api/v1/usage/summary` | + `counts.dns_records {used, limit}` | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `dns/dns_edit_base.php` 105–118 (`onSubmit`: user type other than admin,
  limit of the client behind the user's default group, `$this->id == 0`, `count(id) FROM dns_rr WHERE sys_groupid =
  default_group`, refuse when `number >= limit_dns_record`, no check when the limit is negative) and 78–91
  (`onShowNew`, same count for client users); the same code in `dns/dns_caa_edit.php` 119–127,
  `dns/dns_dkim_edit.php` 104–111, `dns/dns_dmarc_edit.php` 216–224, `dns/dns_spf_edit.php` 148–156,
  `dns/dns_tlsa_edit.php` 88–96; `dns/lib/lang/en_dns_a.lng` 9 (`The max. number of DNS records for your account is
  reached.`); `dashboard/dashlets/limits.php` 107 (dashboard counts `dns_rr` by the client's group);
  `dns/dns_wizard.php` / `lib/classes/dns_wizard.inc.php` 152–158 (zone limit only). No `checkResellerLimit` for
  records.
- **Legacy behaviors to mirror**: create only; non-admin users; count by the acting user's default group; negative =
  unlimited; 0 = no records; no reseller cap.
- **Tables written (via datalog only)**: none new; a refused create writes nothing.
- **System fields handling**: unchanged (record `sys_groupid` from the zone).
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-16):
  - The refusal uses the API's typed `limit-reached` problem and its detail text
    (`You have reached the maximum number of DNS records allowed for your account.`) instead of the legacy wording,
    consistent with every other count limit (spec 012/023).
  - `/me/capabilities` is not extended: counts against limits belong to `/usage/summary` (spec 017), which the module
    already reads.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `POST /dns/records` from a client or reseller key MUST be refused with 403 `limit-reached` when the
  acting client's `limit_dns_record` is 0 or more and the number of `dns_rr` rows carrying the key's group is at least
  that limit; `limit` MUST be `{name: limit_dns_record, scope: client, max, used}`.
- **FR-002**: The refusal MUST happen before any write (no `dns_rr` row, no serial bump, no `sys_datalog` row).
- **FR-003**: Admin keys, keys without a client row, record updates and deletes MUST NOT be limited; no reseller cap
  applies.
- **FR-004**: `GET /usage/summary` MUST return `counts.dns_records {used, limit}` with `used` counted by the same rule
  as FR-001 and `limit` null for negative limits.
- **FR-005**: Contract first; feature tests for the create matrix (client, reseller, admin, -1/0/n, update/delete at
  the cap, other client's records), the problem body, no datalog on refusal, and the summary count; the spec 012
  "records are never limited" regression test is replaced.

### Key Entities

- **DNS record** (`dns_rr`): counted by `sys_groupid`.
- **Client limit** (`client.limit_dns_record`, -1 unlimited, 0 none, n cap).
- **Usage count** (`UsageSummary.counts.dns_records`, schema `api/components/schemas/UsageCount.yaml`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 records created by client or reseller keys beyond the plan's record cap across the test matrix.
- **SC-002**: The usage summary's record count equals the count the create check uses in every test case.
- **SC-003**: Existing record updates and deletions are unaffected at the cap (0 refusals in tests).

## Assumptions

- The WHMCS module (spec 005) reads `counts.dns_records` and maps `limit-reached` with `limit.name =
  limit_dns_record` to its own text.
- Existing installations may have clients above their cap; they can keep, edit and delete records but not add new ones
  until they are below the cap (legacy behaviour).
