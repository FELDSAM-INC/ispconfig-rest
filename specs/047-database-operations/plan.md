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
mysqldump / mysql remain the execution layer. Uploads now use 768 KiB chunks with
an uploading state, exact declared byte count (up to 2 GiB), ordered idempotent
writes, explicit finalize and cancel. API-owned migration adds transfer sizes.
Generated SQL normalization, gzip and PDO chunk iteration are bounded-memory;
4 GiB raw SQL, 2 GiB compressed export, 4-hour job deadline and progress heartbeats.
Queued work survives another job's long runtime. Downloads use keyset batches.
Root-only rotated diagnostics contain IDs, phases and numeric MySQL errors only.
Tests include auth/ownership/locks, incomplete/changed/reordered chunk rejection,
retry/finalize/cancel, lexer buffer boundaries and actual 1 GiB random binary data.
