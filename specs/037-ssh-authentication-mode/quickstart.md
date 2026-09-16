# Quickstart: SSH Authentication Mode for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='ShellUserAuthenticationModeTest|ShellUserApiTest|MeCapabilitiesApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'` (only commits already on
`origin/main`).

Rules for this server: TEMPORARY clients only — never clients 1, 2, 19 or any `customer_no` starting `WHMCS-`; never
delete keys, clients or data another session created (check names first).

**This check changes one system setting.** isp-test has an empty `ssh_authentication` in `[sites]`. Before the first
change, save the whole blob: `mysql -N -B dbispconfig -e "SELECT config FROM sys_ini WHERE sysini_id=1" > /root/sysini-037.bak`.
After the last step, restore it and verify it is byte-identical (`cmp` against a fresh dump). Do not leave the
setting changed.

1. Admin key: `POST /clients` `qa037…` with `web_servers=1`, `limit_shell_user=2`, `ssh_chroot=no,jailkit`,
   `template_master=0`; mint a client-scoped key; create a website for the client.
2. Client key `GET /me/capabilities` → `sites.shell.authentication` is `password_or_key` (the server's current
   value).
3. Set `ssh_authentication=key` in the `[sites]` section (admin key, `PUT /system/config/sites`).
   - Client key `GET /me/capabilities` → `authentication` is `key`.
   - Client key `POST /sites/shell-users` with a `password` → 422, `errors.password`,
     `error_types.password` = `feature-not-allowed`, and the `sys_datalog` count is unchanged.
   - Client key `POST /sites/shell-users` with `ssh_rsa` → 201.
   - Admin key `POST /sites/shell-users` with both → 201, and the stored row has no password.
4. Set `ssh_authentication=password` and repeat the mirror case: `ssh_rsa` → 422 on that field, `password` → 201,
   admin with both → 201 with an empty `ssh_rsa`.
5. Restore the setting to empty, confirm `authentication` is `password_or_key` again and that a client key may send
   both credentials.
6. Restore `/root/sysini-037.bak` if any step left the blob different, and verify byte-identity.

## 3. Cleanup

1. Delete the shell users, the website and the client through the API.
2. Wait until `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa037%'` and the ids created) after checking the names.
4. Verify: no `qa037` client, group, user, website, shell user, client directory or home directory remains; nothing
   is pending; the `[sites]` section matches the backup; remove `/root/sysini-037.bak`.
