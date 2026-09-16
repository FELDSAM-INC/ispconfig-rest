# Requirements Checklist: Backup Download for Scoped Keys

Quality gate for `spec.md` before planning. Each item is checked against the written specification, not
against an implementation.

## Grounding in what the installation can actually do

- [x] The legacy capability was established **before** the design: ISPConfig has no HTTP download at all
      (`plugin_backuplist.inc.php:113-125`), only the `backup_download` remote action.
- [x] The real permissions are recorded with their sources: archives in `<backup_dir>/web<id>` are
      `root:root` 0700 (`backup_plugin.inc.php:78-80`); the delivered copy is `<system_user>:<system_group>`
      0640 inside a `root:<system_group>` 0750 folder (`backup.inc.php:988, 1099, 1105-1106, 116-136`).
- [x] The API's own runtime identity is stated (www-data, php-fpm pool, no privileged component), because it
      decides what is possible.
- [x] The three-day retention is tied to its source (`backup.inc.php:1820`) and to the `available_until`
      spec 018 already returns.
- [x] The specification does not promise a capability the platform cannot deliver: the stock case is
      explicitly the 409 path, not the 200 path.

## Requirement quality

- [x] Every functional requirement is testable without reading the code (FR-001 … FR-009).
- [x] The two refusal situations are distinguished by type (`download-not-prepared` vs
      `download-not-readable`) so a consumer can act differently on each.
- [x] The representation carries enough state (`download.state`, `download.http`) for a panel to decide
      whether to show the button **before** issuing a request.
- [x] `HEAD` is specified alongside `GET`, so a size can be shown without transferring the file.
- [x] Streaming (not buffering) is a requirement, not an implementation detail (FR-008).

## Safety

- [x] Path traversal and symlink abuse are addressed explicitly (FR-002), which matters because the folder is
      writable by a customer with shell access.
- [x] No path is ever disclosed in a response or a log (FR-002, SC-004).
- [x] Permissions are never widened by the feature itself; enabling HTTP downloads is an operator decision
      with a documented trade-off (FR-009, Assumptions).
- [x] Cross-tenant access is indistinguishable from a missing resource (SC-005), matching spec 024's rule.
- [x] The plan gate (`limit_backup`) is applied exactly as on the other backup endpoints.
- [x] No write of any kind: no `sys_datalog`, no `sys_remoteaction` (FR-007, SC-003).

## Testability

- [x] Each user story has an independent test that can run without a real ISPConfig server (readable and
      unreadable copies can both be simulated on a temporary path).
- [x] Success criteria are measurable: byte-identical download (SC-001), correct refusal type on a stock
      install (SC-002), no journal rows (SC-003), no paths (SC-004), tenant indistinguishability (SC-005).
- [x] Edge cases name their expected answer (deleted backup, expired copy, foreign server, locked account,
      symlink, large file).

## Consistency with shipped specs

- [x] Reuses spec 018's artefact (the copy in `<document_root>/backup`) instead of inventing a second
      delivery location.
- [x] Refusal types follow spec 023's vocabulary and add two new ones that must be documented in
      `docs/problems.md`.
- [x] Scoping follows specs 011/024; the plan gate follows spec 018.
- [x] Locked accounts (spec 019) are addressed: reads stay allowed.

**Result**: the specification is grounded, complete and honest about the stock case; planning may proceed.
