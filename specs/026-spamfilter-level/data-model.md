# Data Model: Spam Filter Level Selection for Scoped Keys

No migrations. Existing tables `spamfilter_users` and `spamfilter_policy`.

## MailUserSpamFilter (`api/components/schemas/MailUserSpamFilter.yaml`) — addition

| Field | Type | Source / write |
|---|---|---|
| `policy_id` | integer ≥ 0 | read: `spamfilter_users.policy_id` WHERE `email` = mailbox address, 0 when missing; write: upsert (below) |

## MailDomain (`api/components/schemas/MailDomain.yaml`) — addition

| Field | Type | Source / write |
|---|---|---|
| `spamfilter_policy_id` | integer ≥ 0 | read: `spamfilter_users.policy_id` WHERE `email` = `@` + domain, 0 when missing; write: upsert when sent |

## spamfilter_users upsert

| Case | Datalog | Columns |
|---|---|---|
| row exists, `policy_id` differs | `u` | `policy_id` |
| row exists, same value | none | — |
| row missing (mailbox) | `i` | `server_id` = mailbox server, `priority` 7, `policy_id`, `email` = address, `fullname` = IDN-decoded address, `local` `Y`, `sys_userid` = acting user, `sys_groupid` = mailbox (domain) group, `sys_perm_user`/`group` `riud`, `sys_perm_other` '' |
| row missing (domain) | `i` | `server_id` = domain server, `priority` 5, `policy_id`, `email` = `fullname` = `@domain`, `local` `Y`, sys fields as above with the domain group |

## Validation

| Field | Rule | Message |
|---|---|---|
| `policy_id` | `sometimes`, integer, ≥ 0, non-zero must be a readable `spamfilter_policy.id` | `The selected policy id is invalid.` |
| `spamfilter_policy_id` | same | `The selected spamfilter policy id is invalid.` |

## Refusals

| Case | Status |
|---|---|
| mailbox / domain not readable | 404 (route binding) |
| readable without `u` | 403 `You do not have permission to update this resource.` |
| unreadable or nonexistent policy, non-integer, negative | 422 |
