# Feature Specification: DNS Zone Wizard For Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: dns  
**Input**: User description: "DNS zone wizard for scoped keys: legacy `dns_wizard.php` creates a zone plus its records from a `dns_template` (placeholders {DOMAIN}, {IP}, {NS1}, {NS2}, {EMAIL}, …), visible to clients per the template's own permissions. ispconfig-rest has no wizard and client keys cannot list templates. Specify a readable template list for scoped keys and zone creation from a template with the placeholder values, producing the same zone + records as legacy in ONE change set. The legacy wizard does NOT check `limit_dns_record` — decide and document whether the API enforces the 030 record limit for wizard-created records so a client cannot bypass the limit. Must fit the WHMCS module `specs/005-dns/contracts/ispconfig-rest-calls.md`."

## Context

ISPConfig's DNS wizard is how a zone is normally created: the user picks a template ("Default"), types the domain
and a few values, and gets a complete zone — SOA plus the A, NS, MX and SPF records the provider decided every new
zone should have. The panel offers the wizard to administrators and customers alike.

The API has no equivalent. `POST /dns/soa` creates an empty zone, and the panel or integration then has to invent
the starting records itself: it must know which records the provider wants, in what order, with which TTLs — exactly
the knowledge the provider already recorded in a template. Worse, a customer key cannot even read the templates:
`GET /dns/templates` is row-scoped (spec 011), and the shipped "Default" template is owned by the administrator
group with no world-read permission, so a client key sees an empty list. The legacy wizard ignores row permissions
here and lists every template with `visible = 'Y'` to everyone.

This feature adds the two pieces a panel needs: a template list a scoped key may read, and one call that expands a
template into a zone with its records — the same rows, in the same order, as the panel writes.

It also closes a hole the legacy wizard has: `dns_wizard.php` checks `limit_dns_zone` but never `limit_dns_record`,
so a customer at its record cap can still create a zone with a dozen records through the wizard. Spec 030 added the
record cap to `POST /dns/records`; this feature applies it to the wizard as well.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A customer creates a complete zone in one step (Priority: P1)

A customer adds DNS hosting for a domain in the panel. One call creates the zone and the provider's standard
records, and the name server answers for the domain with the right addresses, name servers, mail route and SPF
record.

**Why this priority**: it is the whole feature — without it a panel must reimplement the provider's zone layout, and
the WHMCS module cannot offer a template choice at all (module spec 005, US2 scenario 3).

**Independent Test**: create a zone from the shipped "Default" template with a client key, then compare the zone row
and its records with what the panel's wizard writes for the same input, and check that all rows share one change
set.

**Acceptance Scenarios**:

1. **Given** a client key and the "Default" template, **When** it sends `POST /dns/soa/from-template` with
   `template_id`, `domain`, `ip`, `ns1`, `ns2` and `email`, **Then** 201 with the created zone, an
   `X-Change-Set-Id` header, and the template's records exist in the zone with the placeholders replaced.
2. **Given** the same request, **Then** the journal contains the zone insert (`dns_soa` `i`, `active = N`), one
   `dns_rr` insert per template record in template order, and the zone activation (`dns_soa` `u`, `active = Y`),
   all with the same change set id.
3. **Given** the created zone, **Then** `origin`, `ns` and `mbox` are dot-terminated and lower-cased, `mbox` has
   `@` replaced by `.`, and the SOA timers come from the template.
4. **Given** a template whose records reference `{IPV6}` and a request with `ipv6`, **Then** the AAAA record carries
   the given address.
5. **Given** an administrator key, **When** it sends the same request with `client_id`, **Then** the zone and all
   records belong to that client's group.
6. **Given** a domain that already has a zone, **Then** 409 and nothing is written.

---

### User Story 2 - The customer can see the templates offered to it (Priority: P1)

The panel shows the provider's zone templates so the customer can pick one ("Default", "Web hosting", "Mail only").

**Why this priority**: without a readable list the wizard cannot be offered; the module currently hard-codes a
standard record set as a stand-in.

**Independent Test**: list templates with client, reseller and administrator keys and compare against the
`visible` flag, not the row permissions.

**Acceptance Scenarios**:

1. **Given** the shipped "Default" template (owned by the administrator group, no world read), **When** a client key
   calls `GET /dns/zone-templates`, **Then** 200 with that template in `data`.
2. **Given** a template with `visible = false`, **Then** it is absent from the list for every key, and using its id
   in the wizard is refused.
3. **Given** the list, **Then** each entry carries the template id, its name and the placeholder fields the wizard
   must ask for (`DOMAIN`, `IP`, `IPV6`, `NS1`, `NS2`, `EMAIL`, `DKIM`, `DNSSEC`), sorted by name, and no template
   text.
4. **Given** `GET /dns/templates` (the administrator management resource), **Then** its row scoping is unchanged: a
   client key still sees no administrator-owned templates there.

---

### User Story 3 - The record cap cannot be bypassed through the wizard (Priority: P1)

A customer whose plan allows a limited number of DNS records cannot create more through the wizard than through the
record endpoint.

**Why this priority**: the record cap (spec 030) is a release gate for the WHMCS module's DNS writes; a wizard that
ignores it makes the cap meaningless.

**Independent Test**: set `limit_dns_record` below the template's record count and create a zone from the template.

**Acceptance Scenarios**:

1. **Given** a client with `limit_dns_record = 3` and a template with 7 records, **When** its key uses the wizard,
   **Then** 403 `limit-reached` with `limit {name: limit_dns_record, scope: client, max: 3, used: 0}`, and no zone,
   no record and no journal entry are written.
2. **Given** the same client with `limit_dns_record = -1` (unlimited), **Then** the zone is created with all
   records.
3. **Given** a client at its zone cap (`limit_dns_zone`), **Then** 403 `limit-reached` with
   `limit.name = limit_dns_zone`, and nothing is written.
4. **Given** an administrator key, **Then** neither cap applies.

---

### User Story 4 - Optional DKIM and DNSSEC as in the panel (Priority: P2)

A customer creating a zone for a domain whose mail is hosted here can have the DKIM record added straight away, and
can ask for the zone to be signed.

**Why this priority**: both are checkboxes in the panel's wizard; they save the customer a manual record and a
second call, but the zone is usable without them.

**Independent Test**: create a zone for a domain with DKIM enabled in mail, with and without the flag.

**Acceptance Scenarios**:

1. **Given** a mail domain the key can read with DKIM enabled, **When** the wizard runs with `dkim: true`, **Then**
   the zone additionally contains a TXT record `<selector>._domainkey.<domain>.` with the published public key.
2. **Given** `dkim: true` for a domain with no DKIM-enabled mail domain the key can read, **Then** the zone is
   created without a DKIM record (no error), as in the panel.
3. **Given** `dnssec: true`, **Then** the zone is created with `dnssec_wanted = true` and the server starts signing.
4. **Given** a template whose `fields` do not offer `DKIM`/`DNSSEC`, **Then** sending those flags is refused with
   422.

---

### Edge Cases

- A template id that exists but is not visible is refused (legacy loads it without checking `visible`, so a posted
  id bypasses the list — see Deviations).
- A template whose text has an unknown section header is refused with 422 naming the template; legacy ends the
  request with a fatal error.
- A template that declares a placeholder in `fields` but never uses it in the text is accepted; the value is simply
  unused.
- A template record row with fewer than five `|`-separated parts (the DKIM row legacy appends is one) gets the
  zone's TTL and priority 0 rather than 0/0 — see Deviations.
- Placeholders in the `[ZONE]` section are replaced as well, so `ns={NS1}.` and `mbox={EMAIL}.` work.
- The zone is created inactive and activated after the records, so the name server never publishes a half-built
  zone; a failure anywhere rolls everything back and leaves no zone.
- Records are written with the zone's server and owning group, regardless of the acting key's own group.
- Zone and record validation of `POST /dns/soa` and `POST /dns/records` is not re-run per record: templates are
  administrator-authored content (see Deviations).

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/dns/zone-templates.yaml` (new), `api/modules/dns/soa.yaml`
  (`/dns/soa/from-template`), `api/modules/dns/_index.yaml`, `api/openapi.yaml`.
- **Shared schemas**: `api/components/schemas/DnsZoneTemplate.yaml` (new),
  `api/components/schemas/DnsZoneFromTemplate.yaml` (new), `api/components/schemas/DnsSoa.yaml` (response).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/dns/zone-templates` | Zone templates offered by the wizard, readable by every key | 200 |
| POST | `/api/v1/dns/soa/from-template` | Creates a zone and its records from a template | 201 |

- **Refusals**: 403 `limit-reached` (`limit_dns_zone`, `limit_dns_record`), 409 duplicate origin, 422 validation
  (missing placeholder value, unknown/invisible template, malformed template, unassigned `server_id` with
  `error_types.server_id = server-not-assigned`).

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test):
  - `dns/dns_wizard.php` 44–55 (zone-limit check for non-admins), 73–83 (`SELECT * FROM dns_template WHERE visible
    = 'Y' ORDER BY name ASC`, offered to every user), 143–167 (a client may only use servers from its
    `dns_servers`), 170–190 (`fields` drive the form; `DNSSEC` hidden when mirrored DNS servers exist), 232–243
    (create on POST).
  - `lib/classes/dns_wizard.inc.php::create()` — server resolution and the "not allowed server" check, IDN + lower
    case filters for `domain`, `ns1`, `ns2`, `email`, the legacy regexes, `limit_dns_zone` client and reseller
    checks, placeholder replacement, the `{DNSSEC}` injection of `dnssec_wanted=Y`, the DKIM lookup
    (`SELECT dkim_public, dkim_selector FROM mail_domain WHERE domain = ? AND dkim = 'y' AND getAuthSQL('r')`,
    selector default `default`, key stripped of PEM headers and newlines), the `[ZONE]`/`[DNS_RECORDS]` parser,
    `mbox` `@` → `.`, `increase_serial(0)`, the insert of `dns_soa` with `active = 'N'`, one `datalogInsert` per
    record with the zone's `server_id`/`sys_groupid` and `active = 'Y'`, and the final
    `datalogUpdate('dns_soa', ['active' => 'Y'])`.
- **Legacy behaviors to mirror**: template visibility rule for the list; placeholder set; zone-then-records-then-
  activate order and its journal entries; `sys_perm` presets `riud`/`riud`/`''`; server restriction to the
  account's assigned DNS servers (spec 016); `limit_dns_zone` for client and reseller.
- **Tables written (via datalog only)**: `dns_soa` (`i`, then `u`), `dns_rr` (`i` per record). Reads:
  `dns_template`, `mail_domain` (DKIM), `client`, `server`.
- **Intentional deviations from legacy** (owner-delegated decision 2026-09-16):
  1. **The record cap applies.** The whole batch is counted against `limit_dns_record` before anything is written
     (legacy checks only the zone cap, so its wizard bypasses the record cap entirely).
  2. **Every placeholder the template declares must have a value.** Legacy skips a value that was not submitted at
     all and then writes the literal `{IP}` into the zone; the API refuses with 422 instead.
  3. **Only visible templates can be used.** Legacy's `create()` loads any `template_id` that is posted, including
     hidden ones.
  4. **A malformed template is a 422**, not a fatal error (`die('Unknown section type')`).
  5. **A short record row inherits the zone's TTL** and priority 0. Legacy reads the missing parts as `null` and
     stores TTL 0 — its own appended DKIM row is such a case.
  6. **Per-record contract validation is not re-run.** Template records are written as the template author wrote
     them (legacy does the same); the type is still restricted to the record types the API knows.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose `GET /dns/zone-templates` listing every `dns_template` row with `visible = true`
  to every key, without the row-level read predicate, sorted by name, with the shared pagination parameters.
- **FR-002**: Each entry MUST carry `id`, `name` and `fields` (the declared placeholder tokens as an array) and
  MUST NOT carry the template text or its system fields.
- **FR-003**: `GET /dns/templates[/{id}]` and the template write endpoints MUST keep their current behaviour
  (row-scoped reads, administrator-only writes).
- **FR-004**: System MUST expose `POST /dns/soa/from-template` taking `template_id`, `domain`, the placeholder
  values `ip`, `ipv6`, `ns1`, `ns2`, `email`, the flags `dkim`, `dnssec`, and optionally `server_id` and
  `client_id`, and MUST answer 201 with the created zone and an `X-Change-Set-Id`.
- **FR-005**: The request MUST require exactly the placeholder values the template declares in `fields`
  (`DOMAIN` is always the `domain` field) and MUST refuse values or flags the template does not declare, with 422.
- **FR-006**: Values MUST be normalized as legacy does — IDN-encoded and lower-cased `domain`, `ns1`, `ns2`,
  `email`; `mbox` with `@` replaced by `.`; `origin`, `ns` and `mbox` dot-terminated — and validated with the
  legacy regexes (`email` as an email address).
- **FR-007**: The zone MUST be placed on a DNS server assigned to the account when `server_id` is omitted, and an
  unassigned or nonexistent server MUST be refused per spec 016 (422, `error_types.server_id =
  server-not-assigned`); administrator keys may name any primary DNS server.
- **FR-008**: The zone's owning group MUST follow the acting key (spec 011/024); administrator and reseller keys
  MAY set it with `client_id`, and every created record MUST inherit the zone's group and server.
- **FR-009**: Creation MUST run in one transaction and one change set, writing the zone inactive, then the records
  in template order, then activating the zone; a failure MUST leave no zone and no record.
- **FR-010**: The account's `limit_dns_zone` and `limit_dns_record` caps (client and, where legacy has one, the
  parent reseller's) MUST be enforced before any write; a refusal MUST be 403 `limit-reached` with the
  spec 023 `limit` members and MUST write nothing.
- **FR-011**: A duplicate `origin` MUST be refused with 409, as `POST /dns/soa` does.
- **FR-012**: `dkim: true` MUST append the DKIM TXT record of the domain's DKIM-enabled mail domain when the key
  can read it, and MUST be a no-op otherwise; the record MUST carry the published public key with PEM headers and
  line breaks removed, and the selector MUST default to `default`.
- **FR-013**: `dnssec: true` MUST create the zone with `dnssec_wanted = true`.
- **FR-014**: An unknown, invisible or malformed template MUST be refused with 422 on `template_id`.
- **FR-015**: Tests MUST cover: the template list for client, reseller and administrator keys including an
  invisible template; a full expansion with journal order, change set and row contents; placeholder validation;
  both caps; duplicate origin; DKIM with and without a readable mail domain; DNSSEC; server assignment; and that
  `GET /dns/templates` scoping is unchanged.

### Key Entities

- **DNS zone template** (`dns_template`): `name`, `fields` (placeholder tokens), `template` (the `[ZONE]` and
  `[DNS_RECORDS]` text), `visible`.
- **DNS zone** (`dns_soa`) and **records** (`dns_rr`) created from it.
- **Mail domain** (`mail_domain`): source of the optional DKIM record.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A panel creates a provider-standard zone with one API call instead of one call per record, with no
  knowledge of the provider's record layout.
- **SC-002**: A zone created from the "Default" template through the API has the same rows as one created through
  the ISPConfig wizard with the same input (verified field by field on isp-test).
- **SC-003**: A customer cannot exceed `limit_dns_record` or `limit_dns_zone` through the wizard.
- **SC-004**: A client key can list the provider's visible zone templates, while administrator-owned templates stay
  invisible in the management resource.
- **SC-005**: A failed creation leaves no zone, no record and no journal entry.

## Assumptions

- Providers keep their customer-facing templates `visible`; hidden templates are drafts and stay unusable.
- The WHMCS module (spec 005) replaces its hard-coded standard record set with the template choice once this ships
  (module task T049).
- Zone signing itself is unchanged here: `dnssec_wanted` is only set at creation; managing it afterwards is spec
  032.
- Record contents come from provider-authored templates, so they are trusted the way the panel trusts them.

## Out of Scope

- Editing templates through a customer key (administrator-only, unchanged).
- The legacy domain-module (`use_domain_module`) variant of the wizard, which restricts the domain to a list
  maintained in the domain module.
- Secondary (slave) zones, zone import and `dns_import.php`.
- DNSSEC status, DS records and switching it afterwards (spec 032).
