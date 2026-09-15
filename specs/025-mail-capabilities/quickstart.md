# Quickstart: Mail Capabilities and Email Program Settings for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MeCapabilitiesApiTest|MeMailSettingsApiTest|MailTabEnforcementTest|UsageSummaryApiTest|MailUserSubresourceApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`); do not change system settings (another session tests
on the same server — the tab-off cases are covered by the automated tests). With a QA admin key
(`ispconfig-rest key:create "qa025 admin"`, plaintext kept in a root-only file, never printed):

1. `POST /clients` for `qa025…` with `mail_servers=1`, `limit_mailcatchall=1`, `limit_mailfilter=2`; mint its client
   key (`POST /system/api-keys`).
2. Client key:
   - `GET /me/capabilities` → 200, `mail = {autoresponder: true, mail_filters: true, custom_rules: false,
     spamfilter_policy: true, dkim: true, custom_login: false, password_policy: {min_length: 8, min_strength: 3,
     ascii_only: false}}`
   - `GET /me/mail-settings` → 200, one server `{server_id: 1, host: "isp-test.feldhost.cz", webmail_url:
     "https://isp-test.feldhost.cz:8081/webmail", imap 993 ssl, pop3 995 ssl, smtp 587 starttls + 465 ssl}`,
     `webmail_link = true`
   - `?client_id=1` → 404; `?foo=1` → 400
   - `POST /mail/domains` `qa025-<rand>.test`, `POST /mail/users` `box@…`, `POST /mail/forwards` catch-all →
     `GET /usage/summary` `counts.mail_catchalls = {used: 1, limit: 1}`, `counts.mail_filters = {used: 0, limit: 2}`
   - `PUT /mail/users/{id}/spamfilter` `{custom_mailfilter: "…"}` → 422 with `error_types.custom_mailfilter`
     feature-not-allowed; `{move_junk: "n"}` → 200; `PUT …/autoresponder` → 200; `POST …/filters` → 201 and
     `counts.mail_filters.used = 1`
3. Admin key: `GET /me/mail-settings` → 422; `?client_id=<temp>` → 200 identical; `PUT …/spamfilter`
   `{custom_mailfilter: ""}` → 200.
4. Cleanup: `DELETE /clients/<temp>` (admin, cascades), wait until `server.updated` ≥ the last datalog id, delete QA
   keys by SQL (`DELETE FROM api_keys WHERE id IN (…) AND name LIKE 'qa%'`), verify no `qa025` client, mail_domain,
   mail_user, mail_forwarding, spamfilter_users, mail_user_filter rows, `/var/vmail/qa025-*` directories or client
   directories remain.

## 3. Results

Recorded in tasks.md after the run.
