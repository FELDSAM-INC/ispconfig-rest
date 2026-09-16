# Quickstart: Clearable String Settings in the System Configuration

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='SystemConfigClearableTest|SystemConfigApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'` (only commits already on
`origin/main`).

**This check changes system settings.** Back the blob up first and restore it at the end, verifying byte-identity:

```bash
mysql -N -B dbispconfig -e "SELECT config FROM sys_ini WHERE sysini_id=1" > /root/sysini-040.bak
```

Never touch clients 1, 2, 19 or any `customer_no` starting `WHMCS-`, and never delete another session's keys.

1. Admin key: read `/system/config` and note the current values of `webmail_url`, `dns_external_slave_fqdn`,
   `ssh_authentication` and `company_name` (isp-test: empty, empty, empty, empty).
2. Set each one to a test value (for example `https://webmail.example.test`, `ns9.example.test`, `key`,
   `QA040 Ltd`) and read them back → 200 and the new values.
3. Clear each one with `""` → **200** (this is the behaviour being added; before the fix it was 422) and a read
   returns an empty value.
4. Send `null` for one of them → treated the same as `""`.
5. Refusals that must stay: `{"web_php_options": []}` on `sites` → 422 naming the field;
   `{"default_webserver": ""}` on `sites` → 422; a `y`/`n` setting with `""` → 422.
6. Whole-document route: `PUT /system/config` with `{"misc": {"company_name": ""}}` → 200 and the value is empty.
7. Compare the blob with the backup: only the keys touched above may differ, and after restoring the original values
   it must be byte-identical (`cmp`).

## 3. Cleanup

1. Restore every setting to the value noted in step 1 (or restore `/root/sysini-040.bak` with a targeted SQL
   replacement if anything is left different), and prove byte-identity with `cmp`.
2. Delete the QA keys by SQL (`name LIKE 'qa040%'` and the ids created) after checking the names.
3. Remove `/root/sysini-040.bak` and confirm nothing is pending in the journal.
