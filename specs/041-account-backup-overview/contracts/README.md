# Contracts: Account-Wide Backup Overview

The OpenAPI specification is the source of truth (constitution I). This feature's contract lives in the
repository's `api/` tree, not in this folder:

| File | Content |
|---|---|
| `api/modules/me/backups.yaml` | the `/me/backups` path and its `get` operation: `client_id`, `limit`, `offset`, the 200 list response and the 400/401/403/404/422 refusals |
| `api/modules/me/_index.yaml` | module index entry `backups: $ref: './backups.yaml'` |
| `api/openapi.yaml` | root path entry `/me/backups` |
| `api/components/schemas/AccountBackupOverview.yaml` | one website entry: `web_domain_id`, `domain`, `server_id`, `backups_available`, `total`, `latest[]` |
| `api/components/schemas/AccountBackupLatest.yaml` | one newest-backup entry: `id`, `type`, `created_at`, `size_bytes`, `format`, `job`, `encrypted`, `download_available`, `database_name` |

Contract rules specific to this feature:

- **No new visibility vocabulary.** The operation description states that an entry appears only for `vhost`
  websites the key may read, and that a backup appears only if the per-website list would show it.
- **Refusals reuse the shipped types** of spec 023: 403 `feature-not-allowed` with `feature: limit_backup`,
  422 `validation-failed` for `client_id`, plain 400/404 otherwise.
- **`latest` is documented as one entry per backup type**, newest first — not "the newest backup".
- **Field parity is part of the contract**: `AccountBackupLatest` documents each field as the same value the
  `WebBackup` schema carries for that backup, so a consumer can rely on both endpoints agreeing.
