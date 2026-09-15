# Quickstart: Spam Filter Level Selection for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='SpamfilterLevelApiTest|MailDomainApiTest|MailUserSubresourceApiTest|MailTabEnforcementTest|ChangeSetHeader'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). QA admin key `qa026 admin` (plaintext in a root-only
file, never printed):

1. `POST /clients` `qa026…` with `mail_servers=1`, `limit_maildomain=1`, `limit_mailbox=1`; client key.
2. Client key:
   - `GET /mail/spamfilter/policies` → 200, the 7 world-readable policies
   - `POST /mail/domains` `{domain, spamfilter_policy_id: 5}` → 201, `spamfilter_policy_id = 5`; row `@domain` priority
     5, policy 5, `sys_groupid` = client group
   - `POST /mail/users` → 201; `GET …/spamfilter` → `policy_id = 0`; `PUT {policy_id: 7}` → 200; row priority 7
     policy 7; repeating the PUT → 200 and no new datalog entry
   - `PUT …/spamfilter {policy_id: 999999}` → 422
3. Admin key: `POST /mail/spamfilter/policies` private policy (not world-readable); client `PUT …/spamfilter` with it →
   422; admin `PUT` with it → 200; admin resets the mailbox to 0.
4. Client `PUT /mail/domains/{id} {spamfilter_policy_id: 0}` → 200; `GET /mail/domains` shows the values.
5. After the server processed the datalog: the legacy panel reads the same `spamfilter_users` rows (email, priority,
   policy_id); the rspamd user settings files of the two addresses exist.
6. Cleanup: delete mailbox, domain, client and the private policy (admin), wait until `server.updated` ≥ the last
   datalog id, delete QA keys by SQL, verify no `qa026` rows, spam filter user rows, rspamd files, `/var/vmail` or
   client directories remain.

## 3. Results

Recorded in tasks.md after the run.
