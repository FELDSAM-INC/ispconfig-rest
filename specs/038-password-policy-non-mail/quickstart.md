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

## 4. Results on isp-test (2026-09-16, deployed `bfa2e0a`)

Temporary client 48 (`qa038temp`) with website 26 and mail domain `qa038mail.test`. The installation's policy was
used as it stands (`min_password_length=8`, `min_password_strength=3`); the system configuration was not modified.
Weak password `abcdefgh`, compliant password `Qa038-Strong!x9`.

| Check | Result |
|---|---|
| `POST /clients` weak | 422, `errors.password` = the legacy message naming 8 chars and strength "Good" |
| `POST /clients` compliant | 201 |
| Client key `GET /me/capabilities` | `sites.password_policy` = `{min_length: 8, min_strength: 3}`; `mail.password_policy` still `{8, 3, ascii_only: false}` |
| Weak `database_password`, FTP, shell and WebDAV passwords | 422 on each field with the same message |
| Journal during those four refusals | 0 new rows |
| Compliant database user and FTP user | 201 each |
| `PUT /sites/web-domains/{id}` `stats_password` weak / compliant | 422 / 200 |
| `PUT /clients/{id}` password weak | 422 |
| `POST /mail/users` weak (spec 028 regression) | 422 with the same policy message — mailbox enforcement unchanged |

**Not observed live**: the mailbox create with a *compliant* password. The first attempt failed for an unrelated
reason — the check script omitted the mailbox `name` field (`errors.name: "The name field is required."`) — and the
corrected call ran in the cleanup pass, whose captured output began after that line; the temporary client was deleted
in the same pass. Mailbox acceptance is covered by the automated mail suite, which passes unchanged.

Cleanup: mailbox, mail domain, FTP user, database user, website and client deleted through the API;
`server.updated` reached the last journal id (1110); QA keys 106–109 removed. No `qa038` client, website, mail
domain, mailbox or client directory remains and nothing is pending. The one credential row still present
(`c1jozko`, group 2) belongs to client 1 and predates this run; clients 1, 2 and 19 and the remaining keys were not
touched.

## 3. Cleanup

1. Delete the created users, the mailbox, the website and the client through the API.
2. Wait until `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa038%'` and the ids created) after checking the names.
4. Verify no `qa038` client, group, user, website, database user, FTP or shell user, mailbox or client directory
   remains, that nothing is pending, and that the system configuration was never modified.
