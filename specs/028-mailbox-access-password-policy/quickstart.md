# Quickstart: Mailbox Access Switches and Password Policy

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MailPasswordPolicyTest|MailboxAccessPasswordPolicyTest|MailUserApiTest|MailUserSubresourceApiTest|ClientLockApiTest|LockedClientWriteGuardTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`); do not change system settings (isp-test policy:
`min_password_length=8`, `min_password_strength=3`). QA admin key `qa028 admin`; passwords are generated in the
script and never printed.

1. `POST /clients` `qa028…` with `mail_servers=1`, `limit_maildomain=1`, `limit_mailbox=2`; client key; client creates
   mail domain `qa028-<rand>.example.test`.
2. Password policy (client key unless noted):
   - `POST /mail/users` password `abcdefgh` → 422 with the "8 chars … Good" message; admin key same → 422
   - `POST /mail/users` compliant password → 201
   - `PUT /mail/users/{id}/password` `abcdefgh` → 422; compliant → 200
   - `PUT /mail/users/{id}` `password: ""` → 200, hash unchanged
3. Switches:
   - `doveadm auth test -x service=imap` and `-x service=pop3` for the mailbox → succeeded
   - `PUT /mail/users/{id}` `{disableimap: true, disablepop3: true}` → 200; row `disableimap`, `disablesieve`,
     `disablesieve-filter`, `disablepop3` = `y`; `GET` shows true; doveadm IMAP and POP3 auth → failed
   - `{disableimap: false, disablepop3: false, disabledeliver: true}` → IMAP/POP3 auth succeeded again; `disablelda`,
     `disablelmtp` = `y`
4. Lock interaction: admin `PUT /clients/<temp>` `locked: true` → mailbox `disablesmtp = y`; client `PUT`
   `disablesmtp: false` → 403 account-locked; client `PUT` `disabledeliver: false` → 200; admin `PUT` `disablesmtp:
   false` → 200; admin unlocks.
5. Cleanup: delete mailbox, domain and client (admin), wait until `server.updated` ≥ the last datalog id, delete QA keys
   by SQL, verify no `qa028` rows, `/var/vmail` or client directories remain.

## 3. Results

Recorded in tasks.md after the run.
