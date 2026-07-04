# Feature Specification: Client-Scoped Permissions

**Feature Branch**: `011-client-scoped-permissions`
**Created**: 2026-07-04
**Status**: Draft
**Module**: cross-cutting (client / dns / mail / monitor / server / sites / system)
**Input**: User description: "The API currently operates at admin scope — every key sees and mutates everything. API keys are already bound to an ISPConfig sys_userid/sys_groupid. Enforce ISPConfig's legacy riud row-permission model (getAuthSQL/checkPerm), module-level access, and client resource limits so a client-bound key is confined to its own tenant."

> The API surface (7 modules, ~270 operations, 564 passing tests) is complete but authorization-flat: `ApiKeyAuth` resolves a key to a `sys_userid`/`sys_groupid` pair that is used only for **attributing** writes (`IspContext` → `BaseModel::applySysFieldDefaults`), never for **restricting** reads or writes. The frozen OpenAPI contract already declares 403 responses on most operations in anticipation of this feature. This spec activates ISPConfig's own authorization model — the same `sys_userid`/`sys_perm_user`, `sys_groupid`/`sys_perm_group`, `sys_perm_other` riud row model the legacy interface enforces through `getAuthSQL()`/`checkPerm()` — plus the legacy module-access and client-limit layers, without adding a single endpoint.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Row-level read scoping for non-admin keys (Priority: P1)

A hosting company mints an API key bound to a customer's ISPConfig user (`php artisan api:key:create "acme" --sys-userid=5 --sys-groupid=7`) and hands it to that customer's automation. When the customer's tooling calls `GET /api/v1/mail/domains`, it receives **only** the mail domains its ISPConfig user may read — exactly the rows the legacy interface would list for that login: rows it owns with `sys_perm_user` containing `r`, rows whose `sys_groupid` is in the user's expanded group set with `sys_perm_group` containing `r`, and world-readable rows (`sys_perm_other` containing `r`, e.g. the stock spamfilter policies). `GET /api/v1/mail/domains/{id}` for another tenant's domain returns 404 — the row does not exist for this key. Admin keys (bound to `sys_userid` 1) behave exactly as today: they see everything.

**Why this priority**: This is the confidentiality boundary. Until reads are scoped, a client key can enumerate every tenant's domains, mailboxes, databases and DNS zones — the API cannot be given to customers at all. Read scoping alone (with writes still admin-gated by not distributing non-admin keys for writes) is a viable, valuable MVP.

**Independent Test**: Seed two clients (two `sys_user`/`sys_group` pairs) each owning one mail domain; mint one key per client plus an admin key. `GET /mail/domains` with client A's key returns only A's domain and `meta.total == 1`; `GET /mail/domains/{B's id}` with A's key returns 404 problem+json; both requests with the admin key return both rows / 200.

**Acceptance Scenarios**:

1. **Given** rows owned by clients A and B, **When** A's key lists any scoped resource, **Then** only A-visible rows are returned and `meta.total` counts only those rows (silent filtering — no error, mirroring legacy `listform_actions` which ANDs `getAuthSQL('r')` into every list query).
2. **Given** a row A cannot read, **When** A's key requests `GET .../{id}`, `PUT .../{id}` or `DELETE .../{id}`, **Then** 404 problem+json (legacy `getDataRecord()` selects with `getAuthSQL('r')` and finds nothing — an unreadable row is indistinguishable from a nonexistent one).
3. **Given** a reseller key whose `sys_user.groups` CSV contains its clients' group ids, **When** it lists resources, **Then** rows of all its clients appear (group-clause match), alongside its own.
4. **Given** the stock spamfilter policies (`sys_perm_other='r'`), **When** any client key lists `GET /mail/spamfilter/policies`, **Then** the policies are visible (world-readable), but `PUT`/`DELETE` on them is denied.
5. **Given** an admin key (`sys_userid` 1, or a `sys_user` with `typ='admin'`), **When** it performs any request, **Then** behavior is byte-identical to the current implementation (no predicate applied — legacy `getAuthSQL()` returns `'1'` for admins).
6. **Given** nested sub-resources (`mail/users/{id}/filters`, `clients/{id}/templates`, `sites/web-domains/{id}/ssl`), **When** the parent row is not visible to the key, **Then** the whole subtree 404s (scoping applied at the parent binding).

---

### User Story 2 - Write enforcement, module gates and key ergonomics (Priority: P2)

The same customer key creates a new mail domain via `POST /mail/domains`. The created row is stamped with **the key's** identity (`sys_userid` 5 / `sys_groupid` 7) regardless of any `sys_*` values smuggled into the payload — exactly as the legacy interface stamps the session user on INSERT. Updating a visible row that the key may read but not update (no `u` in the matching perm field) returns 403 problem+json; deleting without `d` likewise. Calls to admin-only surfaces — everything under `/servers`, `/system`, `/monitor`, and `/resellers` — return 403 for non-admin keys, mirroring ISPConfig's module access model (`sys_user.modules` CSV: clients get `dashboard,mail,sites,dns,tools`, never `admin` or `monitor`). The operator meanwhile can mint tenant keys conveniently: `php artisan api:key:create "acme" --client-id=42` resolves the client's control-panel `sys_userid`/`sys_groupid` automatically.

**Why this priority**: Completes the integrity boundary — P1 stops information leaks, P2 stops cross-tenant mutation and privilege escalation (self-assigning another tenant's `sys_groupid`, editing global server config). Depends on P1's scope machinery.

**Independent Test**: With client A's key: `POST /mail/domains` with `{"sys_userid": 1, ...}` in the body creates a row stamped 5/7 (payload's sys fields ignored); `PUT` on a visible group-readable row without `u` perm → 403; `GET /servers` → 403; `DELETE /mail/domains/{B's id}` → 404. `api:key:create --client-id` resolves the right identity for a seeded client.

**Acceptance Scenarios**:

1. **Given** a non-admin key, **When** it creates any resource, **Then** the row's `sys_userid`/`sys_groupid` are forced to the key's identity and the permission preset follows the legacy tform `auth_preset` (`riud`/`riud`/`''`; `spamfilter_policy` keeps its `'r'` other-preset), with client-supplied `sys_*` input ignored.
2. **Given** a row visible to the key (readable) but without `u` in the matching perm letters, **When** `PUT`, **Then** 403 problem+json (legacy: `checkPerm($id,'u')` fails → "Update denied", and the UPDATE's `WHERE getAuthSQL('u')` matches nothing).
3. **Given** a row visible but without `d`, **When** `DELETE`, **Then** 403 (legacy `tform_actions::onDelete` errors with `error_no_delete_permission` for non-admins failing `checkPerm($id,'d')`).
4. **Given** a non-admin key, **When** any `/servers/**`, `/system/**`, `/monitor/**` or `/resellers/**` request is made (including GETs), **Then** 403 problem+json before any query runs.
5. **Given** a non-admin, non-reseller key, **When** any `/clients/**` request is made, **Then** 403 (the legacy `client` module is only granted to users with `limit_client > 0`, i.e. resellers); a reseller key passes the gate and row scoping confines it to its own clients.
6. **Given** a reseller key, **When** it `POST /clients`, **Then** the new client's group is appended to the **reseller's** `sys_user.groups` CSV and `parent_client_id` is forced to the reseller's own client id (legacy `client_edit.php` onAfterInsert behavior for non-admin creators).
7. **Given** `--client-id=42` where client 42 exists, **When** the operator runs `api:key:create`, **Then** the key is bound to the `sys_user`/`sys_group` pair created for that client (`sys_group.client_id = 42` → `groupid`; `sys_user.default_group = groupid` → `userid`); a nonexistent client id aborts with a clear error.

---

### User Story 3 - Client resource limits on create (Priority: P3)

The customer's plan allows 5 mail domains (`client.limit_maildomain = 5`). The 6th `POST /mail/domains` with their key returns 403 problem+json with a "limit reached" detail, mirroring legacy `checkClientLimit()`: a limit of `-1` is unlimited, `0` disables the resource type entirely (the legacy UI hides the menu item and `checkClientLimit` fails because `count >= 0`), and a positive limit caps the count of rows the client can already update. If the client belongs to a reseller, the reseller's own limit is also enforced across all of the reseller's clients (`checkResellerLimit()`).

**Why this priority**: Commercial quota enforcement — valuable but independent: P1/P2 already prevent cross-tenant damage; limits only cap volume inside one tenant. **This story is explicitly deferrable to a follow-up feature** if implementation shows it doubling the feature's size (it touches every create path and needs per-resource limit-name/type mappings). It is specced here so the decision is deliberate; see the resource→limit table under FR-020.

**Independent Test**: Seed client A with `limit_maildomain = 1` and one existing domain; `POST /mail/domains` with A's key → 403 "limit reached"; set limit `-1` → 201; set limit `0` (and zero existing domains) → 403. Admin key: never limited.

**Acceptance Scenarios**:

1. **Given** `limit_X = -1`, **Then** creates are never blocked by the limit (legacy `if ($client['number'] >= 0)` skips the check for negative limits).
2. **Given** `limit_X = 0`, **Then** every create of that resource type is denied for the client key (count `>= 0` always true) — this also covers the "feature not booked" gates the legacy menus apply (`limit_mailrouting`, `limit_mail_wblist`, `limit_spamfilter_wblist` default to 0).
3. **Given** `limit_X = n > 0` and `n` existing rows counted with the legacy counting rule (rows matching `getAuthSQL('u')` for the acting user, plus the form's type filter, e.g. `type='vhost'` for websites), **Then** the next create → 403; after deleting one row, create succeeds.
4. **Given** a client under a reseller whose reseller-level limit is exhausted across all its clients, **Then** create → 403 (legacy `checkResellerLimit`, counting over the reseller's groups ∪ userid).
5. **Given** a key whose `sys_user` has no `client` row (e.g. admin-created non-client user), **Then** limits do not apply (legacy `get_client_limit` returns `-1` when no client row exists).

---

### Edge Cases

- **Tables without `sys_*` permission columns**: `sys_datalog` (ispconfig3.sql:1668), `sys_log` (:1762), `monitor_data` (:1145), `sys_ini` (:1748), `server_php`/firewall/config blobs — cannot be row-scoped. Every API surface reading them (`/monitor/**`, `/system/config*`, `/servers/**`) is module-gated admin-only (see FR-013/FR-014), so no row predicate is needed. Models with `hasSysFields = false` (`app/Models/SysIni.php`, `app/Models/ClientTemplateAssigned.php`) are covered by the system-module gate and the scoped client parent binding respectively.
- **DataLog journal leaks**: `GET /monitor/data-logs` exposes full old/new payloads of every tenant's changes and `sys_datalog` has no perm columns. Legacy evidence: `monitor/datalog_list.php:45` gates on the `monitor` module (never in a client's default module CSV, config.inc.php:109) and its list definition disables row auth outright (`monitor/list/datalog.list.php:41` — `$liste['auth'] = 'no'`). **Decision: the entire monitor module is admin-only** for API keys.
- **Read-only monitor endpoints for client keys**: same as above — 403, not a filtered view. Legacy clients simply lack the `monitor` module; there is no legacy "client view" of `sys_log`/`monitor_data` to mirror.
- **`spamfilter_policy` world-read convention**: installer seeds policies with `sys_perm_other='r'`, `sys_groupid=0` (ispconfig3.sql:2523-2527) and the tform preset keeps `perm_other='r'` for new ones (mail/form/spamfilter_policy.tform.php `auth_preset`) — every key can read policies (they populate mailbox policy dropdowns), nobody but the owner (admin) can write them. Row scoping reproduces this with no special-casing.
- **Resources reached via parent scoping**: `/servers/{id}/...` is inside the admin-only server module, so parent scoping questions are moot there. Client-module sub-resources (`clients/{client}/templates`) scope through the client-row binding.
- **Group-0 rows**: rows with `sys_groupid = 0` (stock policies) match no group clause; visibility then rests on `sys_perm_other` — same as legacy (`IN (...)` never contains 0 because `sys_user.groups` holds real group ids).
- **Key bound to a `sys_userid` with no `sys_user` row**: legacy has no analog (a session always has a user row). Decision (owner, 2026-07-04): reject at auth time with **401**, same problem body as an invalid key — fail closed; a key pointing at a deleted/nonexistent login is a misconfiguration, not a degraded tenant. The dev key remains a synthetic admin (1/1) and never consults `sys_user`.
- **`sys_user.active` / `client` deactivation**: legacy blocks login of inactive users; API keys have their own `active` flag. Assumption (below): key `active` governs; `sys_user.active` is not consulted.
- **Mailuser bypass quirk**: legacy `getAuthSQL()` also bypasses for webmail-user sessions (`$_SESSION['s']['user']['mailuser_id'] > 0`, tform_base.inc.php:1744). API keys are never mailuser sessions — quirk intentionally not reproduced.
- **Legacy `LIKE '%perm%'` letter matching**: perms are letter sets (`riud`); the predicate must use containment semantics (`LIKE '%r%'`), not equality, to match rows with e.g. `perm_group='ru'`.
- **Admin identity definition**: legacy bypass keys off `typ = 'admin'` (tform_base.inc.php:1744, auth.inc.php:42-49), not userid 1. The API treats a key as admin when `sys_userid == 1` (preserves current dev-key/test behavior, userid 1 is the installer-seeded superadmin) **or** its `sys_user.typ = 'admin'` (parity for additional admin users).
- **Dev key** (`config('api.dev_key')` in local/testing): acts as 1/1 → admin, unchanged.
- **Reseller creating a client that itself is a reseller**: `/resellers/**` stays admin-only (legacy menu: client/lib/module.conf.php:28-45, `typ == 'admin'`); a reseller key gets 403 there even though it passes the `/clients/**` gate.

## API Contract *(mandatory)*

- **Spec file(s)**: **none added, none changed** — this feature introduces **no new endpoints and no shape changes**. It activates response-code semantics the contract already declares. Audit basis: all 259 operations under `api/modules/*/*.yaml` (2026-07-04).
- **Shared schemas**: unchanged; errors use the existing shared problem+json responses (`api/components/responses/{Forbidden,NotFound,Unauthorized}.yaml`).
- **Endpoints**: none new. The semantics activated per operation class:

| Situation (non-admin key) | Response | Contract status |
|---|---|---|
| List (GET collection), rows exist the key cannot read | 200, rows silently absent, `meta.total` = visible count | ✅ no change needed (200 declared everywhere) |
| Show/PUT/DELETE on a row the key cannot **read** | 404 problem+json | ✅ declared — audit: all 171 parameterized operations declare 404 |
| PUT on a visible row without `u` perm / DELETE without `d` | 403 problem+json | ✅ declared on every write operation of the client-accessible modules (client, dns, mail, sites) |
| POST stamped with forced sys fields | 201 (unchanged shape) | ✅ no change needed |
| POST denied by module gate / access limit (P2/P3) | 403 problem+json | ✅ declared on every POST of the client-accessible modules |
| Any request to an admin-only module (server, system, monitor) or `/resellers/**` | 403 problem+json | ⚠️ **65 operations lack a declared 403** — see below |
| Missing/invalid key | 401 | ✅ unchanged |

**Contract-compatibility verdict**: Row-level scoping (P1), write-permission enforcement and limit enforcement in the client-accessible modules (P2 create/update/delete paths, P3) require **zero contract changes** — every needed code (200/403/404) is already declared. The **only** gap is module-level admin gating: 67 of 259 operations declare no 403, of which 65 would actually emit one under this feature (the other 2 are `GET /mail/spamfilter/users` and `GET /mail/spamfilter/wblist` collection lists, which under silent list filtering never 403). The 65:

| File | Ops missing 403 |
|---|---|
| `api/modules/server/server-config.yaml` | 23 (all) |
| `api/modules/server/servers.yaml` | 5 (all) |
| `api/modules/server/ip-addresses.yaml` / `ip-mappings.yaml` / `php-versions.yaml` | 5 each (all) |
| `api/modules/server/firewall.yaml` | 3 (all) |
| `api/modules/system/directive-snippets.yaml` | 4 (GET list, POST, GET id, PUT — DELETE already declares 403) |
| `api/modules/system/dns-cas.yaml` | 2 (both GETs) |
| `api/modules/system/{system,sites,mail,dns,domains,misc}-config.yaml` | 1 each (the GETs; PUTs already declare 403) |
| `api/modules/system/resync.yaml` | 2 (both) |
| `api/modules/monitor/data-logs.yaml` / `server-status.yaml` | 2 each (all) |
| `api/modules/monitor/system-logs.yaml` | 1 (all) |

[NEEDS CLARIFICATION: resolution for the 65 — (a) additive contract amendment appending the shared `Forbidden` response ref to those operations (non-breaking, no shape/path/parameter change; recommended — Principle I says the spec must describe real behavior), or (b) ship enforcement emitting a 403 the YAML does not declare (works at runtime, leaves the contract lying). Owner sign-off required; option (a) is a docs-only diff confined to response maps.]

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (all paths under `source_code/`, read-only):
  - **`interface/lib/classes/tform_base.inc.php:1742-1769` — `getAuthSQL($perm)`**, the row predicate: line 1744 returns `'1'` (no restriction) for `typ == 'admin'` (or mailuser sessions); otherwise builds `((sys_userid = {userid} AND sys_perm_user LIKE '%{perm}%') OR (sys_groupid IN ({groups CSV}) AND sys_perm_group LIKE '%{perm}%') OR sys_perm_other LIKE '%{perm}%')` from the session user's `userid` (:1751-1756) and `groups` CSV (:1758-1763), with the world clause always appended (:1764).
  - **`interface/lib/classes/tform.inc.php:68-108` — `checkPerm($record_id, $perm)`**: for `record_id > 0`, re-selects the row with `getAuthSQL($perm)` ANDed (:81-86); for `record_id == 0` (insert) checks the form's `auth_preset` — and `userid == 0 && groupid == 0` presets mean *everyone may insert* (:100-102). Every CRUD tform consulted (`mail_domain`, `dns_soa`, `spamfilter_policy`) presets `userid=0, groupid=0, perm_user='riud', perm_group='riud'`, `perm_other=''` (`'r'` for spamfilter_policy).
  - **Read paths**: `tform_base.inc.php:1724-1731` (`getDataRecord` ANDs `getAuthSQL('r')` — unreadable ⇒ record simply not found); `interface/lib/classes/listform_actions.inc.php:242-247` (every list query ANDs `getAuthSQL('r')` unless the list opts out with `$liste['auth']='no'`); `listform.inc.php:105-111` (`{AUTHSQL}` placeholder in datasource queries).
  - **Write paths**: `tform_base.inc.php:1620-1630` (`getSQL` denies INSERT/UPDATE for non-admins failing `checkPerm(…,'i'/'u')`); `:1574` (UPDATE additionally carries `WHERE getAuthSQL('u') AND idx = id`); `:1548-1561` (INSERT always stamps `sys_userid` = session user, `sys_groupid` = session `default_group`, perms from `auth_preset` — the client cannot choose them); `interface/lib/classes/tform_actions.inc.php:328-332` (DELETE gate: non-admin needs `checkPerm($id,'d')`).
  - **Group expansion**: `$_SESSION['s']['user']` is the raw `sys_user` row (login/index.php:326-337), so the predicate's group set is the `sys_user.groups` CSV verbatim; `interface/lib/classes/auth.inc.php:100-121` (`add_group_to_user`) appends a new client's group to its reseller's CSV — that CSV is the entire reseller-sees-clients mechanism. `sys_user`/`sys_group` DDL: ispconfig3.sql:1852-1880 (`groups` TEXT, `default_group`, `typ`, `modules`, `client_id`), :1734-1740 (`sys_group.client_id`).
  - **Admin/reseller predicates**: `auth.inc.php:42-49` (`is_admin` = `typ=='admin'`), :51-58 (superadmin = admin && userid 1), :60-80 (`is_reseller`/`has_clients` = joined `client.limit_client != 0`).
  - **Module access**: `auth.inc.php:176-211` (`verify_module_permissions`/`check_module_permissions` — CSV membership test on `sys_user.modules`, failure = redirect ≙ deny); clients receive `$conf['interface_modules_enabled']` = `'dashboard,mail,sites,dns,tools'` (interface/lib/config.inc.php:109) plus `,client` only when `limit_client > 0` (client_edit.php:328-331) — never `admin`, never `monitor`. Admin-only page gates confirmed: `admin/server_config_edit.php:45` and `tools/resync.php:45` require module `admin`; `monitor/*.php:45` require module `monitor`.
  - **Admin-only-by-menu surfaces** (page itself checks only the parent module — enforcement in legacy is the module CSV + row ownership, the menu is cosmetic): dns templates (`dns/lib/module.conf.php:23-28`, `typ=='admin'`; `dns_template_edit.php:45` checks only module `dns`), spamfilter users/policies (`mail/lib/module.conf.php:115-126`), mail server settings — access rules, content filters, transports, relay domains/recipients (`mail/lib/module.conf.php:204-236`), resellers (`client/lib/module.conf.php:28-45`).
  - **Limits**: `tform.inc.php:183-209` (`checkClientLimit` — client row via `sys_group.client_id` join on the session `default_group`; `-1` = unlimited; else `COUNT(*)` of rows matching `getAuthSQL('u')` + optional type filter must stay below the limit), :211-250 (`checkResellerLimit` — parent client's limit counted over the reseller's groups ∪ userid); `auth.inc.php:124-146` (`get_client_limit` returns `-1` for non-client users). Call sites: `mail/mail_domain_edit.php:53-64`, `dns/dns_soa_edit.php:70-75`, `sites/web_vhost_domain_edit.php:98-116`, `mail/mail_transport_edit.php:60`. Limit column DDL with defaults: ispconfig3.sql:139-260.
  - **Legacy REMOTE API (comparison only — not our model)**: remote users are separate credentials (`remote_user`, ispconfig3.sql:1358-1370) with a per-function CSV (`remote_functions`) checked per call (remoting.inc.php:163-178), and `remoting_lib.inc.php:124-166` acts as **admin** (`sys_userid=1, groups=1`, :142-144) unless a client login/`client_id` param swaps in that client's `sys_user` (:154-156); its deletes do apply `getAuthSQL('d')` (:255). Our model deliberately stays key→`sys_userid`/`sys_groupid` with the interface (not remoting) semantics.
- **Legacy behaviors to mirror**: the exact `getAuthSQL` predicate incl. `LIKE '%perm%'` containment and admin bypass; unreadable ⇒ not-found; silent list filtering; non-admin `u`/`d` gates; INSERT identity stamping from the acting user with tform `auth_preset` perms; module-CSV access mapped to route-group gates; group-CSV expansion (reseller semantics); `checkClientLimit`/`checkResellerLimit` (P3).
- **Tables written (via datalog only)**: **none newly** — this feature adds no write paths; it restricts existing ones. Datalog attribution (`sys_datalog.user` from `IspContext::username()`) is already per-key and unchanged.
- **System fields handling**: `BaseModel::applySysFieldDefaults()` currently *defaults* `sys_userid`/`sys_groupid` from `IspContext` but lets pre-set attributes win; for non-admin keys the identity pair MUST be **forced** (overwrite), while resource-specific perm presets (e.g. spamfilter_policy `perm_other='r'`) are preserved. Admin keys keep today's behavior (services like `ClientService` legitimately pre-set reseller ownership).
- **Intentional deviations from legacy** (each justified):
  1. **403 instead of silent no-op/HTML error for denied updates/deletes**: legacy UPDATE `WHERE getAuthSQL('u')` affects 0 rows and `$app->error()` renders HTML; a REST API must signal explicitly. Visible-but-forbidden = 403, invisible = 404 (anti-enumeration, same information disclosure as legacy).
  2. **Admin-menu-only write surfaces get hard 403 gates** (dns templates writes, spamfilter policies/users writes, mail access-rules/transports/relay-domains/relay-recipients/content-filters writes for keys without the corresponding limit): legacy "enforcement" there is menu hiding — a client crafting a direct POST to e.g. `spamfilter_policy_edit.php` would succeed. The API hardens this (deny by default; the limit-gated ones open up exactly where legacy limits grant access — `limit_mailrouting`, `limit_mail_wblist`, `limit_spamfilter_wblist` etc., all defaulting to 0/disabled). Documented as hardening, not parity.
  3. **No mailuser-session bypass** (see Edge Cases).
  4. **Module gate = route prefix, not `sys_user.modules` CSV**: API keys have no per-key module list; the gate derives from key class (admin / reseller / client) exactly as the default CSVs assign modules. A per-key module CSV is out of scope.

## Requirements *(mandatory)*

### Functional Requirements

**Auth scope resolution (foundation)**

- **FR-001**: The system MUST resolve, once per authenticated request, an **AuthScope** for the key: `sysUserId`, `sysGroupId`, expanded `groupIds` (parsed from the bound `sys_user.groups` CSV, united with the key's `sys_groupid`), `isAdmin`, and lazily `isReseller`. Group source parity: login/index.php:326-337 + ispconfig3.sql:1867 (`groups` TEXT).
- **FR-002**: `isAdmin` MUST be true iff `sys_userid == 1` **or** the bound `sys_user.typ == 'admin'` (parity: tform_base.inc.php:1744, auth.inc.php:42-49; preserves current admin/dev-key behavior).
- **FR-003**: `isReseller` MUST be true iff the bound user's `client.limit_client != 0` (parity: auth.inc.php:69-80).
- **FR-004**: Admin keys MUST bypass every check introduced by this feature; the entire existing test suite (564 tests, admin dev key) MUST pass unchanged.
- **FR-005**: A non-admin key whose `sys_userid` has no `sys_user` row MUST be rejected with 401, fail-closed, using the same problem body as an invalid key (owner decision 2026-07-04: 401 chosen over group-set degradation; the dev key is exempt — it stays a synthetic admin and never consults `sys_user`).

**P1 — read scoping**

- **FR-006**: Every query on a model with sys fields (all `BaseModel` subclasses except `hasSysFields=false` ones) serving a non-admin request MUST be restricted by the legacy read predicate: `(sys_userid = :uid AND sys_perm_user LIKE '%r%') OR (sys_groupid IN (:groupIds) AND sys_perm_group LIKE '%r%') OR (sys_perm_other LIKE '%r%')` (parity: tform_base.inc.php:1750-1765).
- **FR-007**: List endpoints MUST filter silently — 200 with only visible rows, `meta.total` counting only visible rows (parity: listform_actions.inc.php:242-247).
- **FR-008**: Show/update/delete of a row failing the read predicate MUST return 404 problem+json, including via implicit route-model binding — the binding query itself must be scoped so unauthorized ids resolve to nothing (parity: tform_base.inc.php:1724-1731).
- **FR-009**: Nested sub-resource routes MUST inherit scoping through their parent binding (unreadable parent ⇒ 404 for the whole subtree).
- **FR-010**: Datalog machinery MUST remain unscoped internally: `BaseModel::freshRecordFromDatabase()` (already `newQueryWithoutScopes`), `DatalogService`, and uniqueness/existence validation that is intentionally global (e.g. domain uniqueness across tenants) MUST NOT be restricted by the read predicate.

**P2 — write enforcement, module gates, ergonomics**

- **FR-011**: Non-admin update MUST require `u` and delete MUST require `d` on the target row under the same three-clause predicate evaluated against the row's values; visible-but-unpermitted ⇒ 403 problem+json (parity: tform_base.inc.php:1626/1574; tform_actions.inc.php:328-332).
- **FR-012**: Non-admin creates MUST force `sys_userid`/`sys_groupid` to the key's identity, ignoring any client-supplied `sys_*` values, and keep the per-resource legacy `auth_preset` permission letters (parity: tform_base.inc.php:1548-1561).
- **FR-013**: `/servers/**`, `/system/**` and `/monitor/**` MUST return 403 problem+json for every non-admin request (module gate; parity: `sys_user.modules` never contains `admin`/`monitor` for clients — config.inc.php:109, client_edit.php:328-331, admin/server_config_edit.php:45, tools/resync.php:45, monitor/datalog_list.php:45).
- **FR-014**: The DataLog journal (`/monitor/data-logs*`) MUST be admin-only — `sys_datalog` has no perm columns and its list bypasses row auth in legacy (`monitor/list/datalog.list.php:41`), so any non-admin exposure would leak cross-tenant change payloads.
- **FR-015**: `/resellers/**` MUST be admin-only (parity: client/lib/module.conf.php:28-45, `typ == 'admin'`).
- **FR-016**: `/clients/**` (clients, circles, templates, template assignments, client domains) MUST require admin **or** reseller keys; reseller visibility within it is then row-scoped (parity: `client` module granted only with `limit_client > 0`, client_edit.php:328-331).
- **FR-017**: Admin-menu-only mail/dns write surfaces MUST be gated for non-admin keys (hardening deviation 2): writes to `dns/templates`, `mail/spamfilter/policies`, `mail/spamfilter/users`, `mail/relay-domains`, `mail/relay-recipients`, `mail/content-filters` and `mail/spamfilter/config*` are admin-only; writes to `mail/transports` and `mail/access-rules` additionally require the acting client's `limit_mailrouting != 0` / `limit_mail_wblist != 0` respectively, and `mail/spamfilter/wblist` requires `limit_spamfilter_wblist != 0` (parity: mail/lib/module.conf.php:58-77,104-126,204-236; mail_transport_edit.php:60). Reads of these resources stay row-scoped (policies world-readable by seed convention).
- **FR-018**: When a reseller key creates a client, the system MUST force `parent_client_id` to the reseller's own client id and append the new group to the reseller's `groups` CSV (parity: client_edit.php:349-362; the existing `ClientService` group-append path is reused with the acting key's identity instead of an explicit `parent_client_id`).
- **FR-019**: `api:key:create` MUST accept `--client-id=<id>`, resolving `sys_group.groupid` by `client_id` and `sys_user.userid` by `default_group = groupid`, mutually exclusive with `--sys-userid`/`--sys-groupid`, aborting with a non-zero exit and clear message when the client or its control-panel user does not exist.

**P3 — client resource limits (deferrable as a block)**

- **FR-020**: Non-admin creates MUST enforce `checkClientLimit` parity (tform.inc.php:183-209): resolve the acting client via `sys_group.client_id` of the key's `sys_groupid`; `-1` skips; otherwise count rows matching the `u` predicate for the key (plus the legacy type filter) and deny with 403 problem+json (detail "limit reached", mirroring legacy `limit_*_txt` errors) when `count >= limit`. Resource→limit map (defaults from ispconfig3.sql:139-260): `mail/domains`→`limit_maildomain`(-1); `mail/users`→`limit_mailbox`(-1); `mail/forwards`→`limit_mailalias`/`limit_mailforward`/`limit_mailcatchall` by type (-1); `mail/alias-domains`→`limit_mailaliasdomain`(-1); `mail/transports`→`limit_mailrouting`(0); `mail/access-rules`→`limit_mail_wblist`(0); `mail/fetchmail`→`limit_fetchmail`(-1); `mail/spamfilter/wblist`→`limit_spamfilter_wblist`(0); `mail/spamfilter/users`→`limit_spamfilter_user`(0); `mail/spamfilter/policies`→`limit_spamfilter_policy`(0); `dns/soa`→`limit_dns_zone`(-1); `dns/records`→`limit_dns_record`(-1); `dns/slaves`→`limit_dns_slave_zone`(-1); `sites/web-domains`→`limit_web_domain`(-1, `type='vhost'`); `sites/web-child-domains`→`limit_web_subdomain`/`limit_web_aliasdomain` by type (-1); `sites/ftp-users`→`limit_ftp_user`(-1); `sites/shell-users`→`limit_shell_user`(0); `sites/webdav-users`→`limit_webdav_user`(0); `sites/cron-jobs`→`limit_cron`(0); `sites/databases`→`limit_database`(-1); `sites/database-users`→`limit_database_user`(-1); `clients` (by reseller keys)→`limit_client`(0).
- **FR-021**: When the acting client has a `parent_client_id`, the reseller's limit MUST also be enforced across the reseller's whole group set (parity: tform.inc.php:211-250).
- **FR-022**: Keys bound to users without a `client` row MUST be unlimited (parity: auth.inc.php:139-141).

**Cross-cutting**

- **FR-023**: All new denials MUST use RFC 9457 problem+json via the existing `Problem` support (403 `Forbidden`, 404 `Not Found`), matching the shared response components.
- **FR-024**: This feature MUST NOT add, remove or reshape any endpoint; the only permissible contract diff is the additive 403 declaration on the 65 flagged operations, pending the NEEDS CLARIFICATION sign-off in the API Contract section.
- **FR-025**: Write attribution must keep working from non-HTTP contexts (CLI, tests): the default AuthScope outside a request is admin 1/1 (current `IspContext` defaults).

### Key Entities

- **sys_user** (ispconfig3.sql:1852-1880): the ISPConfig login a key impersonates — `userid`, `groups` (CSV of `sys_group.groupid`, the reseller-expansion vehicle), `default_group`, `typ` (`admin`/`user`), `modules` (CSV, legacy module gate), `client_id`. Read-only for this feature; no model exists yet beyond `app/Models/SysUser.php`.
- **sys_group** (ispconfig3.sql:1734-1740): tenant group — `groupid`, `client_id` (join point for limits and `--client-id`). `app/Models/SysGroup.php` exists.
- **ApiKey** (`app/Models/ApiKey.php`, API-owned `api_keys` table): already carries `sys_userid`/`sys_groupid`; gains no columns — AuthScope is derived at request time.
- **AuthScope** (new, request-scoped value object — no table): `sysUserId`, `sysGroupId`, `groupIds[]`, `isAdmin`, `isReseller`; the single source for every predicate/gate in this feature.
- **client** (ispconfig3.sql:139): `limit_*` columns (P3), `parent_client_id` (reseller chain), reached via `sys_group.client_id`.
- **Scoped rows**: every ISPConfig table with `sys_userid`/`sys_groupid`/`sys_perm_user`/`sys_perm_group`/`sys_perm_other` — i.e. every `BaseModel` subclass with `hasSysFields = true` (all current models except `SysIni`, `ClientTemplateAssigned`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For **every** row-scoped resource in the client-accessible modules (client, dns, mail, sites), a four-key test matrix (admin / owner client / other client / reseller-of-owner) exists and passes: admin sees all; owner sees own; other client sees nothing of owner's (list absent + show/update/delete 404); reseller sees its client's rows.
- **SC-002**: A client key can complete its full legitimate lifecycle on its own resources (create → list → show → update → delete) with rows stamped with its identity — verified per module by feature tests asserting the created rows' `sys_userid`/`sys_groupid` equal the key's binding even when the payload claims otherwise.
- **SC-003**: Every operation under `/servers`, `/system`, `/monitor` and `/resellers` returns 403 problem+json for a client key and for a reseller key (spot-check matrix ≥ 1 op per YAML file), and continues to work for the admin key.
- **SC-004**: Cross-tenant mutation is impossible: a suite-wide test proves `PUT`/`DELETE` with key A against every scoped resource id owned by B returns 404, and no `sys_datalog` row is produced by the attempt.
- **SC-005**: The pre-existing test suite passes unchanged (admin-scope regression guarantee), and the OpenAPI spec still parses/renders (no shape drift).
- **SC-006**: Visible-but-unpermitted rows produce 403 (not 404, not 200): verified with a fixture row readable via `sys_perm_other='r'` but owned by another user (spamfilter-policy convention).
- **SC-007** (P3, if not deferred): limit matrix (`-1`, `0`, `n` with `n` existing, reseller-cap) passes for at least `mail/domains`, `dns/soa`, `sites/web-domains`, and `clients`-by-reseller; a "limit reached" create leaves no datalog row.
- **SC-008**: `api:key:create --client-id` resolves identity correctly for a seeded client and fails cleanly for a missing one (command feature tests).

## Assumptions

- **Key provisioning is operator-side**: tenant keys are minted by the hosting operator via `api:key:create`; there is no self-service key endpoint (out of scope).
- **Admin bypass definition**: `sys_userid == 1` or `sys_user.typ == 'admin'` — per FR-002; the dev key (1/1) stays admin.
- **Key `active` flag governs revocation**; `sys_user.active`/client suspension is not re-checked per request (legacy checks it at login only; keys are the API's session analog). Flagging a suspended client's keys is an operator action.
- **Module gating is by key class** (admin / reseller / client) mapped to route groups, not a per-key module CSV — matching how the default `sys_user.modules` CSVs are assigned in legacy (config.inc.php:109, client_edit.php:328-331).
- **Field-level restrictions are out of scope**: legacy hides/forces certain fields for non-admins (e.g. clients cannot pick `server_id` or client ownership on some forms). This feature enforces row/module/limit access; field-level parity is a follow-up feature. Documented so it is not mistaken for covered ground.
- **Mailinglist, XMPP and APS surfaces** don't exist in the API — their legacy gates are out of scope.
- **P3 deferral is pre-authorized**: if the per-resource limit wiring exceeds roughly the size of P1+P2 combined during planning/implementation, US3 ships as feature 012 with no changes to P1/P2 artifacts (the AuthScope/`u`-predicate machinery it needs is built in P1/P2).
- **A populated `dbispconfig` is available in production**; tests use the established SQLite in-memory `tests/Support/*Schema.php` pattern extended with `sys_user`/`sys_group`/`client` tenant fixtures.
- **Legacy verified against the vendored `source_code/` tree (ISPConfig 3.2.x)**; every parity claim above cites file:line in that tree.
