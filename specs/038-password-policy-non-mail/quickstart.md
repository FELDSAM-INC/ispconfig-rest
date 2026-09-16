# Quickstart: Password Policy for Non-Mail Users

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='PasswordPolicyNonMailTest|MailboxAccessPasswordPolicyTest|MeCapabilitiesApiTest|ClientApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'` (only commits already on
`origin/main`).

Rules for this server: TEMPORARY clients only — never clients 1, 2, 19 or any `customer_no` starting `WHMCS-`; never
delete keys, clients or data another session created. isp-test's policy is `min_password_length=8` and
`min_password_strength=3`, which is what this check exercises; **do not change the system configuration**.

1. Admin key: `POST /clients` with a weak password (`abcdefgh`) → 422 on `password` with the policy message.
2. Admin key: the same create with `Qa038-Temp!x9` → 201. Mint a client-scoped key; create a website for it.
3. Client key `GET /me/capabilities` → `sites.password_policy` is `{min_length: 8, min_strength: 3}`, and
   `mail.password_policy` still carries its three fields.
4. Client key, weak password (`abcdefgh`) on each of: `POST /sites/database-users` (`database_password`),
   `POST /sites/ftp-users`, `POST /sites/shell-users`, `POST /sites/web-folder-users` → 422 on the field, with the
   journal count unchanged across all four.
5. Client key, compliant password (`Qa038-Strong!x9`) on the same four → 201 each.
6. Admin key: `PUT /sites/web-domains/{id}` with `stats_password: abcdefgh` → 422; with a compliant value → 200.
7. Admin key: `PUT /clients/{id}` with a weak password → 422; the stored password hash is unchanged.
8. Mailbox regression: admin key `POST /mail/users` with `abcdefgh` → 422 as before (spec 028), and with a compliant
   password → 201.

## 3. Cleanup

1. Delete the created users, the mailbox, the website and the client through the API.
2. Wait until `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa038%'` and the ids created) after checking the names.
4. Verify no `qa038` client, group, user, website, database user, FTP or shell user, mailbox or client directory
   remains, that nothing is pending, and that the system configuration was never modified.
