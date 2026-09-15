# Quickstart: Scoped Parent References

## 1. Automated tests (PHP 8.3 in Docker)

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli \
  php artisan test --filter='ScopedReference(Mail|Sites|Dns)Test'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
vendor/bin/pint --test <changed files>
```

## 2. Manual check on isp-test (mail)

After `ispconfig-rest update` on `isp-test.feldhost.cz`:

1. Mint a QA admin key: `ispconfig-rest key:create "qa 024"`.
2. With the admin key create two temporary clients (`qa024a`, `qa024b`, mail server assigned), a client key for each
   (`POST /system/api-keys`), a mail domain per client (`qa024a.test`, `qa024b.test`) with the client's key, and a
   mailbox `box@qa024b.test` with B's key.
3. With A's key expect:

| Request | Expected |
|---------|----------|
| `POST /mail/users` `x@qa024b.test` | 400, same body as `x@missing-024.test` |
| `POST /mail/forwards` forward `postmaster@qa024b.test` → external | 400 |
| `POST /mail/forwards` catchall `@qa024b.test` | 400 |
| `POST /mail/forwards` alias `al@qa024a.test` → `box@qa024b.test` | 422 on `destination` |
| `POST /mail/alias-domains` `@qa024b.test` → `@qa024a.test` | 400 |
| `POST /mail/alias-domains` `@qa024a.test` → `@qa024b.test` | 400 |
| `POST /mail/users` `x@qa024a.test` | 201 |
| `POST /mail/forwards` alias `al@qa024a.test` → `x@qa024a.test` | 201 |

4. Confirm no datalog rows were written for the rejected requests (`SELECT MAX(datalog_id)` before/after).

## 3. Cleanup

Delete the two temporary clients with the admin key (`DELETE /clients/{id}`), wait until `server.updated` reaches the
last datalog id, then delete the QA keys by id (`DELETE FROM api_keys WHERE id IN (…) AND name LIKE 'qa%'`) and the
temporary clients' deactivated keys; verify no `qa024` rows or `/var/www/clients/client<id>` directories remain.

## 4. Results on isp-test (2026-09-15, deployed 58221b5)

Temporary clients 15 (A) and 16 (B), client keys #43/#44, QA admin key; mail domains `qa024a.test` (A) and
`qa024b.test` (B), mailbox `box@qa024b.test` (B).

| Request with client A key | Result |
|---------------------------|--------|
| mailbox `x@missing-024.test` | 400 "The domain 'missing-024.test' is not an existing mail domain." |
| mailbox `x@qa024b.test` | 400 "The domain 'qa024b.test' is not an existing mail domain." |
| forward `postmaster@qa024b.test` → external | 400 (same message) |
| catch-all `@qa024b.test` | 400 (same message) |
| alias `al@qa024a.test` → `box@qa024b.test` | 422 destination "The destination must be the email address of an existing mailbox." |
| alias `al@qa024a.test` → `nobody@qa024a.test` | 422 destination (same message) |
| alias domain `@qa024b.test` → `@qa024a.test` / `@qa024a.test` → `@qa024b.test` | 400 / 400 |
| datalog max before/after the rejections | 502 / 502 (nothing written); no rows on `qa024b.test` |
| mailbox `x@qa024a.test`, alias to it, forward to external | 201, 201, 201 |
| admin key: alias `adm@qa024a.test` → `box@qa024b.test` | 201 (unchanged) |

Cleanup: both clients deleted (204), `server.updated` reached the last datalog id (518), QA keys deleted by SQL; no
`qa024` clients, users, mail domains, mailboxes, forwards, keys, client directories or maildirs remain. Clients 1, 2
and `WHMCS-` clients untouched.

