# Feature Specification: Client Resource Limits (quota counting enforcement)

**Feature Branch**: `012-client-resource-limits`
**Created**: 2026-07-05
**Status**: Draft
**Module**: cross-cutting (client / dns / mail / sites)
**Input**: User description: "Complete the client-scoped authorization story from feature 011: enforce ISPConfig's per-client resource *count* limits (`client.limit_*`) on create. A client with `limit_maildomain = 5` may create 5 mail domains; the 6th is denied. Mirror legacy `checkClientLimit()` / `checkResellerLimit()` exactly."

> This feature is **User Story 3 (P3) of feature 011**, pre-authorized and deferred there (011 spec Assumptions: "P3 deferral is pre-authorized… ships as feature 012 with no changes to P1/P2 artifacts"). Feature 011 already shipped the machinery this builds on: `App\Support\AuthScope` (with `applyReadPredicate($query, 'u')` — the legacy `getAuthSQL('u')` triplet), the request-scoped `IspContext::authScope()`, the write chokepoint in `App\Models\BaseModel::save()/delete()`, and `App\Services\ClientLimitService` with the **access gate** (`resourceEnabled()` — a limit column of `0` fully disables a resource type → 403). What 011 did **not** ship is the **counting** enforcement: comparing the number of existing rows a client owns against a positive limit. That is this feature. It adds **no endpoints** and, as verified below, **no contract changes**. It continues 011's design; it does not re-derive it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Row-count limits on high-value resources (Priority: P1)

A hosting operator provisions a customer whose plan permits 5 mail domains, 20 mailboxes, 3 websites, 2 databases, 5 FTP users, and 3 DNS zones (`client.limit_maildomain = 5`, `limit_mailbox = 20`, `limit_web_domain = 3`, `limit_database = 2`, `limit_ftp_user = 5`, `limit_dns_zone = 3`). The customer's key creates resources normally until it reaches a cap; the next create returns **403 problem+json** whose `detail` names the exhausted limit ("You have reached your account limit of 5 mail domains."). Deleting one row frees a slot. A limit of `-1` (the install default) never blocks. An admin key is never limited. This is the subset a runaway or malicious client could most damage the host with (mailbox floods, disk via databases/websites, shell access), so it ships first.

**Why this priority**: These are the security- and cost-relevant resources. Feature 011 already stops cross-tenant access; the remaining abuse vector inside one tenant is unbounded volume of the expensive resources. Enforcing the high-value counts is a viable MVP on its own — the other counts (P2) are lower-risk cardinalities.

**Independent Test**: Seed client A with `limit_maildomain = 1` and one existing A-owned `mail_domain` row. `POST /mail/domains` with A's key → 403, `detail` names the limit, and **no** `sys_datalog` row is written. `setClientLimit('clientA','limit_maildomain',-1)` → `POST` → 201. `setClientLimit(...,0)` with zero existing rows → 403 (disabled). Admin key: always 201. Repeat per P1 resource.

**Acceptance Scenarios**:

1. **Given** `limit_maildomain = -1`, **When** A creates any number of mail domains, **Then** the limit never blocks (legacy `if ($client['number'] >= 0)` skips the count for negative limits — tform.inc.php:197).
2. **Given** `limit_maildomain = 0` and zero A-owned domains, **When** A `POST /mail/domains`, **Then** 403 (count `0 >= 0` is always true — the disabled semantics; also caught earlier by the 011 access gate where wired).
3. **Given** `limit_maildomain = n > 0` and exactly `n` A-owned rows (counted with the legacy `getAuthSQL('u')` predicate), **When** A creates the `(n+1)`-th, **Then** 403; after A deletes one, the next create → 201 (tform.inc.php:203 `if ($tmp['number'] >= $client['number'])`).
4. **Given** `limit_web_domain = 1` and one A-owned `web_domain` row of `type='vhost'`, **When** A creates a second vhost, **Then** 403; the count applies the legacy **type filter** `type='vhost'` so child domains (subdomains/aliases) do not consume the vhost limit (web_vhost_domain_edit.php:98).
5. **Given** an admin key at any limit value, **When** it creates, **Then** never blocked (legacy checks only `typ == 'user'` — mail_domain_edit.php:57; `AuthScope->isAdmin` bypasses).
6. **Given** a key whose `sys_user` has no `client` row, **When** it creates, **Then** never limited (legacy `get_client_limit` returns `-1` — auth.inc.php:139-141).

---

### User Story 2 - Row-count limits on the remaining resources + reseller double-cap (Priority: P2)

The same enforcement extends to every other resource ISPConfig row-count-limits: mail aliases / forwards / catchalls / alias-domains (all rows of `mail_forwarding`, discriminated by `type`), mail filters, fetchmail accounts, web child domains (subdomains vs alias-domains), WebDAV users, cron jobs, database users, DNS slave zones, and — where booked (limit ≠ 0) — mail transports, mail access rules and the spamfilter wblist. Additionally, **a client that belongs to a reseller is capped by the reseller's limit too**: if reseller R's `limit_maildomain = 10` and R's clients already hold 10 mail domains between them, R's client cannot create an 11th even if its own `limit_maildomain` allows it (legacy `checkResellerLimit()`). A reseller key creating clients is capped by its own `limit_client`.

**Why this priority**: Same mechanism as P1, applied to lower-cardinality / lower-risk resources, plus the reseller aggregation. Independently valuable but not the primary abuse surface, so it follows P1. The reseller cap depends only on the same count machinery.

**Independent Test**: For each P2 resource, run the `-1 / 0 / n` matrix as in US1. Reseller cap: seed reseller R with `limit_maildomain = 1`, client A (`parent_client_id = R`) with `limit_maildomain = -1`, and one R-owned or A-owned mail domain; `POST /mail/domains` with A's key → 403 (`detail` "Reseller: …"); with no rows anywhere → 201. `mail/forwards` with `type='alias'` counts against `limit_mailalias`; with `type='forward'` against `limit_mailforward`; each type independent.

**Acceptance Scenarios**:

1. **Given** `limit_mailalias = 1` and one A-owned `mail_forwarding` row of `type='alias'`, **When** A `POST /mail/forwards` with `type='alias'`, **Then** 403; but a `type='forward'` create still succeeds if `limit_mailforward` allows (per-type filters — mail_alias_edit.php:59, mail_forward_edit.php:59).
2. **Given** `limit_web_subdomain = 0` (default `-1`, operator set to 0), **When** A `POST /sites/web-child-domains` with a subdomain type, **Then** 403; the count filter is `(type='subdomain' OR type='vhostsubdomain')` (web_childdomain_edit.php:80).
3. **Given** client A under reseller R, A's own `limit_dns_zone = -1` but R's `limit_dns_zone = n` already reached across R's group set, **When** A `POST /dns/soa`, **Then** 403 — the reseller cap counts `(sys_groupid IN (R.groups) OR sys_userid = R.userid)` (tform.inc.php:238).
4. **Given** a reseller key R with `limit_client = 2` and 2 clients already owned by R's group, **When** R `POST /clients`, **Then** 403; the count is the **bespoke** `SELECT count(client_id) FROM client WHERE sys_groupid = :R.group` (client_edit.php:68 — NOT the `getAuthSQL('u')` predicate).
5. **Given** `limit_database_postgresql = 1` and one A-owned `web_database` of `type='postgresql'`, **When** A `POST /sites/databases` with `type='postgresql'`, **Then** 403; MySQL creates still counted only against `limit_database` (database_edit.php:272-274 — bespoke `sys_groupid` predicate + `type='postgresql'`).
6. **Given** `mail/spamfilter/policies` and `mail/spamfilter/users` are **admin-only writes** in the API (011 FR-017 / routes/api/mail.php `scope.admin`), **Then** a non-admin key never reaches their create path, so `limit_spamfilter_policy` / `limit_spamfilter_user` counting is unreachable and is **not** wired (documented, not a gap).

---

### User Story 3 - Quota-sum limits (Priority: P3)

Three limits are **SUM-of-quota caps**, not row counts: `limit_web_quota` (total web disk MB), `limit_mailquota` (total mailbox MB), and `limit_database_quota` (total database MB) — plus `limit_traffic_quota` (total monthly traffic). The check is fundamentally different: it sums the `quota` column of the client's existing rows (excluding the row being edited), adds the **new row's** requested quota, and denies if the sum exceeds the limit; it also forbids an *unlimited* (0 or negative) quota on a single row when the account has a finite cap. A client with `limit_mailquota = 1000` and 900 MB already allocated may create a 100 MB mailbox but not a 200 MB one.

**Why this priority**: Distinct mechanism (payload-dependent, per-resource unit conversions, "cannot be unlimited when capped" rule, and one resource — databases — uses a different count predicate than the rest). Valuable but self-contained; recommended for delivery after P1/P2 and independently deferrable (see plan.md "P3 quota design" and the recommendation below).

**Independent Test**: Seed client A with `limit_mailquota = 1000` and one 900 MB A-owned mailbox. `POST /mail/users` with `quota = 200` → 403 ("only 100 MB free"); `quota = 100` → 201; `quota = 0` (unlimited) with a finite cap → 403. `limit_mailquota = -1` → any quota allowed.

**Acceptance Scenarios**:

1. **Given** `limit_mailquota = 1000` MB and `SUM(existing mailbox quota) = 900` MB, **When** A creates a mailbox with `quota = 200`, **Then** 403 (`900 + 200 > 1000`; mail_user_edit.php:216-219, quota stored in bytes → /1024/1024).
2. **Given** a finite `limit_web_quota`, **When** A sets a web domain `hd_quota = 0` (unlimited) or negative, **Then** 403 (web_vhost_domain_edit.php:1123 `new_web_quota < 0 && limit >= 0`; and the `limit_web_quota_not_0` rule).
3. **Given** the acting client has a `parent_client_id`, **Then** the reseller's `limit_*quota` SUM is also enforced across the reseller's rows (mail_user_edit.php:231-244; web_vhost:1157-1161; database_edit.php:228-231).
4. **Given** `limit_database_quota`, **Then** the existing-quota SUM uses the **bespoke** predicate `sys_groupid = :client.default_group` (database_edit.php:281), unlike `limit_web_quota`/`limit_mailquota` which use `getAuthSQL('u')` — implementations must not assume a single predicate.

---

### Edge Cases

- **Race condition (count-then-insert is non-atomic)**: two concurrent creates by the same key when `count == limit - 1` can both pass their count check and both insert, exceeding the cap by one. **Legacy has the identical gap** (it counts at form render `onShowNew`, even earlier than submit) and no ISPConfig table has a per-client-count constraint to enforce atomically. **Decision**: accept the gap; do not add locking or serialization. Documented, not fixed (Assumptions).
- **`type` not yet known at middleware time**: the count for `web_domain` / `mail_forwarding` depends on the row's `type`, which is only known after payload validation. The check therefore runs at the model save chokepoint, not as pre-validation route middleware (see plan.md "Chokepoint").
- **Limit column vs. acting client resolution mismatch**: legacy resolves the client via `sys_group.groupid = session.default_group → sys_group.client_id → client`; the API resolves via `AuthScope->clientId` (= `sys_user.client_id`). For a client's control-panel identity these coincide; feature 011 already adopted `clientId` and this feature continues it (Assumptions).
- **Cascade / service-initiated inserts**: `BaseModel::save()` is the create chokepoint; cascades under a non-admin key are legitimately that client's creates and are counted. Admin scopes bypass entirely, so admin-context cascades and the 564-test suite are unaffected.
- **`limit_dns_record` has NO legacy enforcement**: despite existing as a column (ispconfig3.sql:232) and appearing in 011's FR-020 draft map, there is **no `checkClientLimit('limit_dns_record')` call site anywhere** in `source_code/interface/web` — it is a dashboard *display* field only (dashboard/dashlets/limits.php:107). **DNS record creation is NOT count-limited** and is deliberately **not wired** (correcting the 011 FR-020 draft).
- **Delete then recreate** frees a slot immediately because the count is live (no soft-delete). A "limit reached" denial writes no `sys_datalog` row (the deny throws before `parent::save()`).
- **Update path**: row-count limits are a **create-only** concern (legacy checks only `onShowNew`); updates never change the row count, so they are never count-gated. Quota-SUM (P3) *is* re-checked on update because a quota change alters the sum (legacy checks it in the edit flow for both new and existing).

## API Contract *(mandatory)*

- **Spec file(s)**: **none added, none changed.** This feature adds no endpoints and no request/response shape changes. It activates a denial (403) the contract already declares on every gated create.
- **Shared schemas**: unchanged; the over-limit denial uses the existing `api/components/responses/Forbidden.yaml` (RFC 9457 `application/problem+json`).
- **Endpoints**: none new. Semantics activated on the create (`POST`) operations of the count-limited resources:

| Situation (non-admin key) | Response | Contract status |
|---|---|---|
| Create when `count < limit` (or `limit = -1`) | 201 (unchanged) | ✅ |
| Create when `count >= limit` (or `limit = 0`) | 403 problem+json, `detail` names the limit | ✅ **declared** |
| Create when reseller cap reached | 403 problem+json, `detail` prefixed "Reseller:" | ✅ declared |
| Quota-sum exceeded (P3) on `POST`/`PUT` | 403 problem+json | ✅ declared (403 present on those PUTs too) |
| Admin key, any limit | 201/200 unchanged | ✅ |

**Contract-compatibility verdict**: **ZERO contract changes required.** Every gated create operation already declares a `403` response. Verified 2026-07-05 by parsing every `POST` across `api/modules/**/*.yaml`: all 43 `POST` operations declare `403`, including every resource this feature gates — `/mail/domains`, `/mail/users`, `/mail/forwards`, `/mail/alias-domains`, `/mail/fetchmail`, `/mail/users/{id}/filters`, `/mail/transports`, `/mail/access-rules`, `/mail/spamfilter/wblist`, `/sites/web-domains`, `/sites/web-child-domains`, `/sites/databases`, `/sites/database-users`, `/sites/ftp-users`, `/sites/shell-users`, `/sites/webdav-users`, `/sites/cron-jobs`, `/dns/soa`, `/dns/slaves`, `/clients`, `/clients/domains`. **No gaps.** (The 65-operation 403 gap flagged in feature 011 was in the *admin-only* modules — server/system/monitor — none of which this feature touches; feature 011's contract amendment already added those. The P3 quota PUTs — `/mail/users/{id}`, `/sites/web-domains/{id}`, `/sites/databases/{id}` — also already declare 403.)

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (all under `source_code/`, read-only):
  - **`interface/lib/classes/tform.inc.php:183-209` — `checkClientLimit($limit_name, $sql_where='')`**: resolves the client via `SELECT {limit_name} as number, parent_client_id FROM sys_group, client WHERE sys_group.client_id = client.client_id AND sys_group.groupid = {session.default_group}` (:194); if `number >= 0` (i.e. limit not `-1`), runs `SELECT count({db_table_idx}) FROM {db_table} WHERE {getAuthSQL('u')} [AND {sql_where}]` (:198-202) and **fails when `count >= number`** (:203). So `-1` = unlimited (skip), `0` = disabled (`count >= 0` always), `n` = at most `n` rows. **Count predicate = `getAuthSQL('u')`** (the acting user's 3-clause update predicate), which for a reseller naturally spans its clients' rows (its `groups` CSV).
  - **`interface/lib/classes/tform.inc.php:211-250` — `checkResellerLimit($limit_name, $sql_where='')`**: resolves the acting client's `parent_client_id` (:222); if `!= 0`, loads the reseller's `userid, groups` from `sys_user WHERE client_id = parent_client_id` (:228) and the reseller's `{limit_name}` from `client WHERE client_id = parent_client_id` (:234); if `>= 0`, counts `SELECT count({db_table_idx}) FROM {db_table} WHERE (sys_groupid IN ({reseller.groups}) OR sys_userid = {reseller.userid}) [AND {sql_where}]` (:238-242) and **fails when `count >= number`** (:243). **Reseller count predicate is a raw group-OR-userid match — NOT `getAuthSQL('u')`** (no perm-letter clause).
  - **Call sites (both checks are called; only for `typ == 'user'`, admins skip)**: `mail/mail_domain_edit.php:57-64` (`limit_maildomain`), `mail/mail_user_edit.php:59` (`limit_mailbox`), `mail/mail_alias_edit.php:59` (`limit_mailalias`, `type='alias'`), `mail/mail_forward_edit.php:59` (`limit_mailforward`, `type='forward'`), `mail/mail_domain_catchall_edit.php:59` (`limit_mailcatchall`, `type='catchall'`), `mail/mail_aliasdomain_edit.php:59` (`limit_mailaliasdomain`, `type='aliasdomain'`), `mail/mail_user_filter_edit.php:59` (`limit_mailfilter`), `mail/mail_get_edit.php:59` (`limit_fetchmail`), `mail/mail_transport_edit.php:59` (`limit_mailrouting`), `mail/mail_blacklist_edit.php:60` + `mail_whitelist_edit.php:60` (`limit_mail_wblist`), `mail/spamfilter_*_edit.php:59` (`limit_spamfilter_wblist/user/policy`), `dns/dns_soa_edit.php:70` + `dns_import.php:51` + `dns_wizard.php:47` (`limit_dns_zone`), `dns/dns_slave_edit.php:59` (`limit_dns_slave_zone`), `sites/web_vhost_domain_edit.php:98` (`limit_web_domain`, `type='vhost'`), `sites/web_childdomain_edit.php:80,87` (`limit_web_subdomain` `(type='subdomain' OR type='vhostsubdomain')`; `limit_web_aliasdomain` `(type='alias' OR type='vhostalias')`), `sites/ftp_user_edit.php:59` (`limit_ftp_user`), `sites/shell_user_edit.php:59` (`limit_shell_user`), `sites/webdav_user_edit.php:59` (`limit_webdav_user`), `sites/cron_edit.php:60` (`limit_cron`), `sites/database_edit.php:59` (`limit_database`), `sites/database_user_edit.php:59` (`limit_database_user`), `client/domain_edit.php:73` (`limit_domainmodule`).
  - **Bespoke-predicate exceptions** (do NOT use `checkClientLimit`/`getAuthSQL('u')`): `client/client_edit.php:68` counts `client WHERE sys_groupid = {session.default_group}` for `limit_client` (reseller creating a client); `sites/database_edit.php:273` counts `web_database WHERE type='postgresql' AND sys_groupid = {default_group}` for `limit_database_postgresql`.
  - **Quota-SUM (P3)**: `mail/mail_user_edit.php:216-219` (`SUM(quota) FROM mail_user WHERE mailuser_id != :id AND getAuthSQL('u')`, bytes→MB; deny if `sum + new > limit` or unlimited-with-cap); `sites/web_vhost_domain_edit.php:1120-1128` (`SUM(hd_quota) … WHERE domain_id != :id AND type='vhost' AND getAuthSQL('u')`) and `:1139-1142` (`SUM(traffic_quota)`); `sites/database_edit.php:280-281` (`SUM(database_quota) FROM web_database WHERE sys_groupid = :default_group` — bespoke predicate); each with a reseller-SUM variant (mail_user_edit.php:231-244, web_vhost:1157-1176, database_edit.php:228-231) and a "cannot be 0/unlimited when capped" rule (web_vhost:1114-1115, database_edit.php:222-223).
  - **NOT enforced**: no `checkClientLimit('limit_dns_record')` call site exists (dashboard display only). `limit_directive_snippets`, `limit_cron_type`, `limit_cron_frequency`, `limit_cgi/ssi/perl/ruby/python/hterror/wildcard/ssl/…`, `limit_*_backup`, `limit_relayhost`, `limit_xmpp_*` toggles, `limit_web_ip` etc. are **not counts** (behavioral toggles / value lists) — see the classification table.
  - **`get_client_limit` (auth.inc.php:124-146)**: returns `-1` (unlimited) for users with no `client` row.
  - **Limit column DDL + defaults**: `install/sql/ispconfig3.sql:174-247` (client `limit_*` columns).
- **Legacy behaviors to mirror**: `-1`/`0`/`n` semantics; the `getAuthSQL('u')` count predicate + per-resource `type` filter; the raw reseller count predicate; both client and reseller checks on every gated create; admin bypass; unlimited for no-client keys; count-at-create-only (updates not count-gated); the bespoke `sys_groupid` predicates for `limit_client` / `limit_database_postgresql` / `limit_database_quota`.
- **Tables written (via datalog only)**: **none** — this feature restricts existing create paths; a denial writes nothing. On allow, the underlying resource's create datalog is unchanged.
- **System fields handling**: unchanged from 011 — non-admin inserts are stamped with the key's `sys_userid`/`sys_groupid`; the count predicate then sees those rows.
- **Intentional deviations from legacy** (each justified):
  1. **403 problem+json instead of an HTML form error**: legacy renders `$app->error($wordbook['limit_*_txt'])`; a REST API signals the policy denial explicitly. 403 (authorization/quota policy), not 422 (the payload is well-formed) — consistent with 011's access gate (`RequireClientLimit` already returns 403) and the contract's declared code.
  2. **Check at create-submit, not form-render**: legacy checks in `onShowNew()` (rendering the empty form); the API has no form step, so the equivalent point is the create request, evaluated at the model save chokepoint.
  3. **`limit_dns_record` and behavioral toggles are not wired** (parity: legacy does not count-limit them). This is faithfulness, documented so implementation does not miswire the `dns/records` create.
  4. **Spamfilter policy/user counts unreachable**: 011 hardened those writes to admin-only, so their legacy `checkClientLimit` never applies to a non-admin API key.

## Requirements *(mandatory)*

### Functional Requirements

**Foundation**

- **FR-001**: The system MUST extend `App\Services\ClientLimitService` with a `checkCreate(BaseModel $model): void` (or boolean) that enforces `checkClientLimit` + `checkResellerLimit` parity on create, throwing a 403-mapped denial when over limit. Admin scopes and keys without a `client` row MUST pass unconditionally (parity tform.inc.php:187 `typ=='user'` gate; auth.inc.php:139-141).
- **FR-002**: `checkCreate` MUST resolve the resource's limit configuration — `(limitColumn, typeWhere, countPredicate)` — from the model's table and, where the limit depends on the row kind, its `type` attribute, via a single central map (the **classification table** below). Tables absent from the map MUST NOT be limited.
- **FR-003**: The client count MUST be `SELECT count({pk}) FROM {table} WHERE {AuthScope->applyReadPredicate(q,'u')} [AND {typeWhere}]` and MUST deny (403) when `count >= limit` for `limit >= 0`; `limit == -1` MUST skip (parity tform.inc.php:197-205).
- **FR-004**: When the acting client's `client.parent_client_id != 0`, `checkCreate` MUST additionally enforce the reseller cap: load the reseller's `sys_user.userid`/`groups` (by `client_id = parent_client_id`) and the reseller's `{limitColumn}`; if `>= 0`, count `WHERE (sys_groupid IN ({reseller.groups}) OR sys_userid = {reseller.userid}) [AND {typeWhere}]` and deny when `count >= reseller.limit`. The reseller denial's `detail` MUST be distinguishable (prefix "Reseller:", parity mail_domain_edit.php:62). (tform.inc.php:211-250.)
- **FR-005**: The check MUST be invoked at the **create chokepoint** so every create path (controller, service, cascade) is covered with no per-controller code: `BaseModel::save()` on insert (`! $this->exists`), for non-admin sys-fielded models, before `parent::save()` — mirroring 011's write gate placement. A denial MUST throw before any DB write, so **no `sys_datalog` row is produced**.
- **FR-006**: The denial MUST surface as RFC 9457 403 `application/problem+json` via the existing `App\Support\Problem` / exception rendering, with a human `detail` naming the exhausted limit (mirroring legacy `limit_*_txt`).

**P1 — high-value counts** (all with `getAuthSQL('u')` predicate; parity call sites cited in the table)

- **FR-007**: Enforce `limit_maildomain` on `POST /mail/domains` (mail_domain, no filter).
- **FR-008**: Enforce `limit_mailbox` on `POST /mail/users` (mail_user, no filter).
- **FR-009**: Enforce `limit_web_domain` on `POST /sites/web-domains` (web_domain, `type='vhost'`).
- **FR-010**: Enforce `limit_database` on `POST /sites/databases` (web_database, no filter).
- **FR-011**: Enforce `limit_ftp_user` on `POST /sites/ftp-users` (ftp_user, no filter).
- **FR-012**: Enforce `limit_shell_user` on `POST /sites/shell-users` (shell_user, no filter; default 0 = disabled).
- **FR-013**: Enforce `limit_dns_zone` on `POST /dns/soa` (dns_soa, no filter).

**P2 — remaining counts + reseller & bespoke predicates**

- **FR-014**: Enforce the `mail_forwarding` per-type counts: `limit_mailalias` (`type='alias'`), `limit_mailforward` (`type='forward'`), `limit_mailcatchall` (`type='catchall'`) on `POST /mail/forwards` selected by the payload `type`; and `limit_mailaliasdomain` (`type='aliasdomain'`) on `POST /mail/alias-domains`.
- **FR-015**: Enforce `limit_mailfilter` on `POST /mail/users/{id}/filters` (mail_user_filter); `limit_fetchmail` on `POST /mail/fetchmail` (mail_get).
- **FR-016**: Enforce the web child-domain counts on `POST /sites/web-child-domains`: `limit_web_subdomain` (`(type='subdomain' OR type='vhostsubdomain')`) and `limit_web_aliasdomain` (`(type='alias' OR type='vhostalias')`) selected by payload `type`.
- **FR-017**: Enforce `limit_webdav_user` (webdav_user), `limit_cron` (cron), `limit_database_user` (web_database_user), `limit_dns_slave_zone` (dns_slave) — no type filters.
- **FR-018**: Enforce the count layer for the three access-gated (`scope.limit:*`) resources once booked (limit > 0): `limit_mailrouting` (mail_transport), `limit_mail_wblist` (mail_access), `limit_spamfilter_wblist` (spamfilter_wblist). The 011 middleware gate handles `limit == 0`; `checkCreate` adds the `n > 0` counting.
- **FR-019**: Enforce `limit_client` on `POST /clients` (reseller keys) with the **bespoke** count `client WHERE sys_groupid = {scope.sysGroupId}` (client_edit.php:68 — NOT `getAuthSQL('u')`), and `limit_domainmodule` on `POST /clients/domains` (domain table, `getAuthSQL('u')`).
- **FR-020**: Enforce `limit_database_postgresql` on `POST /sites/databases` **only when** the payload `type == 'postgresql'`, with the bespoke count `web_database WHERE type='postgresql' AND sys_groupid = {scope.sysGroupId}` (database_edit.php:273), in addition to the `limit_database` count of FR-010.
- **FR-021**: The system MUST NOT wire a count limit for `limit_dns_record` (no legacy call site) nor for `limit_spamfilter_policy`/`limit_spamfilter_user` (their create paths are admin-only per 011 FR-017 — unreachable by non-admin keys).

**P3 — quota-sum limits (distinct mechanism; independently deferrable)**

- **FR-022**: The system MUST support a `checkQuotaSum(BaseModel $model, quotaColumn, unit, typeWhere, predicate)` that, on create and update, sums `quotaColumn` over the client's existing rows (excluding the row being updated), adds the model's requested quota, and denies (403) when `sum + new > limit`, or when `new` is unlimited (`<= 0` for MB resources / `== 0` for mail) while `limit` is finite (parity mail_user_edit.php:216-219, web_vhost:1120-1128/1139-1142, database_edit.php:280-281).
- **FR-023**: `checkQuotaSum` MUST enforce, per resource, the correct **unit** (mail: bytes/1024/1024 = MB), **type filter** (`limit_web_quota`: `type='vhost'`), and **count predicate** (`limit_web_quota`/`limit_mailquota`/`limit_traffic_quota`: `getAuthSQL('u')`; `limit_database_quota`: bespoke `sys_groupid = {scope.sysGroupId}`), and the reseller-SUM variant when `parent_client_id != 0`.
- **FR-024**: Wire `limit_mailquota` (`POST`/`PUT /mail/users`), `limit_web_quota` + `limit_traffic_quota` (`POST`/`PUT /sites/web-domains`), `limit_database_quota` (`POST`/`PUT /sites/databases`).

**Cross-cutting**

- **FR-025**: Admin keys MUST bypass all checks in this feature; the pre-existing test suite MUST pass unchanged (the 011 admin-regression bar).
- **FR-026**: This feature MUST add, remove, or reshape **no** endpoint and require **no** contract change (403 already declared everywhere gated — API Contract section).
- **FR-027**: Row-count limits MUST apply on **create only** (updates do not change row counts); quota-sum limits (P3) apply on both create and update.

### Limit-column classification table *(the heart of this spec — implementation codes against this)*

Legend: **count** = row-count limit via `checkClientLimit`; **quota-sum** = SUM(quota) cap (P3); **behavioral** = per-resource toggle / value list, not a count; **reseller-meta / no-API** = no API surface or reseller-only. Predicate `u` = `getAuthSQL('u')` (`AuthScope::applyReadPredicate(q,'u')`); `grp` = bespoke `sys_groupid = {acting group}`. Story column: which US wires it (— = not wired).

| `client.limit_*` | Default | Class | API create endpoint | Count table | Type filter (`sql_where`) | Predicate | Story | Legacy cite |
|---|---|---|---|---|---|---|---|---|
| `limit_maildomain` | -1 | count | POST /mail/domains | `mail_domain` | — | u | P1 | mail_domain_edit.php:59 |
| `limit_mailbox` | -1 | count | POST /mail/users | `mail_user` | — | u | P1 | mail_user_edit.php:59 |
| `limit_web_domain` | -1 | count | POST /sites/web-domains | `web_domain` | `type='vhost'` | u | P1 | web_vhost_domain_edit.php:98 |
| `limit_database` | -1 | count | POST /sites/databases | `web_database` | — | u | P1 | database_edit.php:59 |
| `limit_ftp_user` | -1 | count | POST /sites/ftp-users | `ftp_user` | — | u | P1 | ftp_user_edit.php:59 |
| `limit_shell_user` | 0 | count | POST /sites/shell-users | `shell_user` | — | u | P1 | shell_user_edit.php:59 |
| `limit_dns_zone` | -1 | count | POST /dns/soa | `dns_soa` | — | u | P1 | dns_soa_edit.php:70 |
| `limit_mailalias` | -1 | count | POST /mail/forwards (`type=alias`) | `mail_forwarding` | `type='alias'` | u | P2 | mail_alias_edit.php:59 |
| `limit_mailforward` | -1 | count | POST /mail/forwards (`type=forward`) | `mail_forwarding` | `type='forward'` | u | P2 | mail_forward_edit.php:59 |
| `limit_mailcatchall` | -1 | count | POST /mail/forwards (`type=catchall`) | `mail_forwarding` | `type='catchall'` | u | P2 | mail_domain_catchall_edit.php:59 |
| `limit_mailaliasdomain` | -1 | count | POST /mail/alias-domains | `mail_forwarding` | `type='aliasdomain'` | u | P2 | mail_aliasdomain_edit.php:59 |
| `limit_mailfilter` | -1 | count | POST /mail/users/{id}/filters | `mail_user_filter` | — | u | P2 | mail_user_filter_edit.php:59 |
| `limit_fetchmail` | -1 | count | POST /mail/fetchmail | `mail_get` | — | u | P2 | mail_get_edit.php:59 |
| `limit_web_subdomain` | -1 | count | POST /sites/web-child-domains (subdomain) | `web_domain` | `(type='subdomain' OR type='vhostsubdomain')` | u | P2 | web_childdomain_edit.php:80 |
| `limit_web_aliasdomain` | -1 | count | POST /sites/web-child-domains (alias) | `web_domain` | `(type='alias' OR type='vhostalias')` | u | P2 | web_childdomain_edit.php:87 |
| `limit_webdav_user` | 0 | count | POST /sites/webdav-users | `webdav_user` | — | u | P2 | webdav_user_edit.php:59 |
| `limit_cron` | 0 | count | POST /sites/cron-jobs | `cron` | — | u | P2 | cron_edit.php:60 |
| `limit_database_user` | -1 | count | POST /sites/database-users | `web_database_user` | — | u | P2 | database_user_edit.php:59 |
| `limit_dns_slave_zone` | -1 | count | POST /dns/slaves | `dns_slave` | — | u | P2 | dns_slave_edit.php:59 |
| `limit_mailrouting` | 0 | count (access-gated 011) | POST /mail/transports | `mail_transport` | — | u | P2 | mail_transport_edit.php:59 |
| `limit_mail_wblist` | 0 | count (access-gated 011) | POST /mail/access-rules | `mail_access` | — | u | P2 | mail_blacklist_edit.php:60 |
| `limit_spamfilter_wblist` | 0 | count (access-gated 011) | POST /mail/spamfilter/wblist | `spamfilter_wblist` | — | u | P2 | spamfilter_blacklist_edit.php:59 |
| `limit_domainmodule` | 0 | count | POST /clients/domains | `domain` | — | u | P2 | domain_edit.php:73 |
| `limit_client` | 0 | count (reseller-meta) | POST /clients (reseller key) | `client` | — | **grp** | P2 | client_edit.php:68 |
| `limit_database_postgresql` | -1 | count (conditional) | POST /sites/databases (`type=postgresql`) | `web_database` | `type='postgresql'` | **grp** | P2 | database_edit.php:272-274 |
| `limit_mailquota` | -1 | **quota-sum** | POST/PUT /mail/users | `mail_user` SUM(`quota`) bytes→MB | — | u (excl. current) | P3 | mail_user_edit.php:216-219 |
| `limit_web_quota` | -1 | **quota-sum** | POST/PUT /sites/web-domains | `web_domain` SUM(`hd_quota`) MB | `type='vhost'` | u (excl. current) | P3 | web_vhost_domain_edit.php:1120-1128 |
| `limit_traffic_quota` | -1 | **quota-sum** | POST/PUT /sites/web-domains | `web_domain` SUM(`traffic_quota`) | — | u (excl. current) | P3 | web_vhost_domain_edit.php:1139-1142 |
| `limit_database_quota` | -1 | **quota-sum** | POST/PUT /sites/databases | `web_database` SUM(`database_quota`) MB | — | **grp** (excl. current) | P3 | database_edit.php:280-281 |
| `limit_dns_record` | -1 | **NOT enforced** | (POST /dns/records) | `dns_rr` | — | — | — | no call site (dashboard only) |
| `limit_spamfilter_user` | 0 | count but **unreachable** | POST /mail/spamfilter/users (admin-only write) | `spamfilter_users` | — | — | — | spamfilter_users_edit.php:59 + 011 FR-017 |
| `limit_spamfilter_policy` | 0 | count but **unreachable** | POST /mail/spamfilter/policies (admin-only write) | `spamfilter_policy` | — | — | — | spamfilter_policy_edit.php:61 + 011 FR-017 |
| `limit_aps` | -1 | count, **no API** | (APS installer) | `aps_instances` | — | — | — | aps_install_package.php:55 |
| `limit_mailmailinglist` | -1 | count, **no API** | (mailing lists) | `mail_mailinglist` | — | — | — | mail_mailinglist_edit.php:59 |
| `limit_openvz_vm` | 0 | count, **no API** | (OpenVZ) | `openvz_vm` | — | — | — | openvz_vm_edit.php:59 |
| `limit_xmpp_domain` / `limit_xmpp_user` | -1 | count, **no API** | (XMPP) | `xmpp_domain` / `xmpp_user` | — | — | — | xmpp_*_edit.php |
| `limit_directive_snippets` | n | **behavioral** (y/n) | — (per-site toggle; the `system/directive-snippets` resource is admin-only, unrelated) | — | — | — | — | web_vhost_domain_edit.php:1101 |
| `limit_cron_type` | url | **behavioral** (enum) | — | — | — | — | — | cron form (allowed cron kinds) |
| `limit_cron_frequency` | 5 | **behavioral** (min interval) | — | — | — | — | — | cron form |
| `limit_cgi`,`limit_ssi`,`limit_perl`,`limit_ruby`,`limit_python`,`force_suexec`,`limit_hterror`,`limit_wildcard`,`limit_ssl`,`limit_ssl_letsencrypt`,`limit_backup`,`limit_mail_backup`,`limit_relayhost`,`limit_xmpp_*` (y/n) | n/y | **behavioral** (y/n toggles) | — | — | — | — | — | web/mail/xmpp forms |
| `limit_web_ip`,`web_php_options`,`ssh_chroot`,`limit_xmpp_auth_options` | (list) | **behavioral** (value lists) | — | — | — | — | — | forms |
| `limit_client_prefix` | (n/a) | **not present** in vendored DDL | — | — | — | — | — | (schema uses `customer_no_template`; flag) |

### Key Entities

- **client** (ispconfig3.sql:139-263): source of `limit_*` values and `parent_client_id`; reached via `AuthScope->clientId` (= `sys_user.client_id`). Read-only.
- **sys_user / sys_group**: the reseller's `userid`/`groups` for the reseller cap; the acting `default_group` ↔ `sys_groupid` for the bespoke predicates. Read-only.
- **AuthScope** (`app/Support/AuthScope.php`, from 011): provides `applyReadPredicate(q,'u')`, `isAdmin`, `sysUserId`, `sysGroupId`, `clientId`.
- **ClientLimitService** (`app/Services/ClientLimitService.php`, from 011): extended here with `checkCreate` (+ P3 `checkQuotaSum`) and the resource map.
- **Counted resources**: every `BaseModel` in the classification table's "count"/"quota-sum" rows.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every **P1** gated resource, the `-1 / 0 / n` matrix passes with a client key: `-1` → unlimited creates; `0` (no rows) → 403; at exactly `n` owned rows → the next create 403 with a `detail` naming the limit; after deleting one → 201. Admin key at any limit → always 201.
- **SC-002**: A "limit reached" denial writes **no** `sys_datalog` row (assert the datalog table gains no row across the denied create).
- **SC-003**: The reseller double-cap passes: client A under reseller R with `A.limit = -1`, `R.limit = n`, `n` rows across R's group set → A's create 403 ("Reseller:" detail); with fewer rows → 201.
- **SC-004**: Per-**type** correctness: on `/mail/forwards`, `type='alias'` counts only against `limit_mailalias` (a `type='forward'` create still succeeds under `limit_mailforward`); on `/sites/web-domains`, only `type='vhost'` rows consume `limit_web_domain`.
- **SC-005**: Every **P2** gated resource passes the same matrix; `limit_client` (reseller `POST /clients`) and `limit_database_postgresql` (postgres only) use their bespoke `sys_groupid` counts.
- **SC-006**: `POST /dns/records` is **never** count-limited (regression guard against wiring `limit_dns_record`).
- **SC-007** (P3, if not deferred): the quota-sum matrix passes for `limit_mailquota`, `limit_web_quota`, `limit_database_quota`: `sum + new > limit` → 403; unlimited quota with a finite cap → 403; `-1` cap → any quota allowed; reseller-SUM variant enforced.
- **SC-008**: The pre-existing suite (admin dev key) passes unchanged; the OpenAPI spec still parses (no shape drift).

## Assumptions

- **P3 is independently deliverable and may be deferred**: the row-count P1/P2 is the feature's core value. If the quota-sum wiring (distinct `checkQuotaSum`, per-resource units/predicates, reseller-SUM, update-path) proves to exceed a timebox, US3 ships as feature 013 with no change to P1/P2 artifacts. **Recommendation: include P3 in this feature** — the create-time check can live at the same `BaseModel::save()` chokepoint (the quota value is a model attribute at save), so the marginal cost is one extra service method plus per-resource config, not new plumbing. Defer only if scheduling forces it.
- **Race condition accepted**: count-then-insert is non-atomic; concurrent creates can exceed a cap by one. Legacy has the same gap; no locking is added.
- **Client resolution via `AuthScope->clientId`** (`sys_user.client_id`) is treated as equivalent to legacy's `default_group → sys_group.client_id` for control-panel identities (011 decision, continued).
- **Chokepoint = `BaseModel::save()` on insert** (mirrors 011's write gate); no per-controller limit code. The `scope.limit:*` middleware from 011 stays as the `limit == 0` access gate; `checkCreate` adds `n > 0` counting.
- **403, not 422**, for over-limit (authorization/quota denial; payload valid), consistent with 011 and the frozen contract.
- **Legacy verified against the vendored `source_code/` tree (ISPConfig 3.2.x)**; every parity claim cites file:line.
- **`limit_client_prefix`** referenced in the feature brief does **not** exist in the vendored `client` DDL (which uses `customer_no_template`/`customer_no_start`); treated as not-applicable — flag for owner if a different schema version is targeted.

## NEEDS CLARIFICATION

- **NC-1**: `limit_domainmodule` gates the `domain` (domain-module) table via `client/domain_edit.php:73`. Confirm the API's `POST /clients/domains` resource maps to the `domain` table and that `limit_domainmodule` (default 0 = disabled) is the intended gate, or whether client-domain creation is admin/reseller-only in the API (then counting is unreachable, like spamfilter). Verify before wiring FR-019's domainmodule half.
- **NC-2**: Quota-SUM **update semantics**: legacy re-checks the sum on edit (excluding the current row). Confirm the API's `PUT /mail/users`, `PUT /sites/web-domains`, `PUT /sites/databases` should 403 on a quota bump that would exceed the cap (FR-022/FR-027 assume yes). If updates are out of P3 scope, restrict FR-024 to `POST`.
- **NC-3**: For `limit_database_postgresql` and `limit_client`, legacy uses the acting `default_group`; the API uses `scope.sysGroupId`. Confirm these are identical for the control-panel identities the API mints (they are under TenantFixtures), or whether the count must resolve `default_group` explicitly.
