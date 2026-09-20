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
