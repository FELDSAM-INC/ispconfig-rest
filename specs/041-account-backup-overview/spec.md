# Feature Specification: Account-Wide Backup Overview

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: me (account description), reading the sites module's backup data  
**Input**: WHMCS module spec 007 (Usage & Backups) dependency 041: "backups are only listed per website, so an overview showing *last backup* per website would need one call per row, which the consumer's constitution forbids."

## Context

Spec 018 exposes backups as sub-resources of one website: `GET /sites/web-domains/{id}/backups`,
`/backup-jobs` and `/backup-settings`. A customer panel that lists an account's websites and wants to show
"last backup" beside each one would have to call the backup list once per website — ten websites, ten calls
before the page renders. The WHMCS panel forbids per-row API calls on list pages, so its backup overview
currently shows websites only and says nothing about their backups until one is opened.

The data needed for such an overview already exists in one place: `web_backup` rows carry
`parent_domain_id`, `backup_type`, `tstamp`, `filesize` and `filename`, and the server's `backup_dir`
decides whether backups are configured at all. One account-scoped query can answer for every website of the
account at once.

This feature adds a read-only account endpoint that returns, per vhost website of the account, whether
backups are possible there and the newest backup of each type — nothing that the per-website endpoints do
not already disclose to the same key.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the newest backup of every website in one call (Priority: P1)

A customer panel renders its backup overview: the account's websites, each with the date, type and size of
its newest backup, without calling per website.

**Why this priority**: it is the whole reason for the feature; without it the overview is blind and the
consumer must either break its own request budget or show nothing.

**Independent Test**: seed client A with two vhost websites (W1 with a `web` and a `mysql` backup, W2 with
none) and client B with one backed-up website. With A's key, `GET /me/backups` returns exactly W1 and W2,
W1 carrying its newest backup per type and W2 an empty list, and never mentions B's website. One request,
regardless of the number of websites.

**Acceptance Scenarios**:

1. **Given** an account with several vhost websites, **When** its key calls `GET /me/backups`, **Then** 200
   returns `{data, meta}` with one entry per vhost website the key may read, ordered by domain.
2. **Given** a website with a `web` and a `mysql` backup, **When** the overview is read, **Then** its entry
   carries the newest backup of each type with id, created time, size in bytes, format, manual/automatic
   origin, encrypted flag and whether a download copy can be prepared — the same fields the per-website list
   returns for those rows.
3. **Given** a website without backups, **When** the overview is read, **Then** its entry is present with an
   empty `latest` list and a total of 0, not omitted.
4. **Given** websites on a server without a configured backup directory, **When** the overview is read,
   **Then** those entries report `backups_available: false`.
5. **Given** subdomains and alias domains, **When** the overview is read, **Then** they do not appear
   (only `vhost` websites have backups, as in spec 018).
6. **Given** another account's websites, **When** the overview is read, **Then** they never appear.

---

### User Story 2 - The overview follows the same plan gate as the per-website endpoints (Priority: P2)

A customer whose plan excludes backups gets the same refusal from the overview as from the per-website
endpoints, and an administrator can read any client's overview.

**Why this priority**: consistency of the gate; a consumer must not learn from the overview what the
detail endpoints refuse to tell it.

**Independent Test**: client key with `limit_backup = 'n'` → 403 with the plan feature named; admin key
without `client_id` → 422; admin key with a foreign client's id → that client's overview; unknown client →
404.

**Acceptance Scenarios**:

1. **Given** a client key whose client has `limit_backup = 'n'`, **When** it calls the overview, **Then**
   403 problem+json of type `feature-not-allowed` naming `limit_backup`, exactly as spec 018 refuses the
   per-website endpoints.
2. **Given** an admin key, **When** it calls the overview without `client_id`, **Then** 422; **When** it
   passes a known client's id, **Then** that client's overview; **When** it passes an unknown or
   non-numeric id, **Then** 404 or 422 respectively, as the other account endpoints answer.
3. **Given** a reseller key, **When** it calls the overview, **Then** it sees its own websites, and with
   `client_id` one of its own clients' overviews.
4. **Given** any key, **When** it sends an unknown query parameter, **Then** 400, as the other account
   endpoints answer.

---

### Edge Cases

- A website whose backups live on a different server than the website itself: the entry reports the backup
  with `download_available: false`, matching the per-website representation.
- A backup row whose website has been deleted: not reachable — entries are built from the account's
  websites, not from backup rows.
- Many websites: the overview pages like every other list (`limit`, `offset`, `meta.total`), default 25,
  maximum 100, so one page covers a normal account and a large one is explicit about the rest.
- A locked (suspended) account: the overview is a read and stays readable, as spec 019 leaves reads
  untouched.

## API Contract *(mandatory)*

| Method | Path | Purpose | Codes |
|---|---|---|---|
| GET | `/me/backups` | Newest backup per type for each vhost website of the account | 200, 400, 401, 403, 404, 422 |

Query parameters: `client_id` (admin keys: required; reseller keys: optional, one of their clients),
`limit`, `offset`. Unknown parameters are 400.

Response (200):

```json
{
  "data": [
    {
      "web_domain_id": 20,
      "domain": "example.com",
      "server_id": 1,
      "backups_available": true,
      "total": 7,
      "latest": [
        {
          "id": 51,
          "type": "web",
          "created_at": "2026-09-15T00:10:00+02:00",
          "size_bytes": 184320000,
          "format": "tar.gz",
          "job": "auto",
          "encrypted": false,
          "download_available": true,
          "database_name": null
        },
        { "id": 52, "type": "mysql", "created_at": "2026-09-15T00:12:00+02:00", "size_bytes": 20480, "format": "gzip", "job": "auto", "encrypted": false, "download_available": true, "database_name": "c1_shop" }
      ]
    }
  ],
  "meta": { "total": 2, "limit": 25, "offset": 0 }
}
```

`latest` holds at most one entry per backup type present for that website (`web`, `mysql`, `mongodb`),
newest first by creation time. `total` counts all backups of that website visible to the key.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Reads only.** No `sys_datalog` row, no `sys_remoteaction` row, no write of any kind.
- **Visibility** follows spec 018 exactly: a backup belongs to a website (`parent_domain_id`) and is visible
  when its `server_id` is the website's server or one of the website's database servers
  (`WebBackupService::backupServerIds()`), which is how the per-website list already decides.
- **Plan gate**: ISPConfig shows the Backup tab only for vhost websites and only when the client's
  `limit_backup` is `y` (`web_vhost_domain.tform.php`, ported in `RequireBackupAccess`). The overview
  applies the same two rules, so it can never reveal a website whose backup tab legacy would hide.
- **`backups_available`** reuses `WebBackupService::backupsAvailable()` (the server's `backup_dir`), the
  same flag the per-website settings endpoint reports.
- **No new legacy behaviour is invented**: every field of `latest` is produced by the existing
  `backupRepresentation()` port of `plugin_backuplist.inc.php`.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /me/backups` MUST return one entry per `vhost` website the calling key may read, ordered
  by domain ascending, paged with `limit`/`offset` and `meta.total`.
- **FR-002**: Each entry MUST carry `web_domain_id`, `domain`, `server_id`, `backups_available`, `total`
  and `latest`.
- **FR-003**: `latest` MUST contain the newest backup per backup type visible for that website, using the
  same representation as `GET /sites/web-domains/{id}/backups`.
- **FR-004**: Websites without visible backups MUST appear with `latest: []` and `total: 0`.
- **FR-005**: Client and reseller keys MUST be refused with 403 `feature-not-allowed` (`limit_backup`) when
  their client's `limit_backup` is not `y`; admin keys are never limited by it.
- **FR-006**: Admin keys MUST pass `client_id` (422 otherwise); an unknown or foreign client MUST be 404,
  as in the other account endpoints.
- **FR-007**: Unknown query parameters MUST be refused with 400.
- **FR-008**: The endpoint MUST answer with a fixed number of database queries, independent of the number
  of websites in the account (no per-website query).
- **FR-009**: The endpoint MUST NOT expose file system paths, other accounts' data, or server data beyond
  the website's own `server_id`.

### Key Entities

- **Website backup overview entry**: one vhost website of the account plus its backup summary.
- **Backup**: existing `web_backup` row, represented exactly as spec 018 represents it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A consumer renders an account backup overview with **one** HTTP request, whatever the number
  of websites.
- **SC-002**: The number of database queries for the overview does not grow with the number of websites
  (verified by a query-count assertion).
- **SC-003**: Every field of a `latest` entry equals the value the per-website list returns for the same
  backup (verified by comparing both responses in a test).
- **SC-004**: No key can see a website or backup through the overview that the per-website endpoints would
  refuse it.

## Assumptions

- The consumer still opens a website's own backup page for the full list, jobs and settings; the overview
  deliberately carries only what a list page needs.
- `mongodb` backups are represented like the other types; no MongoDB-specific behaviour is added.
- The overview lives in the `me` module because it describes the calling account, like `/me/servers`,
  `/me/capabilities`, `/me/hosting-addresses` and `/me/hosting-links`.
