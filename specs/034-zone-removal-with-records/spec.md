# Feature Specification: Zone Removal With Records

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: dns  
**Input**: User description: "Zone removal with records: legacy `dns_soa_del.php` deletes the zone's records with the zone (datalog d for each); the API returns 400 when records exist (documented deviation in README). Change to legacy parity: DELETE /dns/soa/{id} removes records then the zone in one change set, for all keys with delete permission (scoped per 011/024); update README 'Known deviations' and contract."

## Context

Deleting a zone in ISPConfig deletes everything in it: the form deactivates the zone, deletes every resource record
of the zone and then the zone itself. The API refuses instead — `DELETE /dns/soa/{id}` answers 400 "Cannot delete
zone that contains DNS records (N associated records)" — a deviation declared in the contract and the README.

A panel therefore has to list every record, delete them one by one and only then delete the zone: many calls, a
partially deleted zone when one call fails, and a customer-visible error the ISPConfig panel never shows. This
feature restores the legacy behaviour.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Customer removes a zone in one request (Priority: P1)

A customer deletes a DNS zone in the panel. The zone and all its records disappear, and the name server stops
answering for the domain.

**Why this priority**: it is the last DNS deviation from the panel and forces the WHMCS module into a multi-call
removal flow.

**Independent Test**: create a zone with several records, delete it with the owning client's key, and check the
response, the remaining rows and the journal entries.

**Acceptance Scenarios**:

1. **Given** a zone with 5 records, **When** its client key sends `DELETE /dns/soa/{id}`, **Then** 204, no `dns_rr`
   row of that zone and no `dns_soa` row remain, and the response carries the change-set header.
2. **Given** the same deletion, **Then** the journal contains, in order, one update of the zone marking it inactive,
   one delete per record and one delete of the zone, all in the same change set (legacy `dns_soa_del.php`).
3. **Given** a zone without records, **Then** 204 with the same entries minus the record deletions.
4. **Given** a failure while deleting, **Then** nothing is written (one transaction).
5. **Given** the zone is gone, **When** the same delete is repeated, **Then** 404.

---

### User Story 2 - Only keys that may delete the zone may remove it (Priority: P1)

The cascade must not become a way around the scoping rules.

**Why this priority**: deleting another tenant's zone would be a tenant-isolation failure.

**Independent Test**: delete zones of another client and of a managed client with client, reseller and admin keys.

**Acceptance Scenarios**:

1. **Given** a zone of another client, **When** a client key deletes it, **Then** 404 and nothing is written.
2. **Given** a zone of a managed client, **When** the reseller's key deletes it, **Then** 204 and the records are
   gone too.
3. **Given** any zone, **When** an admin key deletes it, **Then** 204.
4. **Given** a locked client (spec 019), **Then** the existing lock rules are unchanged.

### Edge Cases

- Records whose `sys_groupid` differs from the zone's (an administrator moved them) are deleted with the zone, as
  legacy deletes every `dns_rr` row of the zone regardless of ownership.
- The SOA serial is not bumped per deleted record (legacy deletes the rows directly, without `dns_rr_del.php`'s
  serial bump) — the zone is deactivated and then removed instead.
- Zones with many records produce one journal entry per record, as in the panel.
- Secondary zones (`dns_slave`) are unrelated and untouched.
- `DELETE /dns/records/{id}` keeps its own behaviour (serial bump included).

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/soa.yaml` (DELETE: cascade, no 400).
- **Shared schemas**: none.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| DELETE | `/api/v1/dns/soa/{id}` | Deletes the zone's records and the zone in one change set | 204 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `dns/dns_soa_del.php` 41–51 (`onBeforeDelete`: `checkPerm($this->id,
  'd')`, `datalogUpdate('dns_soa', ['active' => 'N'], 'id', $id)`, then `SELECT id FROM dns_rr WHERE zone = ?` and
  `datalogDelete('dns_rr', 'id', $rec['id'])` for each), followed by `tform_actions::onDelete()`'s own
  `datalogDelete('dns_soa', …)`; `dns/dns_rr_del.php` 53–60 (the per-record serial bump that the cascade does not
  use).
- **Legacy behaviors to mirror**: deactivate, delete records, delete zone — in that order, all journaled.
- **Tables written (via datalog only)**: `dns_soa` (`u` then `d`), `dns_rr` (`d` per record).
- **System fields handling**: unchanged.
- **Intentional deviations from legacy**: none. The previous deviation (400 for non-empty zones) is removed from the
  contract and the README.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `DELETE /dns/soa/{id}` MUST delete every `dns_rr` row of the zone and then the zone, in one database
  transaction and one change set, for every key that may delete the zone (spec 011/024 scoping unchanged).
- **FR-002**: The zone MUST first be journaled as inactive (`active = N`, datalog `u`), then each record (datalog
  `d`), then the zone (datalog `d`) — the legacy order.
- **FR-003**: The 400 refusal MUST be removed from the implementation, the contract and the README deviation list.
- **FR-004**: A key that may not delete the zone MUST still get 404 and write nothing.
- **FR-005**: Tests: cascade with records, empty zone, journal entry order and change set, scoping for client,
  reseller and admin keys, repeated delete 404.

### Key Entities

- **Zone** (`dns_soa`) and its **records** (`dns_rr.zone`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A zone with any number of records is removed with one API call (previously 1 + N).
- **SC-002**: No orphaned `dns_rr` rows remain after a zone deletion in any test case.
- **SC-003**: The journal of an API zone deletion matches what the ISPConfig panel writes for the same zone.

## Assumptions

- The WHMCS module (spec 005) replaces its record-by-record removal flow with the single call once this ships.
- Consumers relying on the 400 as a safety net must confirm the deletion themselves (the panel does the same).
