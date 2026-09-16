# Data Model: Backup Download for Scoped Keys

Read-only feature. No table is created, altered or written. The entities below are a response object and the
derivation of one file's state.

## Source data

| Source | Fields used | Purpose |
|---|---|---|
| `web_backup` | `backup_id`, `parent_domain_id`, `server_id`, `backup_type`, `filename`, `backup_mode`, `backup_format` | which archive, and its resolved name |
| `web_domain` | `domain_id`, `server_id`, `document_root`, `system_user`, `system_group`, `type` | where a prepared copy is delivered, and the vhost rule |
| `sys_remoteaction` | `action_type`, `action_param`, `action_state` | a pending `backup_download` → state `preparing` |
| file system | existence, regular-file check, readability, size, mtime of `<document_root>/backup/<filename>` | whether it can be streamed now |

The filename always comes from the backup row (resolved exactly as `backupRepresentation()` resolves it,
including the borg extension rules). Nothing from the request participates in the path.

## `download` object (added to every backup representation)

| Field | Type | Values / meaning |
|---|---|---|
| `state` | string | `unavailable` — the backup is stored on another server, so a copy can never be delivered here; `not_prepared` — no copy, or the copy is older than the retention; `preparing` — a `backup_download` action for this backup is pending; `ready` — a copy exists, is a regular file and is within the retention |
| `http` | boolean | whether **this** API process can read the copy and therefore stream it; false on a stock installation |
| `filename` | string \| null | the copy's name, present when `state` is `ready` or `preparing` |
| `available_until` | string (date-time) \| null | copy mtime + 3 days (`WebBackupService::DOWNLOAD_RETENTION`), in the API timezone; null unless a copy exists |

`state` answers "is there a file"; `http` answers "can this API hand it to you". A panel offers a download
button only when both `state: ready` and `http: true`, offers "prepare a copy" on `not_prepared`, shows a
progress hint on `preparing`, and explains FTP/SSH delivery when `http` is false.

## Path resolution (FR-002, research R4)

1. `document_root` must be an absolute path containing no `..` segment; otherwise the state is `unavailable`.
2. The folder is `realpath(<document_root>/backup)`; if it does not resolve, the state is `not_prepared`.
3. The candidate is `realpath(<folder>/<filename from the backup row>)`.
4. It must resolve, must still start with `<folder>/`, and must be a regular file — a symlink pointing
   outside, a directory or a dangling link is treated as `not_prepared` and never opened.
5. `is_readable()` decides `http`.

No path is ever returned, logged or included in a problem detail.

## Endpoint outcomes

| Situation | Status | Problem type |
|---|---|---|
| readable copy within retention | 200 (stream) or 200 headers for `HEAD` | — |
| no copy, unresolvable folder, or copy older than the retention | 409 | `download-not-prepared` |
| copy present but not readable by the API process | 409 | `download-not-readable` |
| backup stored on a server other than the website's | 409 | `download-not-readable` |
| backup not on that website, website not readable, or not a vhost | 404 | `about:blank` |
| client/reseller key whose client lacks `limit_backup` | 403 | `feature-not-allowed` (`feature: limit_backup`) |

## Response headers on success

`Content-Type: application/octet-stream`, `Content-Length: <size>`,
`Content-Disposition: attachment; filename="<archive name>"`, `Cache-Control: private, no-store`.
`HEAD` returns the identical headers with no body.
