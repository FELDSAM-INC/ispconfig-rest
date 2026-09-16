# Quickstart: Account-Wide Backup Overview

How to exercise `GET /me/backups` locally and on isp-test, and what a correct answer looks like.

## 1. Local (tests)

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli \
  php artisan test --filter=MeBackupsApiTest
```

The class covers both user stories: the newest-per-type fold, a website without backups, the
`backups_available` flag, tenant isolation, the `limit_backup` refusal, the admin `client_id` rules,
unknown parameters, paging, a query-count assertion (constant in the number of websites) and field equality
with `GET /sites/web-domains/{id}/backups`.

## 2. Local (by hand)

```bash
php artisan serve
curl -s -H "X-API-Key: $API_DEV_KEY" 'http://127.0.0.1:8000/api/v1/me/backups?client_id=1' | jq
```

## 3. Shape of a correct answer

```json
{
  "data": [
    {
      "web_domain_id": 20,
      "domain": "example.com",
      "server_id": 1,
      "backups_available": true,
      "total": 7,
      "latest": [
        { "id": 51, "server_id": 1, "parent_domain_id": 20, "backup_type": "web",   "database_name": null,      "backup_mode": "rootgz", "backup_format": "tar_gzip", "filename": "web20260915001000.tar.gz",        "filesize": 184320000, "filesize_approximate": false, "created_at": "2026-09-15T00:10:00+02:00", "job": "auto", "encrypted": false, "download_available": true },
        { "id": 52, "server_id": 1, "parent_domain_id": 20, "backup_type": "mysql", "database_name": "c1_shop", "backup_mode": "rootgz", "backup_format": "gzip",     "filename": "db_c1_shop_2026-09-15_00-12.sql.gz", "filesize": 20480,     "filesize_approximate": false, "created_at": "2026-09-15T00:12:00+02:00", "job": "auto", "encrypted": false, "download_available": true }
      ]
    },
    { "web_domain_id": 21, "domain": "new.example.com", "server_id": 1, "backups_available": true, "total": 0, "latest": [] }
  ],
  "meta": { "total": 2, "limit": 25, "offset": 0 }
}
```

Read it as: `latest` is one entry **per backup type**, so a site whose files are backed up but whose
database backup is missing or old is visible at a glance — that is the point of the endpoint.

## 4. Live check on isp-test

Backups are produced by the server once a minute from `sys_remoteaction`, so a real backup takes a few
minutes. Use a **temporary** client and website; never a `WHMCS-` customer and never clients 1, 2 or 19.

1. **Mint a temporary admin key** and create a temporary client with `limit_backup = 'y'`, a website on
   server 1, and a client-scoped key for it.
2. **Baseline**: `GET /me/backups` with the client key → 200, the website present, `backups_available: true`
   (server 1 has `backup_dir=/var/backup`), `total: 0`, `latest: []`.
3. **Create a backup**: `POST /sites/web-domains/{id}/backups {"type":"web"}` → 201 job; poll
   `GET /sites/web-domains/{id}/backup-jobs` until the job leaves `pending` (about a minute), then poll
   `GET /sites/web-domains/{id}/backups` until a row appears.
4. **Overview again**: `GET /me/backups` → `total: 1` and one `latest` entry of type `web`; compare every
   field with the same row in the per-website list — they must be identical (SC-003).
5. **Plan gate**: set the temporary client's `limit_backup` to `n` with the admin key →
   `GET /me/backups` with the client key → 403, problem type `feature-not-allowed`, `feature: limit_backup`.
   Set it back to `y`.
6. **Isolation**: with a second temporary client's key, `GET /me/backups` must not contain the first
   client's website; with the admin key, `client_id` of the first client returns the first client's
   overview, `client_id` of an unknown client returns 404, and no `client_id` returns 422.
7. **Unknown parameter**: `GET /me/backups?foo=1` → 400.
8. **Paging**: `?limit=1` → one entry and `meta.total` unchanged.

## 5. Cleanup (mandatory)

1. Delete the backup (`DELETE /sites/web-domains/{id}/backups/{backup}`) and wait for the job to finish, so
   the server removes the archive.
2. Delete the temporary websites and clients through the API.
3. Wait until `server.updated` reaches the last `sys_datalog` id, so nothing stays queued.
4. Delete **only** the QA keys created here (`name LIKE 'qa%'` and the ids you minted). Keys #1, #2, #20,
   #27 and #50, and anything another session owns, stay.
5. Verify nothing is left: no `qa*` client, no client directory, no backup file under `/var/backup/web<id>`
   for the temporary website, no pending `sys_remoteaction` row.
