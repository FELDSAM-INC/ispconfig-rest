# Internal Contract: Lock Snapshot (`client.tmp_data`)

Shared with the legacy ISPConfig interface; must stay byte compatible.

## Shape

PHP `serialize()` of an array. Keys written by lock; any other keys already present are preserved.

```php
[
    // ... other keys kept as found ...
    'prev_active' => [
        'cron' => [],                                   // every table key is present, possibly empty
        'ftp_user' => [],
        'mail_domain' => [],
        'mail_user' => [
            12 => ['postfix' => 'n'],                    // receive was disabled before the lock
            13 => ['disablesmtp' => 'y'],                // send was already disabled before the lock
        ],
        'mail_forwarding' => [],
        'mail_get' => [],
        'openvz_vm' => [],
        'shell_user' => [],
        'webdav_user' => [],
        'web_database' => [],
        'web_domain' => [
            7 => ['active' => 'n'],                      // website was disabled before the lock
        ],
        'web_folder' => [],
        'web_folder_user' => [],
    ],
    'prev_sys_userid' => [
        'cron' => [],
        // ... same table keys ...
        'web_domain' => [
            7 => '1',                                    // previous owner (string), recorded but never restored
        ],
        // ...
    ],
]
```

- Table key order follows the legacy list; `mail_user` appears once (the `mail_user_smtp` pseudo entry merges
  into it).
- Record ids are integer array keys; `prev_sys_userid` values are strings.
- Only records whose previous state differs from "enabled" are listed in `prev_active`.

## Read rules

- `null` or `''` → empty array; `unserialize` with `allowed_classes => false`; non-array result → empty array.

## Unlock rules

- For each record: enabled value unless `prev_active[table][id][column]` equals the disabled value.
- Owner is always rewritten to the client's (or reseller's) control-panel user; `prev_sys_userid` is ignored
  (legacy reads `prev_sysuser`, a key never written).
- `prev_active` is removed; everything else, including `prev_sys_userid`, is written back.
