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

## 4. Results on isp-test (2026-09-16, deployed `cd5638c`)

Temporary client 41 (`qa037temp`, `limit_shell_user` 2 then 5, `ssh_chroot=no,jailkit`, `template_master=0`) and
website 25. The `sys_ini` blob was backed up first (2131 bytes).

| Check | Result |
|---|---|
| Reported mode with the server's own empty setting | `sites.shell` = `{available: true, chroot_options: [no, jailkit], authentication: password_or_key}` |
| Setting switched to `key` | `authentication` = `key` |
| Client key sends a password (key mode) | 422, `errors.password` = "This hosting accepts an SSH key only; a password cannot be set.", `error_types.password` = `feature-not-allowed` |
| Client key sends a key (key mode) | 201 |
| Admin key sends both (key mode) | 201, stored password empty |
| Journal during that section | exactly 2 rows — the two accepted creates |
| Setting switched to `password` | `authentication` = `password` |
| Client key sends a key (password mode) | 422, `errors.ssh_rsa` = "This hosting accepts a password only; an SSH key cannot be set.", same problem type |
| Client key sends a password (password mode) | 201 |
| Setting restored to empty | `authentication` = `password_or_key` again |

Two notes from the run:

- The first attempt at the password-mode acceptance returned 403: the temporary client's `limit_shell_user` was 2 and
  both slots were already used by the preceding steps. Raising the limit to 5 and repeating gave the expected 201 —
  a script artifact, not a behaviour of this feature.
- Restoring the setting through `PUT /system/config/sites` with `""` is refused (422, "must be a string"), so the
  restore used a targeted SQL replacement of that one line. See the finding recorded in tasks.md.

Cleanup: all four shell users, the website and the client deleted through the API; `server.updated` reached the last
journal id; QA keys 94–98 removed. No `qa037` client, shell user, website, client directory or home directory
remains, nothing is pending, and the `sys_ini` blob is byte-identical to the backup (which was then deleted). Key 91
(`qa dns 005`, another session's) and clients 1, 2 and 19 were not touched.

## 3. Cleanup

1. Delete the shell users, the website and the client through the API.
2. Wait until `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa037%'` and the ids created) after checking the names.
4. Verify: no `qa037` client, group, user, website, shell user, client directory or home directory remains; nothing
   is pending; the `[sites]` section matches the backup; remove `/root/sysini-037.bak`.
