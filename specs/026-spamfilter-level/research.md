# Research: Spam Filter Level Selection for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-15.

## R1 — Where legacy stores the level

**Legacy**: `spamfilter_users` holds one row per recipient key: the mailbox address (`mail_user_edit.php`
onAfterInsert 336–360 / onAfterUpdate 395–480) and `@domain` (`mail_domain_edit.php` onAfterInsert 364–390 /
onAfterUpdate 468–492). Both forms post `policy` (select built from `spamfilter_policy WHERE getAuthSQL('r')`, option 0
"inherit" for mailboxes, "no policy" for domains). When the row exists only `policy_id` is datalog-updated, and only if
it differs; otherwise a row is datalog-inserted:

| Column | Mailbox | Domain |
|---|---|---|
| `email` | address | `@domain` (IDN-encoded) |
| `fullname` | `idn_decode(address)` | `@domain` |
| `priority` | 7 | 5 |
| `local` | `Y` | `Y` |
| `server_id` | mail domain server | domain server |
| `sys_userid` | session user | session user |
| `sys_groupid` | mail domain group | domain group (or chosen client group) |
| `sys_perm_*` | `riud`, `riud`, '' | `riud`, `riud`, '' |

The writes are direct `$app->db->datalogInsert/Update` calls — no permission check on the companion row. isp-test
rows: `@test.cz` priority 5 policy 7; `info@test.cz`, `e2e-claude@test.cz` priority 7 policy 0.

**Decision**: `SpamfilterUserService::assign(string $email, int $policyId, array $insertDefaults)` — datalog update of
`policy_id` when the row exists and differs, datalog insert with the legacy defaults otherwise, via `DatalogService`
(companion-row side effect like the existing domain delete cascade). `MailUserService::syncSpamfilterUser()` keeps its
policy-0 insert on mailbox create/update.

## R2 — Field placement and names

**Decision** (owner-delegated): mailbox `policy_id` on `GET/PUT /mail/users/{id}/spamfilter` (the mailbox spam
settings resource) and domain `spamfilter_policy_id` on the mail domain resource (`GET` show/list, `POST`, `PUT`) — the
names of the WHMCS module contract. The domain value is presented by the controller (not a model accessor) so other
serializations of `MailDomain` do not query `spamfilter_users`; the list loads the page's values with one `whereIn`
query.

**Alternatives considered**: `GET/PUT /mail/domains/{id}/spamfilter` sub-resource — rejected, the module contract
already names `spamfilter_policy_id` on the domain; a `MailDomain` `$appends` accessor — rejected (N+1 on lists, stale
cache after `refresh()`).

## R3 — Which policies may be chosen

**Legacy**: the select shows `getAuthSQL('r')` policies; `spamfilter_users.tform.php` 78–86 uses `{AUTHSQL}` for the
same datasource; the posted value itself is only `intval`-ed.

**Decision**: non-zero ids must pass the spec 024 readable-reference rule (`ScopesReferences::readableQuery
('spamfilter_policy')`); the failure message is the one for a nonexistent id (`The selected policy id is invalid.` /
`The selected spamfilter policy id is invalid.`). Stricter than legacy (owner-delegated). `GET
/mail/spamfilter/policies` is already row-scoped for customer keys (`SpamfilterPolicyController::index` via
`listQuery`) — description updated only.

## R4 — Permission to change the level

**Legacy**: the forms are reachable only with `u` on the mailbox or domain (`tform->checkPerm`).

**Decision**: when the field is present, the controller checks `AuthScope::allows($record, 'u')` on the bound mailbox
or domain before any write (403 `You do not have permission to update this resource.`, the BaseModel message). Route
binding already hides unreadable records (404). No client limit (`ClientLimitService` deliberately does not count
`spamfilter_users`, 011 FR-017) and no lock-guard entry (not a service switch).

## R5 — When the domain row is written

**Legacy**: every domain insert/update upserts `@domain`, inserting policy 0 when no level was chosen.

**Decision** (owner-delegated): only when `spamfilter_policy_id` is in the request. A policy-0 `@domain` row has the
same effect as no row (`spamfilter_users` lookups fall back to the server default), so omitting it avoids datalog
noise and keeps existing domain write payloads unchanged.

## R6 — Interplay with features 019, 024, 025

- 025 tab gate: `policy_id` is not a mail filter tab field (`UpdateMailUserSpamFilterRequest::MAIL_FILTER_TAB_FIELDS`),
  so hidden tabs never block it; `custom_mailfilter` refusal unchanged.
- 024: the mailbox/domain come from read-scoped route binding; the policy reference is scoped (R3).
- 019: `spamfilter_users` is not in `ClientLockService` lock columns.

## R7 — Tests and schemas

`MailCompletionSchema` has the full `spamfilter_users` columns; `SystemSchema`'s variant lacks `policy_id`, `priority`,
`fullname`, `local` and sys fields — extended so every suite that lists mail domains can read the level.
