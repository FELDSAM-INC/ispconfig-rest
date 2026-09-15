# Contract Notes: Scoped Parent References

No new paths, parameters, schemas or status codes. The following operation descriptions in `api/modules/` gain a
sentence stating that, for client and reseller keys, the referenced row must be visible to the key and that a row the
key cannot see is reported exactly like a nonexistent one:

| File | Operations | Referenced field(s) | Existing response reused |
|------|------------|---------------------|--------------------------|
| `mail/users.yaml` | POST, PUT | domain of `email` | 400 |
| `mail/forwards.yaml` | POST (source), POST/PUT (alias destination) | `source`, `destination` | 400, 422 |
| `mail/alias-domains.yaml` | POST, PUT | `source`, `destination` | 400 |
| `mail/spamfilter-wblist.yaml` | POST | `rid` | 404 |
| `dns/records.yaml` | POST, PUT | `zone` | 422 |
| `sites/web-domains.yaml` | POST, PUT | `parent_domain_id` | 422 |
| `sites/web-child-domains.yaml` | POST, PUT | `parent_domain_id` | 422 |
| `sites/ftp-users.yaml`, `sites/shell-users.yaml`, `sites/cron-jobs.yaml` | POST, PUT | `parent_domain_id` | 422 |
| `sites/webdav-users.yaml`, `sites/web-folders.yaml` | POST | `parent_domain_id` | 422 |
| `sites/databases.yaml` | POST, PUT | `parent_domain_id`, `database_user_id`, `database_ro_user_id` | 422 |
| `sites/web-folder-users.yaml` | POST | `web_folder_id` | 422 |

Sentence used: "For client and reseller keys the referenced … must be visible to the key (ISPConfig
`getAuthSQL('r')`); one it cannot see is rejected exactly like a nonexistent one."
