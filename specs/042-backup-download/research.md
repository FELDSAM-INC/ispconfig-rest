# Research: Backup Download for Scoped Keys

Decisions for feature 042, grounded in ISPConfig 3.3.1p1 read on isp-test, the shipped spec 018
implementation, and the file-reading precedent of spec 022. Each item records the decision, why, and what was
rejected.

## R1 — What ISPConfig can actually do (checked first)

**Finding**: ISPConfig has **no HTTP download of a backup anywhere**. Its backup list plugin
(`interface/lib/classes/plugin_backuplist.inc.php:113-125`) reacts to `backup_action=download` by inserting a
`backup_download` row into `sys_remoteaction`; nothing streams bytes to the browser. The server then copies
the archive into the website's own folder.

**Consequence**: this feature adds a capability rather than porting one, so it must be justified on its own
terms and must not contradict any legacy rule. It deliberately reuses legacy's artefact (the copy in
`<document_root>/backup`) so an HTTP download and an FTP download deliver the identical file.

## R2 — The permissions decide the whole design

**Finding** (all read on isp-test):

| Object | Owner / mode | Source |
|---|---|---|
| `<server.backup_dir>` (`/var/backup`) | `root:root` 0700 | server config `backup_dir`, `backup_plugin.inc.php:78-80` |
| `<backup_dir>/web<id>/<archive>` | `root:root` 0700 | same |
| `<document_root>/backup` (delivery folder) | `root:<system_group>` 0750 | `backup.inc.php:116-136` (`secureBackupDir()`) |
| `<document_root>/backup/<archive>` (the copy) | `<system_user>:<system_group>` 0640 | `backup.inc.php:1099-1106` |
| API process | `www-data`, **and a member of every `clientN` group** | php-fpm pool `ispconfig-rest.conf`, Apache :8090; verified with `id www-data` on isp-test |

**Decision**: the endpoint streams **only** the delivered copy, and **only** when the API process can read it.
The backup directory itself is never touched.

**Corrected on 2026-09-16 by the live check (R11)**: the copy *is* readable on a stock installation. ISPConfig adds
the web server user to every client group — `id www-data` on isp-test lists `client0, client1, client19, client52` —
which is how Apache serves the 0750 client directories. The copy at `<system_user>:<system_group>` 0640 therefore
falls inside a group the API already belongs to, while `/var/backup` (root-only) stays unreachable. Streaming the
*copy* rather than the archive is exactly what makes the feature work without any privilege change; a download
returned 200 with a SHA-256 identical to the file on disk.

**Why**: the alternatives are worse. Reading `/var/backup` would require root. Making the copy readable for
everyone would expose every customer's archive beyond the group ISPConfig already grants. Adding a privileged
helper would put a setuid path into a product whose whole security story is "the API is an ordinary web
application".

Because the API is in every client group, the path guard of R4 carries more weight, not less: an unchecked symlink
in a customer's backup folder could reach another customer's files. That guard is implemented and tested.

**Rejected**: running the API as root (unacceptable); a setuid/sudo helper (new privileged surface, and
ISPConfig itself offers no such hook); `chmod`-ing the copy from the API (the API may not even enter the
folder, and it would silently widen a customer's permissions); a second copy into an API-owned spool (only
the ISPConfig server process can create it, and its action types are fixed in ISPConfig's own code, which we
must not modify).

## R3 — No privileged component exists to lean on

**Finding**: the installation has no `ispconfig-rest` systemd service, no root-run worker and no queue
runner; `ispconfig-rest` is a CLI wrapper an administrator runs by hand, and the HTTP surface is php-fpm as
www-data.

**Decision**: nothing in this feature may assume a background or privileged process. The download is a plain
synchronous read inside the request.

## R4 — Path resolution follows the spec 022 precedent, hardened

**Decision**: the file is located as `realpath(<document_root>/backup/<filename>)`; the request is refused
unless the document root is absolute and free of `..`, the resolved path still lies inside the resolved
`<document_root>/backup` directory, the entry is a regular file (not a symlink or directory), and it is
readable by the process. The filename comes from the backup row, never from the request.

**Why**: `LetsEncryptStatusService::certificate()` (spec 022) already reads a file under `<document_root>`
with exactly this shape of guard, and `SwaggerController::getModuleSpec()` shows the repository's
`realpath()` + prefix check for traversal. The delivery folder is writable by a customer with shell access,
so a symlink there must never turn the endpoint into an arbitrary file reader.

**Rejected**: trusting `document_root` unchecked (a crafted row would escape); accepting a filename from the
request (classic traversal); `file_exists()` without `realpath()` (symlink follows).

## R5 — Two refusals, not one

**Decision**: two new problem types. `download-not-prepared` (409) when no copy exists — including an expired
one — telling the consumer to request preparation first. `download-not-readable` (409) when a copy exists but
this API cannot read it, or when the backup lives on a different server than the website so a copy can never
appear here.

**Why**: a panel must react differently: the first is a button ("prepare a copy"), the second is an
explanation ("fetch it over FTP/SSH"). One shared code would force the consumer to parse prose.

**Rejected**: 404 for the unreadable case (it exists, and hiding that would make the panel look broken); 503
(nothing is temporarily unavailable — the installation is simply configured this way); a single
`download-unavailable` type (loses the actionable distinction).

## R6 — The representation must answer before the request

**Decision**: every backup representation gains a `download` object: `state` (`unavailable`, `not_prepared`,
`preparing`, `ready`), `http` (whether this API could stream it), and `filename` + `available_until` when a
copy exists. `preparing` is derived from a pending `backup_download` action for that backup;
`available_until` is the copy's mtime plus the three-day retention.

**Why**: the consumer's list page may not probe each backup with a request; it renders buttons from the list
it already has. `download_available` (spec 018) answers "can a copy be prepared", which is a different
question from "can I fetch it now".

**Rejected**: leaving the consumer to call and catch 409 (a refusal per row on every page load); overloading
`download_available` (it has a defined legacy meaning — same-server storage).

## R7 — Retention is read from the file, not assumed

**Decision**: `available_until` is computed from the copy's modification time plus
`WebBackupService::DOWNLOAD_RETENTION` (3 days, already a constant); a copy older than that is reported as
`not_prepared` even if the file still exists, because the server's purge (`backup.inc.php:1820`, "delete
files older than 3 days") may not have run yet.

**Why**: telling a customer a file is available when the next purge will remove it mid-download is worse than
telling them to prepare a fresh copy.

## R8 — Streaming, not buffering

**Decision**: the response is a streamed file response with `Content-Length`, `Content-Type:
application/octet-stream`, `Content-Disposition: attachment; filename="<archive>"` and
`Cache-Control: private, no-store`; `HEAD` returns the same headers without a body.

**Why**: archives are routinely gigabytes; `File::get()` (the pattern `SwaggerController` uses for small YAML
files) would exhaust memory. `Cache-Control: private, no-store` keeps a customer's archive out of shared
caches.

**Rejected**: `response()->download()` with automatic deletion (the file belongs to the customer's website
and must survive); range requests (deferred — a first version that streams the whole file is honest and
simple; a consumer that needs resume can be served later).

## R9 — Scoping and the plan gate are the shipped ones

**Decision**: the route joins the existing `scope.backup` group, so the vhost-only 404 and the
`limit_backup` 403 come from `RequireBackupAccess`; the backup itself is resolved with
`WebBackupService::backupOfWebsite()`, which already restricts to the website's own rows.

**Why**: a second implementation of either rule is how the two endpoints eventually disagree (the lesson of
spec 041's SC-003).

## R10 — Test strategy without a real ISPConfig server

**Decision**: feature tests create a temporary directory as the website's `document_root`, write a real file
into `<root>/backup`, and exercise: a readable copy (byte-identical download, headers, `HEAD`), a missing
copy, an expired copy, an unreadable copy (simulated with `chmod 0000`, skipped when the test process is
root), a symlink pointing outside the folder, a foreign-server backup, cross-tenant access, the plan gate,
and the absence of any journal or remote-action row.

**Why**: constitution "Testing (REQUIRED)", and the security requirements (FR-002, SC-004, SC-005) are only
meaningful if asserted.

**Rejected**: mocking the file system (the traversal and symlink guards are exactly what must be proven
against a real one).
