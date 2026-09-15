# Research: Scoped Parent References

## R1 — Predicate source

- **Decision**: use `AuthScope::applyReadPredicate($query, 'r')` from spec 011 on every reference lookup, via the acting
  key's scope from `IspContext`.
- **Rationale**: it is the API's port of legacy `getAuthSQL('r')` (user/group/other permission letters, reseller group
  CSV), already used for list and binding scoping, and a no-op for admin scopes.
- **Alternatives rejected**: comparing `sys_groupid` to the key's group only (misses world-readable rows and reseller
  scope, diverges from legacy); a policy class per model (larger change, no parity benefit).

## R2 — Error parity (no existence leak)

- **Decision**: foreign references return exactly the nonexistent-reference response: `Rule::exists` 422 message for
  id fields; the existing 400 "The domain '…' is not an existing mail domain." for name-based mail domains; the
  existing 404 for a spam filter `rid`.
- **Rationale**: legacy shows the same `no_domain_perm` / `no_zone_perm` / `no_folder_perm` message for missing and
  foreign rows; spec 011 already maps unreadable bound ids to 404 (not 403).
- **Implementation note**: `Rule::exists(...)->where(Closure)` registers a `using` callback, which Laravel's validator
  passes to the presence verifier as an extra `where` group, so the message is unchanged.

## R3 — Alias destinations

- **Legacy**: `mail_alias_edit.php:108-112` requires the destination to be a mailbox visible under `getAuthSQL('r')`
  (`no_destination_perm`); `mail_alias.tform.php` offers only those mailboxes. Forwarders
  (`mail_forward.tform.php`) and catch-alls (`mail_domain_catchall.tform.php:98-122`, datasource commented out) accept
  free text.
- **Decision**: for non-admin keys and type `alias`, every destination address (the API accepts a list) must be a
  readable `mail_user.email`; 422 on `destination` with "The destination must be the email address of an existing
  mailbox." (016 wording). On update the type comes from the stored record (immutable).
- **Admin keys**: unchanged — the current API lets admins alias to any address; restricting it now would be a
  breaking change outside this security fix (owner-delegated decision 2026-09-15).

## R4 — References restricted only by a form datasource in legacy

- **Legacy**: `database.tform.php:158,169` (`web_database_user WHERE {AUTHSQL}`) and
  `spamfilter_whitelist.tform.php:84-93` (`spamfilter_users WHERE {AUTHSQL}`) restrict the choices, but the edit pages
  do not re-check the submitted id.
- **Decision**: treat datasource restrictions as validation in the API (owner-delegated decision 2026-09-15): the API
  has no form, so without the check any id could be sent.

## R5 — Update paths

- Mailbox update: legacy re-checks the domain on every submit (`mail_user_edit.php:181-185`); the API re-resolves the
  domain from the stored email on update, so scoping `resolveMailDomain` covers both.
- Forward/alias/catch-all update: source and type are immutable; only alias destinations are re-checked.
- Alias domain update: a changed destination is re-resolved (scoped).
- Sites/DNS updates: requests that accept a changed `parent_domain_id` / `zone` / database users get the scoped rule;
  requests where the reference is immutable (webdav user, web folder, folder user) need nothing.

## R6 — Audit results

Affected and fixed (see spec Context table): mail users, forwards, alias domains, spam filter allow/deny list `rid`;
DNS records `zone`; web domains (vhostsubdomain/vhostalias) and child domains, FTP/shell/WebDAV users, cron jobs, web
folders, databases (parent, users), web folder users.

Not affected:
- Fetchmail destination — already scoped (016 FR-014).
- Spam filter users, relay domains, spam filter policies/config — writes admin-only (`scope.admin`).
- Mail user filters, autoresponder, cc, password, per-mailbox spam filter — parent bound by URL (`{mailUser}`), scoped
  by 011 route binding.
- Mail transports — no parent reference; limit-gated.
- Web domain `client_id` — `ResolvesClientOwnership` already restricts non-admin assignment.
- Backups (018) — website bound by URL.
