# Quickstart: Backup Download for Scoped Keys

How to exercise the download endpoint locally and on isp-test, and what a correct answer looks like in both
the enabled and the stock case.

## 1. Local (tests)

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli \
  php artisan test --filter=WebBackupDownloadApiTest
```

The class uses a real temporary directory as the website's `document_root`, so the path guards are proven
against a real file system: byte-identical download, `HEAD` headers, missing copy, expired copy, unreadable
copy, symlink escaping the folder, backup on another server, cross-tenant access, the plan gate, and the
absence of any journal or remote-action row.

## 2. Shape of the answers

**Ready and readable** — `GET /api/v1/sites/web-domains/{id}/backups/{backup_id}/download`:

```
HTTP/1.1 200 OK
Content-Type: application/octet-stream
Content-Length: 5660
Content-Disposition: attachment; filename="manual-web28_2026-09-16_03-32.tar.gz"
Cache-Control: private, no-store
```

**No copy prepared yet**:

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-prepared",
  "title": "Conflict",
  "status": 409,
  "detail": "No prepared copy of this backup is available. Request one first."
}
```

**Copy exists but this installation cannot serve it over HTTP**:

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#download-not-readable",
  "title": "Conflict",
  "status": 409,
  "detail": "This installation delivers backup copies to the website's backup folder; fetch it with the website's FTP or SSH access."
}
```

**In a backup representation** (`GET …/backups`):

```json
"download": { "state": "ready", "http": false, "filename": "manual-web28_2026-09-16_03-32.tar.gz", "available_until": "2026-09-19T03:32:08+02:00" }
```

Read it as: the file is there (`state`), but this API may not read it (`http: false`) — so a panel explains
FTP/SSH instead of showing a download button.

## 3. Live check on isp-test — the download works here

isp-test runs the API as `www-data`, and ISPConfig adds that user to every client group (`id www-data` →
`client0, client1, client19, …`), which is how Apache serves the 0750 client directories. The delivered copy at
`<system_user>:<system_group>` 0640 is therefore readable, so the endpoint answers **200** and streams it. Prove it
by checksum, not by status code alone. The refusals are exercised separately: before a copy is prepared
(`download-not-prepared`), and — where reproducible — with an unreadable copy (`download-not-readable`).

Use a **temporary** client and website; never a `WHMCS-` customer, and never clients 1, 2 or 19.

1. Mint a temporary admin key (`ispconfig-rest key:create "qa042 admin"`), create a temporary client with
   `limit_backup = 'y'`, a website on server 1, and a client-scoped key
   (`ispconfig-rest key:create "qa042 client" --client-id=<id>`).
2. **Before any backup**: `GET …/backups` → empty; the download endpoint on a made-up backup id → 404.
3. **Create a backup**: `POST …/backups {"type":"web"}`, wait for the job to leave `pending` (about a
   minute) and for a row to appear in the list. Its `download.state` must be `not_prepared`, `http` false.
4. **Download without a prepared copy** → 409 `download-not-prepared`.
5. **Prepare a copy**: `POST …/backups/{backup_id}/download` → 201 job; while it is pending the representation
   must report `download.state: preparing`. Wait for the server to finish.
6. **Representation after delivery**: `download.state: ready`, `filename` set, `available_until` about three
   days ahead, and `http: true` on this installation.
7. **Download** → 200. Compare the SHA-256 of the response with the file on disk — they must be identical — and
   check the headers (`Content-Type: application/octet-stream`, `Content-Disposition`, `Content-Length`,
   `Cache-Control` carrying `private` and `no-store`; Symfony may order the directives as `no-store, private`).
   `HEAD` must return the same headers with no body.
8. **Record the on-disk truth**: `ls -ld <document_root>/backup` and `ls -l` the copy, plus `id www-data`, so the
   reason the read succeeds is documented rather than assumed.
9. **Isolation**: a second temporary client's key must get 404 for the first client's backup id.
10. **Plan gate**: set `limit_backup = 'n'` on the temporary client → 403 `feature-not-allowed`; restore it.

## 4. Cleanup (mandatory)

1. Delete the prepared copy's backup (`DELETE …/backups/{backup}`) **before** deleting the website — the
   server's backup plugin resolves the website row before touching files, so deleting the website first
   leaves the archive on disk (finding recorded in spec 041).
2. Delete the temporary websites and clients through the API.
3. Wait until `server.updated` reaches the last `sys_datalog` id and no `sys_remoteaction` row is pending.
4. Delete **only** the `qa042*` keys you minted; keys #1, #2, #20, #27, #50 and anything another session owns
   stay.
5. Verify nothing is left: no `qa042` client, `sys_user`, website or client directory, no per-website folder
   under `/var/backup`, no leftover copy under the deleted website's document root.
