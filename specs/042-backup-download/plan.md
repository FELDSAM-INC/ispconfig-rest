# Implementation Plan: Backup Download for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/042-backup-download/spec.md`

## Summary

- `GET`/`HEAD /sites/web-domains/{id}/backups/{backup}/download` streams the copy that spec 018's
  preparation action delivers into `<document_root>/backup`, **only** when the API process can read it
  (research R2, R4, R8).
- Two new problem types make the stock case actionable: `download-not-prepared` (no or expired copy) and
  `download-not-readable` (copy present but unreadable here, or backup stored on another server) — R5.
- Every backup representation gains a `download` object (`state`, `http`, `filename`, `available_until`) so a
  consumer decides what to offer without probing per row (R6, R7).
- Scoping, the vhost rule and the `limit_backup` gate are the shipped ones: the route joins the existing
  `scope.backup` group and the backup is resolved with `WebBackupService::backupOfWebsite()` (R9).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12 (`BinaryFileResponse`/streamed response); dev: phpunit ^11
**Storage**: MySQL `dbispconfig` read-only (`web_backup`, `web_domain`, `sys_remoteaction`) + one file read
**Testing**: PHPUnit feature tests on sqlite in-memory with a real temporary directory, Docker `php:8.3-cli`
(baseline 1276 on `ceba2a9`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz), API as `www-data`
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: constant memory regardless of archive size (streamed, never buffered)
**Constraints**: no privileged helper, no permission changes, no path disclosure, no journal or remote-action
write
**Scale/Scope**: 1 route (2 verbs), 1 controller, 1 service addition, 2 problem types, 1 schema addition

## Constitution Check

- [x] **Spec-first (I)**: the contract, the two `docs/problems.md` entries and the `download` schema land
      before the controller.
- [x] **Datalog-only writes (II)**: nothing is written at all — the download is a read (FR-007, SC-003).
- [x] **Legacy parity (III)**: no legacy HTTP download exists (R1); the feature reuses legacy's own delivery
      artefact and never alters the permissions legacy sets (R2). The deviation is documented in README
      "Known deviations" as an addition, not a behaviour change.
- [x] **Route discipline (IV)**: one route inside the existing `scope.backup` group; the controller validates
      and delegates, the service resolves and decides.
- [x] **HTTP contract (V)**: 200 with file headers, problem+json refusals carrying the spec 023 vocabulary
      plus the two new types; 404 for anything the key may not see.
- [x] **Tests required**: readable, missing, expired, unreadable, symlink-escape, foreign-server,
      cross-tenant, plan gate, `HEAD`, and no-journal assertions (R10).
- [x] **No schema changes**: no migrations; one new response object on an existing representation.

## Project Structure

### Documentation (this feature)

```
specs/042-backup-download/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
└── contracts/README.md
```

### Source Code (repository root)

```
api/modules/sites/web-backups.yaml                  # + GET/HEAD .../download operation
api/components/schemas/WebBackup.yaml               # + download object
api/components/schemas/WebBackupDownload.yaml       # new: state/http/filename/available_until
app/Support/ProblemType.php                         # + DOWNLOAD_NOT_PREPARED, DOWNLOAD_NOT_READABLE
docs/problems.md                                    # + the two entries
app/Services/WebBackupService.php                   # + copy resolution, state, retention check
app/Http/Controllers/Api/V1/WebBackupController.php # + show/stream the prepared copy
routes/api/sites.php                                # + GET/HEAD download route in the scope.backup group
tests/Feature/WebBackupDownloadApiTest.php          # new
README.md                                           # Known deviations: HTTP download, and the opt-in
```

## Legacy Research (Phase 0 focus)

Completed in [research.md](research.md). The decisive facts, all read on isp-test:

- ISPConfig offers **no** HTTP backup download; `plugin_backuplist.inc.php:113-125` only queues
  `backup_download`.
- The archive lives in `<backup_dir>/web<id>` as `root:root` 0700; the delivered copy is
  `<system_user>:<system_group>` 0640 inside a `root:<system_group>` 0750 folder
  (`backup.inc.php:116-136, 988, 1099-1106`).
- Copies are purged after three days (`backup.inc.php:1820`).
- The API runs as `www-data` with no privileged component, so on a stock installation it can read neither the
  archive nor the copy — the honest answer there is `download-not-readable`.

## Complexity Tracking

| Item | Why it is justified | Simpler alternative rejected because |
|---|---|---|
| Two new problem types | the consumer must act differently on "prepare one" vs "use FTP" | one type forces prose parsing (R5) |
| `download` object on the representation | list pages may not probe per row | catching 409 per backup is a refusal per row (R6) |
| `realpath()` + prefix + symlink guard | the delivery folder is writable by a customer with shell access | a plain `file_exists()` would let a symlink read any file the API can reach (R4) |
