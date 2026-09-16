# Contracts: Backup Download for Scoped Keys

The OpenAPI specification is the source of truth (constitution I). This feature's contract lives in the
repository's `api/` tree and in the problem-type documentation, not in this folder:

| File | Content |
|---|---|
| `api/modules/sites/web-backups.yaml` | the `GET` and `HEAD` operations of `/sites/web-domains/{id}/backups/{backup_id}/download`: octet-stream 200 with `Content-Length` and `Content-Disposition`, and the 401/403/404/409 refusals |
| `api/components/schemas/WebBackupDownload.yaml` | the `download` object: `state` (`unavailable`, `not_prepared`, `preparing`, `ready`), `http`, `filename`, `available_until` |
| `api/components/schemas/WebBackup.yaml` | `download` added to the backup representation, so list and show carry it too |
| `docs/problems.md` | the two new entries, `download-not-prepared` and `download-not-readable` |
| `app/Support/ProblemType.php` | the two constants and their registration in `NAMES` |

Contract rules specific to this feature:

- **The 200 is a file, not JSON.** The operation documents `application/octet-stream`, the headers a consumer
  needs (`Content-Length`, `Content-Disposition`, `Cache-Control: private, no-store`), and that `HEAD`
  returns the identical headers without a body.
- **The two 409s are distinguishable by `type`**, because a consumer reacts differently: prepare a copy
  versus explain FTP/SSH delivery. The detail text is for humans and may be reworded; the `type` is stable.
- **`download.http` is a property of the installation**, not of the backup — the contract says so plainly, so
  a consumer does not treat `false` as a transient error.
- **No path anywhere.** The contract states that neither responses nor problem details disclose a file system
  location; `filename` is the archive's name only.
- **The preparation endpoint is unchanged.** `POST …/backups/{backup_id}/download` keeps its spec 018 meaning
  (queue a copy into the website's folder); this feature adds the retrieval half.
