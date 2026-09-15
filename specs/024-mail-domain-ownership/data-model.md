# Data Model: Scoped Parent References

No tables or columns change. The feature defines which referenced rows must be readable by the acting key.

| Write (non-admin key) | Field | Referenced table.column | Readability | Rejection |
|-----------------------|-------|-------------------------|-------------|-----------|
| `POST/PUT /mail/users` | `email` domain part | `mail_domain.domain` | `applyReadPredicate('r')` | 400 (nonexistent-domain body) |
| `POST /mail/forwards` | `source` domain part / `@domain` | `mail_domain.domain` | same | 400 |
| `POST/PUT /mail/forwards` (type `alias`) | each `destination` address | `mail_user.email` | same | 422 `destination` |
| `POST/PUT /mail/alias-domains` | `source`, `destination` | `mail_domain.domain` | same | 400 |
| `POST /mail/spamfilter/wblist` | `rid` (non-zero) | `spamfilter_users.id` | same | 404 |
| `POST/PUT /dns/records` | `zone` | `dns_soa.id` | same | 422 `zone` |
| `POST/PUT /sites/web-domains` (vhostsubdomain/vhostalias) | `parent_domain_id` | `web_domain.domain_id` (type vhost) | same | 422 |
| `POST/PUT /sites/web-child-domains` | `parent_domain_id` | `web_domain.domain_id` (type vhost) | same | 422 |
| `POST/PUT /sites/ftp-users`, `/shell-users`, `/cron-jobs`; `POST /webdav-users`, `/web-folders` | `parent_domain_id` | `web_domain.domain_id` (vhost/vhostsubdomain/vhostalias) | same | 422 |
| `POST/PUT /sites/databases` | `parent_domain_id`; `database_user_id`; `database_ro_user_id` (non-zero) | `web_domain.domain_id`; `web_database_user.database_user_id` | same | 422 |
| `POST /sites/web-folder-users` | `web_folder_id` | `web_folder.web_folder_id` | same | 422 |

Readability for a row: `sys_userid` = key user and `sys_perm_user` has `r`, OR `sys_groupid` in the key's group set and
`sys_perm_group` has `r`, OR `sys_perm_other` has `r`. Admin scopes skip the predicate.
