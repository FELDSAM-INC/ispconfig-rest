# Implementation and verification

- Extract the ordinary database create path into DatabaseProvisioningService;
  both normal create and copy retain the same request validation, limits and datalog.
- API-owned migration adds jobs, bounded base64 chunks and per-server heartbeats.
  Scoped database routes own job creation/status/private gzip download. Jobs cannot
  be accessed through a foreign readable record, even with sys_perm_other set.
- A root-owned standalone worker uses ISPConfig's local control credentials and
  existing master connection; no Laravel installation is needed on remote DB nodes.
  It serializes execution, rechecks owner/server/active/lock state and waits for
  physical target provisioning. Imports use a database-scoped, locked-when-idle SQL
  principal through an unprivileged native client with shell/local infile disabled.
- Native generated dumps normalize object DEFINERs and static source schema
  qualifiers with a lexer. Literal strings and non-executable comments stay intact.
  Uploaded dumps are not rewritten. Foreign DEFINER/global privilege operations
  fail under the database user's permissions; import failures may partially apply.
- PHP 8.3/8.5 API suites, contract tests, lexer regression cases and an isolated
  MariaDB 10.11 integration fixture cover the feature. WHMCS has separate PHP
  7.4/8.3 and two-theme browser acceptance checks in its repository.

Constitution: OpenAPI authored first; no ISPConfig table migrations or direct
metadata writes; API-owned state is exempt. Physical SQL import/export is the
owner-requested extension documented in spec.md. No native ISPConfig endpoint is
misrepresented as implementing these operations.


## Large database follow-up (2026-09-20)

Owner requested at least 1 GiB databases and worker logging. Native proc_open /
mysqldump / mysql remain the execution layer. The first large-file implementation used 768 KiB chunks with
an uploading state, exact declared byte count (up to 2 GiB), ordered idempotent
writes, explicit finalize and cancel. API-owned migration adds transfer sizes.
Generated SQL normalization, gzip and PDO chunk iteration are bounded-memory;
initially 4 GiB raw SQL, 2 GiB compressed export, a 4-hour deadline and progress
heartbeats. The raw SQL limit is superseded below.
Queued work survives another job's long runtime. Downloads use keyset batches.
Root-only rotated diagnostics contain IDs, phases and numeric MySQL errors only.
Tests include auth/ownership/locks, incomplete/changed/reordered chunk rejection,
retry/finalize/cancel, lexer buffer boundaries and actual 1 GiB random binary data.

## Parallel uploads and quota follow-up (2026-09-20)

New imports negotiate 8/4/2 MiB or 768 KiB chunks and allow four concurrent requests,
including out-of-order arrival. Exact lengths, total size and retry identity remain
enforced. Legacy jobs keep sequential 768 KiB chunks. A nullable API-owned chunk-size
column freezes the protocol per job; MySQL packet limits can reduce the requested size.

The reported 293 MB gzip expands to 5.36 GB: dump size is not database storage size.
The worker no longer imposes a 4 GiB raw SQL limit; available temporary disk space
(with a 64 MiB reserve) bounds processing instead. Uploaded/compressed artifacts
remain limited to 2 GiB. Failures log phase and processed bytes; successful gzip
expansion logs the final uncompressed byte count.

Import/copy checks the configured table+index storage quota before, during and after
SQL execution using ISPConfig's measure. MySQL 8 statistics expiry is disabled for
these reads. On quota failure, terminate only the job principal's sessions, rotate
and lock its password, and expose a safe quota-specific error. Checks every two
seconds are not a byte-perfect hard quota and cannot roll back already applied SQL.
Export remains available above quota. Tests cover quota failure before/during SQL
and successful 5.36 GiB expansion into a database that fits its unchanged quota.
