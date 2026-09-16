# Feature Specification: Backup Download for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: sites (website backups)  
**Input**: WHMCS module spec 007 (Usage & Backups) dependency 042: "spec 018 delivers a copy into the website's `backup` folder (FTP/SSH only); a customer without file access cannot fetch it."

## Context

Spec 018 exposes ISPConfig's own "download" behaviour: `POST /sites/web-domains/{id}/backups/{backup_id}/download`
queues a `backup_download` remote action, the server copies the archive into the website's own `backup`
folder, and the customer is expected to fetch it over FTP or SSH. A hosting panel customer who never uses
FTP cannot get their backup at all.

**What ISPConfig itself can do was checked on isp-test first, and it is less than one would hope:**

- ISPConfig has **no HTTP download anywhere**. Its backup list (`interface/lib/classes/plugin_backuplist.inc.php:113-125`)
  only inserts the `backup_download` action; nothing streams a file to the browser.
- The archives live in `<server.backup_dir>/web<domain_id>` (`server/plugins-available/backup_plugin.inc.php:78-80`).
  On isp-test that directory is `root:root` mode 0700 and the archives inside are mode 0700, owned by root.
- The delivered copy is written to `<document_root>/backup/<filename>`
  (`server/lib/classes/backup.inc.php:988, 1099`), then `chown -h <system_user>:<system_group>` and
  `chmod 0640` (lines 1105-1106), inside a folder that `secureBackupDir()` forces to `root:<system_group>`
  mode 0750 (lines 116-136).
- The API runs as **www-data** (php-fpm pool `ispconfig-rest.conf`, served by Apache on :8090) and has **no
  privileged component**: no systemd unit of its own, no root worker, no queue runner.
- Copies are purged after three days ("delete files older than 3 days", `backup.inc.php:1820`), which is the
  `available_until` spec 018 already documents.

The archive under `/var/backup` is therefore out of reach for the API. The **delivered copy** is not: ISPConfig
adds the web server user to every client group (`id www-data` lists `client0, client1, client19, …`), which is how
Apache serves the 0750 client directories, so a copy at `<system_user>:<system_group>` 0640 is readable by the API
on a stock installation. Verified live on 2026-09-16: the endpoint streamed a copy whose SHA-256 matched the file
on disk byte for byte.

This feature therefore streams the prepared copy — never the archive — and says precisely why it cannot when a
particular installation, a hardened permission set or a multi-server layout puts the copy out of reach. The FTP/SSH
delivery of spec 018 remains available and is the only route on those installations.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Fetch a prepared backup over HTTP (Priority: P1)

A customer whose provider has granted the API read access to website backup folders clicks "Download" in the
panel and receives the archive in the browser, without FTP.

**Why this priority**: it is the capability the consumer asked for; without it the panel can only explain a
folder path.

**Independent Test**: seed website W of client A with a backup and a prepared copy readable by the test
process; `GET /sites/web-domains/{W}/backups/{id}/download` with A's key returns 200, the archive's bytes,
`Content-Type: application/octet-stream`, a `Content-Disposition` naming the file, and `Content-Length`
matching the file. Client B's key gets 404 for the same URL.

**Acceptance Scenarios**:

1. **Given** a prepared, readable copy of a backup of the key's own website, **When** the key downloads it,
   **Then** 200 streams the file with `Content-Disposition: attachment; filename="<name>"`, the correct
   `Content-Length`, and bytes identical to the file on disk.
2. **Given** the same request, **When** the response is produced, **Then** no file system path appears in any
   header or body, and nothing is written to `sys_datalog` or `sys_remoteaction`.
3. **Given** a backup of a website the key may not read, **When** it is downloaded, **Then** 404 — the same
   answer an unknown backup id gives.
4. **Given** a client whose plan excludes backups, **When** it downloads, **Then** 403 `feature-not-allowed`
   with `feature: limit_backup`, as every other backup endpoint answers.
5. **Given** a `HEAD` request for the same URL, **When** it is answered, **Then** the same headers are
   returned without a body, so a consumer can show the size before downloading.

---

### User Story 2 - Be told exactly why a download is not possible (Priority: P1)

A customer on an installation where the API cannot read backup copies is told to use the folder, instead of
being handed a broken button.

**Why this priority**: a copy has to be prepared before it can be fetched, so "not prepared yet" is the common
first answer; and on hardened or multi-server installations the copy may never be readable here. A consumer must be
able to decide what to show before offering the action.

**Independent Test**: with no prepared copy → 409 `download-not-prepared`; with a copy present but unreadable
by the API process → 409 `download-not-readable`; both with a `download` object describing the folder
delivery. The backup representation reports the same state, so a panel can hide the button up front.

**Acceptance Scenarios**:

1. **Given** a backup whose copy has not been prepared, **When** it is downloaded, **Then** 409 problem+json
   of type `download-not-prepared`, telling the consumer to request preparation first.
2. **Given** a prepared copy the API process cannot read, **When** it is downloaded, **Then** 409
   problem+json of type `download-not-readable`, stating that this installation delivers backups to the
   website's `backup` folder over FTP/SSH — without naming a path.
3. **Given** a backup stored on a different server than the website, **When** it is downloaded, **Then** 409
   `download-not-readable` as well, since the copy can never appear on this server
   (`download_available: false` in the representation).
4. **Given** any backup, **When** it is read through the backup endpoints, **Then** its representation carries
   a `download` object with `state` (`unavailable`, `not_prepared`, `preparing`, `ready`), `filename` and
   `available_until` when a copy exists, and `http` (boolean) saying whether this API can stream it.
5. **Given** a copy that has expired (older than the three-day retention), **When** it is downloaded,
   **Then** 409 `download-not-prepared`, because the file is gone.

---

### Edge Cases

- A backup deleted between listing and downloading: 404.
- A copy that exists but is empty or truncated: streamed as-is with its real length; the API does not judge
  archive contents.
- A locked (suspended) account: downloading is a read and stays allowed, exactly as spec 018 allows
  preparation while locked.
- Concurrent downloads of the same file: both succeed; the endpoint only reads.
- Very large archives: streamed, never loaded into memory.
- Symlink in the website's backup folder: refused — the API resolves the real path and requires it to stay
  inside the configured folder, so a customer with shell access cannot turn the endpoint into a file reader.

## API Contract *(mandatory)*

| Method | Path | Purpose | Codes |
|---|---|---|---|
| GET | `/sites/web-domains/{id}/backups/{backup_id}/download` | Stream the prepared copy of the backup | 200, 401, 403, 404, 409 |
| HEAD | same | Headers only (size, filename) | 200, 401, 403, 404, 409 |

Success headers: `Content-Type: application/octet-stream`, `Content-Length`, `Content-Disposition:
attachment; filename="<archive name>"`, `Cache-Control: private, no-store`.

The existing `POST …/backups/{backup_id}/download` (prepare a copy) is unchanged; this feature adds the
retrieval half and the state a consumer needs to choose between them.

Backup representation gains:

```json
"download": {
  "state": "ready",
  "http": true,
  "filename": "manual-web28_2026-09-16_03-32.tar.gz",
  "available_until": "2026-09-19T03:32:08+02:00"
}
```

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Reads only.** No `sys_datalog` row and no `sys_remoteaction` row is written by the download itself; the
  preparation action of spec 018 is untouched.
- **No legacy equivalent exists**: ISPConfig offers no HTTP download, so this endpoint adds a capability
  rather than mirroring one. It deliberately reuses legacy's own delivery artefact — the copy in
  `<document_root>/backup` — instead of inventing a second location, so a file downloaded here is exactly the
  file an FTP user would fetch.
- **Permissions are respected, never widened.** The API streams only files its own process can already read;
  it never changes ownership or mode, never runs a privileged helper, and never reads the backup directory
  itself (`root:root` 0700).
- **Scoping** follows specs 011/018/024: the backup must belong to a website the key may read, and the plan's
  `limit_backup` gate applies exactly as elsewhere.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET`/`HEAD /sites/web-domains/{id}/backups/{backup_id}/download` MUST stream the prepared copy of
  that backup when the API process can read it, with `Content-Length`, `Content-Disposition` and an
  octet-stream content type.
- **FR-002**: The endpoint MUST resolve the file only inside the website's configured `backup` folder, MUST
  reject symlinks and any path that escapes it, and MUST never disclose a path in a response or log.
- **FR-003**: A missing or expired copy MUST be refused with 409 `download-not-prepared`.
- **FR-004**: A present but unreadable copy, and a backup stored on another server, MUST be refused with 409
  `download-not-readable`.
- **FR-005**: The plan gate and row scoping MUST behave exactly as for the other backup endpoints (403
  `feature-not-allowed` / 404).
- **FR-006**: Backup representations MUST carry a `download` object with `state`, `http`, and — when a copy
  exists — `filename` and `available_until`.
- **FR-007**: The download MUST NOT write any journal or remote-action row.
- **FR-008**: Large files MUST be streamed without loading them into memory.
- **FR-009**: The documentation MUST state plainly where the download works (the delivered copy is readable
  because ISPConfig puts the web server user in every client group), where it does not (hardened permissions,
  multi-server layouts), and that the API never changes permissions to make itself able to read.

### Key Entities

- **Prepared copy**: the archive `backup_download` placed in `<document_root>/backup`, owned by the website's
  system user, mode 0640, removed after three days.
- **Download state**: derived from the backup row, the website's server, the copy's presence and the API
  process's ability to read it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On an installation where the copy is readable by the API, a consumer downloads a backup over
  HTTP and the received bytes are identical to the file on disk (verified by checksum).
- **SC-002**: When a copy is missing or unreachable, the endpoint answers 409 with a type that tells the consumer
  which of the two situations applies, and the panel can decide from the representation alone whether to offer the
  button.
- **SC-003**: No request to the endpoint produces a `sys_datalog` or `sys_remoteaction` row.
- **SC-004**: No response, header or log line contains a file system path.
- **SC-005**: A backup belonging to another account is indistinguishable from a nonexistent one (404).

## Assumptions

- The copy-to-folder preparation of spec 018 stays the default delivery on installations that do not opt in.
- No change is made to ISPConfig's permissions and no privileged helper is introduced; the endpoint reads only
  what the API process may already read by ISPConfig's own group design.
- Range requests are out of scope for this version; the endpoint streams the whole file.
- Mail backups are out of scope, as in spec 018.
