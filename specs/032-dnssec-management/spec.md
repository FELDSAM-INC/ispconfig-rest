# Feature Specification: DNSSEC Management For Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: dns  
**Input**: User description: "DNSSEC management for scoped keys: expose DNSSEC for a client key on its own zone — read status plus enable/disable, and the DS/DNSKEY data a customer must give to the registrar, derived from the legacy fields the server fills in. Check what the server plugin needs and whether a client may do this in legacy at all; if legacy is admin-only, make it plan-gated and enforce with 023 `feature-not-allowed`. Never return private key material to non-admin keys. Must fit the WHMCS module `specs/005-dns/contracts/ispconfig-rest-calls.md`."

## Context

Signing a zone with DNSSEC is only half a job: after the name server generates the keys, the domain's owner has to
hand a **DS record** to the registrar, or the signatures are never trusted. ISPConfig does the first half
automatically and shows the second half as a read-only text area on the zone form.

The API exposes the raw columns and nothing else. `dnssec_wanted` and `dnssec_algo` are already writable by every
key — spec 033 confirmed them as "still writable", and the zone wizard (spec 029) was verified on isp-test creating
a signed zone with a client key — but a consumer that wants to *show* DNSSEC has only `dnssec_info`, a blob of
server text: a `DS-Records:` section, a dashed separator, then `DNSKEY-Records:` with comment lines. A panel cannot
render that, and on installations running PowerDNS the very same column additionally carries a raw log of the
`pdnsutil` commands the plugin ran — output the plugin itself notes will trip the intrusion detection if it is ever
posted back.

There is also a gate nobody enforces. ISPConfig hides the whole DNSSEC block when the zone's DNS server has mirrors,
because the signing it does would not reach the mirrors. The API accepts `dnssec_wanted` there anyway: the flag is
stored, the plugin never signs, and the customer waits for a DS record that never appears.

This feature turns the blob into data, and the hidden block into an honest refusal.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A customer gets the DS record for its registrar (Priority: P1)

A customer switches DNSSEC on for its zone and, once the name server has signed it, copies the DS record from the
panel into the registrar's form.

**Why this priority**: without the DS record, enabling DNSSEC achieves nothing — this is the entire point of the
feature, and the WHMCS module's DNSSEC screen (module spec 005, US7) is blocked on it.

**Independent Test**: enable DNSSEC on a zone with a client key, wait for the server to sign, read the sub-resource
and compare the DS values with the `dsset-` file the name server wrote.

**Acceptance Scenarios**:

1. **Given** a signed zone, **When** its client key reads `GET /dns/soa/{id}/dnssec`, **Then** 200 with
   `state` `signed`, the signing algorithm, when it was last signed, and `ds_records` containing `key_tag`,
   `algorithm`, `digest_type`, `digest` and a ready-to-paste `record` line.
2. **Given** the same zone, **Then** `dnskey_records` lists the published keys with `flags`, `protocol`,
   `algorithm`, `public_key` and `type` (`ksk` for flags 257, `zsk` for 256).
3. **Given** a zone with DNSSEC requested but not yet signed, **Then** `state` is `pending` and the record lists are
   empty.
4. **Given** a zone with DNSSEC off, **Then** `state` is `off` and the record lists are empty.
5. **Given** a zone of another client, **Then** 404 — as for a zone that does not exist.

---

### User Story 2 - A customer switches signing on and off (Priority: P1)

The customer turns DNSSEC on for a zone, and later off again when moving the domain away.

**Why this priority**: the read is useless without the switch; the switch already works but is undocumented and
ungated.

**Independent Test**: `PUT /dns/soa/{id}` with `dnssec_wanted` true and false with a client key, and check the
stored flag, the journal entry and what the name server does.

**Acceptance Scenarios**:

1. **Given** a zone of a client, **When** its key sends `PUT /dns/soa/{id}` with `dnssec_wanted: true`, **Then**
   200, the flag is journaled, and the name server signs the zone.
2. **Given** a signed zone, **When** the key sends `dnssec_wanted: false`, **Then** 200 and the name server stops
   publishing the signed zone.
3. **Given** a zone switched off again, **Then** the sub-resource reports `state` `off`, and the keys the server
   generated stay on disk (they are only removed when the zone itself is removed) — the customer can switch signing
   back on with the same DS record.
4. **Given** `dnssec_algo` outside the two algorithms ISPConfig supports, **Then** 422.

---

### User Story 3 - DNSSEC is not offered where the installation cannot sign (Priority: P2)

On an installation whose DNS servers are mirrored, the panel does not offer DNSSEC at all, and a customer is not
left waiting for a DS record that will never come.

**Why this priority**: it prevents a silent dead end, but only affects mirrored installations.

**Independent Test**: point a zone at a DNS server that has a mirror, read the sub-resource and try to enable
DNSSEC.

**Acceptance Scenarios**:

1. **Given** a zone whose DNS server has at least one mirror, **When** any key reads the sub-resource, **Then**
   `available` is false and `state` is `unavailable`.
2. **Given** the same zone, **When** any key sends `dnssec_wanted: true`, **Then** 422 with
   `errors.dnssec_wanted` and `error_types.dnssec_wanted` = `feature-not-allowed`, and nothing is journaled.
3. **Given** such a zone that already has `dnssec_wanted` set, **When** a key re-sends the stored value or sets it
   to false, **Then** the request is accepted (switching off is always allowed).
4. **Given** an installation without mirrors, **Then** `available` is true for every zone and enabling works.

---

### User Story 4 - Key material and server logs never reach a customer (Priority: P2)

Whatever the name server writes into its DNSSEC notes, a customer only ever sees public DNS data.

**Why this priority**: on PowerDNS the notes contain a raw command log; private keys live on the server, but the
column is a blob whose content depends on the plugin.

**Independent Test**: read a zone and its DNSSEC sub-resource with a client key and with an admin key, and compare.

**Acceptance Scenarios**:

1. **Given** a signed zone, **When** a client or reseller key reads `GET /dns/soa/{id}`, **Then** `dnssec_info` is
   `null`; the parsed sub-resource is the supported way to read DNSSEC data.
2. **Given** the same zone, **When** an administrator key reads it, **Then** `dnssec_info` is returned unchanged.
3. **Given** a sub-resource response of any key, **Then** it contains no private key, no file path and no command
   text — only the DS and DNSKEY values and the state fields.

---

### Edge Cases

- A zone whose `dnssec_info` is empty while `dnssec_initialized` is set (the server has not written its notes yet)
  reports `state` `pending` with empty lists rather than failing.
- Notes the parser does not recognise (a PowerDNS log without a DS section, a future format) yield empty lists and
  `state` `signed` — the flags remain the source of truth for the state.
- Digests that the server wrapped across lines are joined; comment lines (`;`) are ignored.
- A zone switched off after signing keeps `dnssec_initialized` and its notes in the database (bind parity); the
  sub-resource still reports `off`, because the flag is what the customer asked for.
- The `.signed` zone file and the key files are removed by ISPConfig only when the zone is deleted — spec 034's
  cascade already covers that, and this feature adds no cleanup of its own.
- DNSSEC state fields stay read-only on the zone resource: only `dnssec_wanted` and `dnssec_algo` are writable.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/soa.yaml` (`/dns/soa/{id}/dnssec`, plus the mirror rule on PUT),
  `api/openapi.yaml`.
- **Shared schemas**: `api/components/schemas/DnsSoaDnssec.yaml` (new),
  `api/components/schemas/DnsSoa.yaml` (`dnssec_info` description).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/dns/soa/{id}/dnssec` | DNSSEC state with the DS and DNSKEY records for the registrar | 200 |

- **Refusals**: 422 `errors.dnssec_wanted` with `error_types.dnssec_wanted` = `feature-not-allowed` when the zone's
  DNS server is mirrored; 404 for a zone the key cannot read.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test):
  - `dns/dns_soa_edit.php` 92–102 (existing zone: `SELECT count(*) FROM server WHERE mirror_server_id = ?` with the
    zone's server; new zone: `mirror_server_id > 0 AND dns_server = 1`) and 157–168 (the same check over the
    client's `dns_servers`), both setting `show_dnssec`; `dns/templates/dns_soa_edit.htm` 155–172 (the whole DNSSEC
    block, including the read-only `dnssec_info` text area, lives inside `<tmpl_if name="show_dnssec">`).
  - `dns/form/dns_soa.tform.php` 294–318 (`dnssec_wanted` checkbox, `dnssec_algo` set, `dnssec_info` read-only).
  - `server/plugins-available/bind_plugin.inc.php` 79–127 (`soa_dnssec_create`: keys per algorithm, then sign),
    130–194 (`soa_dnssec_sign`: writes `DS-Records:` + `DNSKEY-Records:` into `dnssec_info`, sets
    `dnssec_initialized` and `dnssec_last_signed` with a direct UPDATE), 230–267 (`soa_dnssec_delete`: removes key,
    signed-zone and dsset files and clears the notes — only on origin change or zone removal), 392–406 (the
    wanted/initialized state machine; switching off only deletes the `.signed` file).
  - `server/plugins-available/powerdns_plugin.inc.php` 491–520 (`handle_dnssec`, `soa_dnssec_disable`) and 527–560
    (`dnssec_info` assembled from the public keys **plus a raw command log**).
- **Legacy behaviors to mirror**: the mirror-server rule; DNSSEC is available to customers, not only
  administrators (no client limit column exists for it); switching off keeps the generated keys.
- **Tables written (via datalog only)**: none by this feature. `dns_soa` writes stay the existing `PUT` path.
  Reads: `dns_soa`, `server`.
- **Intentional deviations from legacy** (owner-delegated decision 2026-09-16):
  1. **A hidden DNSSEC block becomes a refusal.** Legacy simply omits the fields when the DNS server is mirrored
     and silently stores whatever a remote API client sends; the API refuses the enable with a typed 422 so the
     caller learns why.
  2. **The raw notes are administrator-only.** Legacy shows the `dnssec_info` text area to any user who may open
     the zone form. The API returns `null` for client and reseller keys and offers the parsed sub-resource instead,
     because the same column carries a raw `pdnsutil` command log on PowerDNS installations.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose `GET /dns/soa/{id}/dnssec` to every key that may read the zone, returning
  `zone_id`, `origin`, `state`, `available`, `wanted`, `initialized`, `algorithm`, `last_signed`, `ds_records` and
  `dnskey_records`.
- **FR-002**: `state` MUST be `off` (not requested), `pending` (requested, not signed yet), `signed` (signed) or
  `unavailable` (the zone's DNS server is mirrored).
- **FR-003**: `ds_records` MUST contain, per DS line the server published, `key_tag`, `algorithm`, `digest_type`,
  `digest` (whitespace removed) and `record`, the full line ready to hand to a registrar.
- **FR-004**: `dnskey_records` MUST contain, per DNSKEY line, `flags`, `protocol`, `algorithm`, `public_key`,
  `type` (`ksk` when flags are 257, `zsk` when 256, otherwise `other`) and `record`; comment lines MUST be ignored.
- **FR-005**: Unparsable or empty notes MUST yield empty lists without failing the request.
- **FR-006**: `available` MUST be false exactly when the zone's DNS server has at least one mirror
  (`server.mirror_server_id = <the zone's server>`), for every key type.
- **FR-007**: A request that sets `dnssec_wanted` to true on a zone where `available` is false MUST be refused with
  422, `errors.dnssec_wanted` and `error_types.dnssec_wanted` = `feature-not-allowed`, and MUST write nothing;
  re-sending the stored value and switching off MUST stay accepted.
- **FR-008**: `dnssec_info` MUST be `null` in every zone response for client and reseller keys and unchanged for
  administrator keys; the other DNSSEC state fields stay readable for all keys.
- **FR-009**: The sub-resource MUST NOT expose private keys, file paths or command output.
- **FR-010**: Enabling and disabling DNSSEC MUST remain `PUT /dns/soa/{id}` with `dnssec_wanted` (and optionally
  `dnssec_algo`), unchanged for administrator keys.
- **FR-011**: Tests MUST cover: the four states; DS and DNSKEY parsing including a wrapped digest, comments and a
  PowerDNS-style log; scoping (another client's zone 404); the mirror rule for read and write with client,
  reseller and admin keys; `dnssec_info` masking per key type; and that no write is journaled by a refusal.

### Key Entities

- **DNS zone** (`dns_soa`): `dnssec_wanted`, `dnssec_algo`, `dnssec_initialized`, `dnssec_last_signed`,
  `dnssec_info`.
- **Server** (`server`): `mirror_server_id`, `dns_server` — the availability rule.
- **DS record / DNSKEY record**: the published public data parsed out of the server's notes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A panel shows a customer the DS record for its registrar using only that customer's own key.
- **SC-002**: The DS values returned match the `dsset-` file the name server wrote, byte for byte after whitespace
  removal.
- **SC-003**: No private key, file path or command output appears in any response to a client or reseller key.
- **SC-004**: Enabling DNSSEC on a mirrored installation is refused with a machine-readable reason instead of being
  stored and ignored.
- **SC-005**: Switching DNSSEC off and on again does not change the DS record the customer already gave the
  registrar.

## Assumptions

- ISPConfig's server plugins remain the only writers of `dnssec_info`, `dnssec_initialized` and
  `dnssec_last_signed`; the API never writes them.
- Providers who mirror their DNS servers do not expect DNSSEC to work there, matching the panel.
- The WHMCS module (spec 005) builds its DNSSEC screen on this sub-resource (module task T092).

## Out of Scope

- Generating, rotating or importing DNSSEC keys through the API (ISPConfig's server plugin owns the key lifecycle).
- Publishing the DS record to a registrar or to a parent zone automatically.
- CDS/CDNSKEY records and algorithm rollover.
- DNSSEC for secondary (slave) zones.
