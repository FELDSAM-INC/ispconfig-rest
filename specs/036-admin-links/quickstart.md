# Quickstart: Administration and File-Transfer Links for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MeHostingLinksApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'` (only commits already on
`origin/main`).

Rules for this server: TEMPORARY clients only — never clients 1, 2, 19 or any `customer_no` starting `WHMCS-`; never
delete keys, clients or data another session created (check names first). isp-test has
`phpmyadmin_url=https://[SERVERNAME]:8081/phpmyadmin`, `dblist_phpmyadmin_link=y` and an empty `webftp_url`; server 1
(`isp-test.feldhost.cz`) is the only database server. System settings must not be changed permanently — if a step
sets one, restore the original value byte-identical afterwards and verify.

1. Admin key: `POST /clients` `qa036…` with `db_servers=1`, `template_master=0`; mint a client-scoped key.
2. Client key `GET /me/hosting-links`:
   - `database_administration.available` is `true`;
   - `servers` contains server 1 with `url = https://isp-test.feldhost.cz:8081/phpmyadmin`;
   - `file_transfer` is `{available: false, url: ""}` (isp-test configures no file manager).
3. Admin key: create a website and a database for the client; client key reads the endpoint again → the same single
   server entry (no duplicate for the hosting server).
4. Error cases with the client key: `?foo=1` → 400; `?client_id=<other client>` → 404. Admin key without `client_id`
   → 422; with the temporary client's id → the same body as the client key sees.
5. Exposure check: the response contains only `client_id`, `database_administration` and `file_transfer`; no other
   `[sites]` value appears anywhere in it.
6. Optional (restore afterwards): set `webftp_url` to `https://files.example.test` through
   `PUT /system/config/sites` with an admin key, read the endpoint → `file_transfer.available` is `true` with that
   exact address, then restore the empty value and confirm the `[sites]` section is byte-identical to the backup
   taken before the change.

## 3. Cleanup

1. Delete the database, the website and the client through the API.
2. Wait until `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa036%'` and the ids created) after checking the names.
4. Verify no `qa036` client, group, user, website, database, client directory or vhost file remains, that nothing is
   pending, and that the `[sites]` configuration is unchanged.
