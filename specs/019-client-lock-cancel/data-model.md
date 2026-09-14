# Data Model: Client Lock and Cancel Side Effects

No new tables or columns. Existing ISPConfig columns and their roles in this feature:

## client

| Column | Type | Role |
|--------|------|------|
| `client_id` | int PK | client identity (`id` in the API) |
| `locked` | enum `'n'`/`'y'` (API boolean) | change `n→y` runs lock, `y→n` runs unlock; `y` activates the FR-013 guard |
| `canceled` | enum `'n'`/`'y'` (API boolean) | change toggles `sys_user.active`; `y` on create creates the login inactive |
| `tmp_data` | text, not exposed | lock snapshot (PHP serialized array, see contracts/lock-snapshot.md); written directly (Principle II exception) |
| `limit_client` | int | reseller detection only (`/resellers` binding) |

State transitions:

```text
locked:   n --PUT locked=true--> y   (lock: records disabled, snapshot stored)
          y --PUT locked=false-> n   (unlock: records restored, prev_active removed)
          n --PUT locked=false-> n   (no side effects)
canceled: n --PUT canceled=true--> y (sys_user.active = 0)
          y --PUT canceled=false-> n (sys_user.active = 1)
create:   canceled=true  -> sys_user.active = 0; locked=true -> stored only
```

## sys_group

| Column | Role |
|--------|------|
| `groupid` | the client's group; lock selects records by `sys_groupid = groupid` |
| `client_id` | link group → client; guard resolves a record's owner client through it |

## sys_user (control-panel login)

| Column | Role |
|--------|------|
| `userid` | client's control-panel user; `sys_userid` written on lock/unlock of `/clients` |
| `client_id` | selects the user(s) of a client |
| `active` | `0` = login refused (canceled), `1` = login allowed; written directly (Principle II exception) |

## Lock-managed records

| Table | Key | Lock column | Disabled value | Enabled value |
|-------|-----|-------------|----------------|---------------|
| `cron` | `id` | `active` | `n` | `y` |
| `ftp_user` | `ftp_user_id` | `active` | `n` | `y` |
| `mail_domain` | `domain_id` | `active` | `n` | `y` |
| `mail_user` | `mailuser_id` | `postfix` | `n` | `y` |
| `mail_user` | `mailuser_id` | `disablesmtp` (reversed) | `y` | `n` |
| `mail_forwarding` | `forwarding_id` | `active` | `n` | `y` |
| `mail_get` | `mailget_id` | `active` | `n` | `y` |
| `openvz_vm` | `vm_id` | `active` | `n` | `y` (skipped when the table is absent) |
| `shell_user` | `shell_user_id` | `active` | `n` | `y` |
| `webdav_user` | `webdav_user_id` | `active` | `n` | `y` |
| `web_database` | `database_id` | `active` | `n` | `y` |
| `web_domain` | `domain_id` | `active` | `n` | `y` |
| `web_folder` | `web_folder_id` | `active` | `n` | `y` |
| `web_folder_user` | `web_folder_user_id` | `active` | `n` | `y` |

Every lock/unlock write is a datalog `u` of `{lock column, sys_userid}` (suppressed when neither changes).

## Validation / rules

- Lock/cancel side effects run only when the stored value (read under row lock) differs from the requested one.
- FR-013 guard (non-admin scopes, lock-managed tables, owner client `locked = 'y'`):
  - create → 403;
  - update changing a lock column from disabled to enabled value → 403;
  - both before any DB write, no datalog.
