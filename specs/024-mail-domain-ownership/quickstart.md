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
