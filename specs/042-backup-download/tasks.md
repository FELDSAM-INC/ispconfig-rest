# Tasks: Backup Download for Scoped Keys

**Feature**: 042 | **Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md)

## Format: `[ID] [P?] [Story] Description`

- `[P]` = may run in parallel with the other `[P]` tasks of the same phase (different files, no shared state)
- `[Story]` = the user story the task serves (US1, US2) or `—` for shared work

## Path Conventions (this project)

Contract in `api/` and `docs/problems.md`, code in `app/`, routes in `routes/api/`, tests in `tests/Feature/`.
The OpenAPI specification is the source of truth and lands before the code.

## Phase 1: Setup

- [x] T001 — Confirm the baseline suite is green (`php artisan test`, expect 1276 passing on `ceba2a9`).

## Phase 2: Foundational (Blocking Prerequisites)

Contract and problem vocabulary first (constitution I); nothing in `app/` before these land.

- [x] T002 [P] — Add `DOWNLOAD_NOT_PREPARED` and `DOWNLOAD_NOT_READABLE` to `app/Support/ProblemType.php`, including `NAMES`.
- [x] T003 [P] — Create `api/components/schemas/WebBackupDownload.yaml`: `state` (`unavailable`, `not_prepared`, `preparing`, `ready`), `http`, `filename` (nullable), `available_until` (nullable date-time), each documented per data-model.md.
- [x] T004 — Add the `download` object to `api/components/schemas/WebBackup.yaml` (required, `$ref` to the new schema), so list and show both carry it.
- [x] T005 — Add the `GET`/`HEAD /sites/web-domains/{id}/backups/{backup_id}/download` operations to `api/modules/sites/web-backups.yaml`: octet-stream 200 with `Content-Length`, `Content-Disposition` and `Cache-Control`, plus 401/403/404/409 with both new types; state that `download.http` is a property of the installation.
- [x] T006 — Document both new types in `docs/problems.md` in the house format (heading, **Status**, prose, extension members, what raises them).
- [x] T007 — Commit the contract phase (`Add the backup download contract and problem types`) and push.

## Phase 3: User Story 1 - Fetch a prepared backup over HTTP (Priority: P1) 🎯 MVP

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T008 [US1] — Create `tests/Feature/WebBackupDownloadApiTest.php` on `WebBackupApiTestCase`, with a real temporary `document_root` per test: key required (401); a readable copy downloads with byte-identical content, `Content-Length`, `Content-Disposition` naming the archive, octet-stream type and `Cache-Control: private, no-store`.
- [x] T009 [P] [US1] — Add: `HEAD` returns the identical headers with an empty body; a large-ish file (a few MB) still streams and the response is not buffered in memory.
- [x] T010 [P] [US1] — Add: no `sys_datalog` and no `sys_remoteaction` row is written by a download (FR-007, SC-003); no response header, body or problem detail contains the document root or any path (SC-004).
- [x] T011 [US1] — Run the class; confirm it fails only because the route is missing.

### Implementation for User Story 1

- [x] T012 [US1] — Add copy resolution to `app/Services/WebBackupService.php`: absolute/`..`-free `document_root`, `realpath()` of `<root>/backup` and of the candidate, prefix check, regular-file check, readability and mtime; never accept a name from the request (research R4).
- [x] T013 [US1] — Add the streamed response in `app/Http/Controllers/Api/V1/WebBackupController.php` (`download` action for `GET`/`HEAD`), delegating resolution to the service.
- [x] T014 [US1] — Register the route in `routes/api/sites.php` inside the existing `scope.backup` group, before the `{backup}` show route so it is not shadowed.
- [x] T015 [US1] — Run the class until the US1 tests pass; run the full suite to prove nothing else moved.

## Phase 4: User Story 2 - Be told exactly why a download is not possible (Priority: P1)

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T016 [US2] — Add: no copy → 409 `download-not-prepared`; a copy older than `DOWNLOAD_RETENTION` → 409 `download-not-prepared` even though the file exists.
- [x] T017 [P] [US2] — Add: an unreadable copy (`chmod 0000`, test skipped when running as root) → 409 `download-not-readable`; a backup whose `server_id` differs from the website's → 409 `download-not-readable`.
- [x] T018 [P] [US2] — Add: a symlink in the backup folder pointing outside it is never opened (treated as not prepared); a directory with the archive's name likewise; cross-tenant backup id → 404; client without `limit_backup` → 403 `feature-not-allowed`.
- [x] T019 [P] [US2] — Add representation tests: `download.state` is `unavailable` / `not_prepared` / `preparing` (pending `backup_download` action) / `ready`, `http` reflects readability, and `available_until` is the copy's mtime plus three days.

### Implementation for User Story 2

- [x] T020 [US2] — Derive the `download` object in `WebBackupService::backupRepresentation()` from the resolution result and the pending-action lookup, without adding a query per backup on list pages.
- [x] T021 [US2] — Map the refusals in the controller to the two problem types with detail texts that name no path.
- [x] T022 [US2] — Run the class until green.

## Phase 5: Polish & Cross-Cutting Concerns

- [x] T023 — Full suite on `php:8.3-cli` (expect 1276 + the new tests, no regressions) and Pint on every changed file.
- [x] T024 — README: document the endpoint under Known deviations — ISPConfig has no HTTP download; this API adds one that works only where the operator has granted the API read access to website backup folders, with the security trade-off stated plainly (FR-009).
- [x] T025 — Commit the implementation phase and push.
- [x] T026 — Deploy to isp-test and confirm the deployed commit is on `origin/main`.
- [x] T027 — Run quickstart §3 live: the download works here (200, SHA-256 identical to the file on disk), with the copy's owner/mode and `id www-data` recorded; plus `download-not-prepared`, isolation and the plan gate.
- [x] T028 — Cleanup per quickstart §4 (backup before website, then clients, keys, leftovers) and verify the server is back to baseline.
- [x] T029 — Record the live results in this file and commit.

## Dependencies & Execution Order

### Phase Dependencies

Phase 1 → Phase 2 (contract + problem types) → Phase 3 (US1) → Phase 4 (US2) → Phase 5. US2 extends the
controller and service from US1 rather than duplicating them.

### Within Each User Story

Tests before implementation, always. In Phase 2, T002 and T003 are independent; T004 needs T003, T005 needs
T004, T006 needs T002.

### Parallel Opportunities

T002/T003; T009/T010; T017/T018/T019.

## Implementation Strategy

**MVP** is User Story 1 — the download itself. User Story 2 is what makes it usable on a stock installation,
where the refusal is the normal answer; both ship together because a download button without a truthful
"why not" is worse than no button.

## Notes

- No migrations, no writes, no permission changes, no privileged helper.
- On isp-test the download succeeds (`http: true`, 200 with byte-identical content) because ISPConfig puts the
  web server user in every client group. `download-not-readable` is for installations where that does not hold.
- Range requests are deliberately out of scope for this version (research R8).

## Live verification on isp-test (T027, 2026-09-16)

Deployed commit `e5149e2`. Temporary clients 52 (`qa042a`, `limit_backup = y`) and 53, website 31
`qa042a-site.test` on server 1, client-scoped keys for both.

| Step | Expected | Observed |
|---|---|---|
| Download of a nonexistent backup id | 404 | 404 |
| Representation before a copy | `not_prepared`, `http: false`, nulls | exactly that, `download_available: true` |
| Download without a copy | 409 `download-not-prepared` | 409, type `download-not-prepared`, no path in the body |
| While the copy job is pending | `preparing`, filename set | `{"state":"preparing","http":false,"filename":"manual-web31_2026-09-16_03-52.tar.gz","available_until":null}` |
| Representation after delivery | `ready`, `available_until` ≈ +3 days | `{"state":"ready","http":true,"filename":"manual-web31_…tar.gz","available_until":"2026-09-19T03:53:01+02:00"}` |
| **Download (SC-001)** | byte-identical stream | **200**, SHA-256 `20459cf4…d011a` on both sides, 5665 bytes; `content-type: application/octet-stream`, `content-disposition: attachment; filename="manual-web31_2026-09-16_03-52.tar.gz"`, `cache-control: no-store, private` |
| `HEAD` | same headers, no body | same headers, plus `accept-ranges`/`last-modified` from the file response |
| Isolation | client B on A's backup → 404 | 404 |
| Plan gate | `limit_backup = n` → 403 | 403 |

### The premise of this spec was wrong, and the documents were corrected

The specification predicted 409 `download-not-readable` here, on the assumption that the API (as `www-data`)
is in neither `root` nor the website's client group. The live check disproved it:

```
id www-data → uid=33(www-data) … groups=33(www-data),5003(ispapps),5004(ispconfig),
              5005(client0),5006(client1),5007(client19),5008(client52)
getent group client52 → client52:x:5008:www-data
copy: -rw-r----- web31:client52   folder: drwxr-x--- root:client52
```

**ISPConfig adds the web server user to every client group** — that is how Apache serves the 0750 client
directories — so the delivered copy is readable and the download works on a stock installation. The archives
under `/var/backup` stay `root:root` 0700 and are never touched, which is why streaming the *copy* is the
right design. `research.md` (R2), `spec.md`, `plan.md`, `quickstart.md`, `WebBackupDownload.yaml`,
`web-backups.yaml`, `docs/problems.md` and `README.md` were rewritten to state this; `download-not-readable`
now documents the cases where it genuinely applies (hardened permissions, a different runtime user, or a
backup stored on another server). No code changed — the implementation was already correct.

This also raises the weight of the path guard rather than lowering it: because the API is in *every* client
group, an unchecked symlink in a customer's backup folder could reach another customer's files. The guard
(`realpath` + prefix + regular-file check) is implemented and covered by tests.

## Cleanup (T028, 2026-09-16)

Backup deleted **before** the website (the spec 041 finding), then both clients through the API; the journal
reached `server.updated` (1156 = 1156) with no pending remote action. Keys `qa042*` (121–125) deleted by name.
Final state matches the pre-run baseline: keys `1, 2, 20, 27, 50`; clients `1, 2, 19`; 6 websites; 0 backup
rows; no `qa042` client or website; client directories `client0, client1, client19`; `www-data` back to
`www-data, ispapps, ispconfig, client0, client1, client19`.

One residue needed a manual step: `/var/backup/web31` remained as an **empty** directory — `backup_delete`
removes the archive but not the per-website folder — and was removed by hand. Worth knowing for any consumer
that tears websites down.
