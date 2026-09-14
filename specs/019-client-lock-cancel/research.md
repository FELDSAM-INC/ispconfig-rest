# Research: Client Lock and Cancel Side Effects

Legacy source read-only on isp-test.feldhost.cz (ISPConfig 3.3.1p1, `/usr/local/ispconfig/interface`).

## R1 — Lock and unlock algorithm

**Decision**: port `functions.inc.php` `func_client_lock()` (lines 567-660) verbatim into
`App\Services\ClientLockService`:

- Table list, in legacy order (table → key column): `cron.id`, `ftp_user.ftp_user_id`,
  `mail_domain.domain_id`, `mail_user.mailuser_id` (column `postfix`), pseudo entry `mail_user_smtp` →
  table `mail_user`, column `disablesmtp`, reversed, `mail_forwarding.forwarding_id`, `mail_get.mailget_id`,
  `openvz_vm.vm_id`, `shell_user.shell_user_id`, `webdav_user.webdav_user_id`,
  `web_database.database_id`, `web_domain.domain_id`, `web_folder.web_folder_id`,
  `web_folder_user.web_folder_user_id`. Every other table uses column `active`.
- Identity: `sys_user.userid WHERE client_id = {client}` (first row) and
  `sys_group.groupid WHERE client_id = {client}`. Records are selected by `sys_groupid = groupid`.
- Lock: for each record, record `prev_active[table][id][column] = 'n'` when the column `!= 'y'` (not reversed)
  or `= 'y'` when reversed (`disablesmtp`); record `prev_sys_userid[table][id] = record.sys_userid` when it
  differs from the client's user id; then `datalogUpdate(table, [column => reversed ? 'y' : 'n',
  'sys_userid' => X], key, id)`.
- Unlock: for each record, value = reversed ? 'n' : 'y' unless `prev_active[table][id][column]` equals the
  inactive value; `datalogUpdate(table, [column => value, 'sys_userid' => Y], key, id)`; afterwards
  `unset($tmp['prev_active'])` and write the snapshot back (all other keys kept, including
  `prev_sys_userid`).
- `DatalogService::updateRecord()` is the exact equivalent of `db_mysql.inc.php::datalogUpdate()` (select old,
  update, re-select, log `u`, suppressed when no column changed) — used as is.
- Records are iterated ordered by key column (legacy SQL has no ORDER BY; ordering makes datalog order
  deterministic without changing the resulting state).
- `openvz_vm` does not exist in test schemas and may be absent: skip querying when `Schema::hasTable()` is
  false, but still initialize its empty snapshot arrays (byte parity with legacy snapshots).

**Rationale**: interchangeability with panel locks (spec SC-003) requires identical state transitions and
snapshot shape.

**Alternatives considered**: a model-based implementation per table (rejected: models add casts, gates and
limit checks that legacy side effects do not run; the service writes are admin-level bookkeeping).

## R2 — Snapshot format (`client.tmp_data`)

**Decision**: PHP `serialize()` of the existing snapshot array with:

- `prev_active` → for every table name in the list (pseudo `mail_user_smtp` merges into `mail_user`, key
  initialized once) an array, possibly empty, of `record id (int key) → [column → 'n'|'y']`;
  `mail_user` records may carry both `postfix` and `disablesmtp`.
- `prev_sys_userid` → same table keys, `record id → sys_userid` as a string (mysqli returns strings;
  `serialize` must emit `s:` values for parity).
- Read: `''`/`null` → `[]`; otherwise `unserialize($value, ['allowed_classes' => false])`, non-array → `[]`.
- Legacy unlock reads `prev_sysuser` (a key lock never writes), so previous owners are never restored — owner
  decision 23 mirrors this: unlock always writes the client's user (or reseller user) and ignores
  `prev_sys_userid`.

**Rationale**: FR-003; a snapshot written by either side must unlock identically on the other.

**Alternatives considered**: JSON snapshot (rejected: legacy panel could not read it).

## R3 — `sys_userid` attribution per endpoint

**Decision**:

| Path | Lock writes `sys_userid` | Unlock writes `sys_userid` |
|------|--------------------------|----------------------------|
| `PUT /clients/{id}` (client_edit.php → `func_client_lock`) | client's control-panel user | client's control-panel user |
| `PUT /resellers/{id}` (reseller_edit.php inline) | acting key's `sys_userid` (legacy session user) | reseller's control-panel user |

The service takes the lock-time and unlock-time user id from the caller; `ClientService` passes
`IspContext::sysUserId()` for reseller locks (detected by the `ClientReseller` model class the controller
binds) and the resolved control-panel user otherwise. Datalog `user` column stays the acting key's username
(existing `DatalogService` behavior, legacy session username).

**Rationale**: FR-009 and exact legacy parity, including the reseller asymmetry.

## R4 — Change detection, atomicity, concurrency

**Decision**: at the start of `updateClient()` (already inside `DB::transaction` in both controllers) re-read the
client row with `lockForUpdate()` for `locked`, `canceled`; after `$client->fill()`, compare those stored raw
values with the filled raw attributes (`YesNoBoolean` stores `'y'`/`'n'`). Run lock/unlock after
`$client->save()` and the sys_user/sys_group sync (legacy `onAfterUpdate` order), cancel right after lock. A
second concurrent request blocks on the row lock and then sees the already changed flag, so no double
snapshot (spec edge case). All writes share `IspContext::sessionId()` (FR-007).

**Rationale**: legacy compares `dataRecord` with `oldDataRecord`; the bound model may be stale when two requests
race, so the comparison reads under the row lock. sqlite ignores `lockForUpdate()`, which is harmless in tests.

**Alternatives considered**: compare with the bound model's original (rejected: race → double snapshot marks
everything inactive and unlock would leave all services disabled).

## R5 — Cancel and cancel on create

**Decision**: `setLoginActive(clientId, active)` runs `UPDATE sys_user SET active = 0|1 WHERE client_id = ?`
(legacy writes the strings `'0'`/`'1'`; the column is numeric). On create, `createSysUser()` writes
`active => canceled ? 0 : 1` using the filled raw `canceled` value; resellers use the same path
(`createClient(..., asReseller: true)`). `locked: true` on create is stored only.

**Rationale**: FR-004/FR-005; owner deviation 1 (cancel on create).

## R6 — FR-013 locked-client write guard

**Decision**: new `App\Services\LockedClientGuard::check(BaseModel $model, array $original, bool $isCreate)`
called from `BaseModel::save()`:

- skip for admin scopes (`AuthScope::isAdmin`), models without sys fields, and tables not in the lock list;
- owner client: `sys_group.client_id` for the record's `sys_groupid` (original row on update, forced/assigned
  value on create, i.e. after `applySysFieldDefaults()`), joined to `client.locked`; one query;
- create: deny when the owner client is locked;
- update: deny when the owner client is locked and a lock column changes from its disabled to its enabled
  value (`active`/`postfix`: original `!= 'y'` → new `'y'`; `disablesmtp`: original `'y'` → new `!= 'y'`);
  other edits of a locked client's records stay allowed;
- deny by throwing `AuthorizationException('The account is locked; its services cannot be enabled or added.')`
  before any DB write → 403 problem+json, no datalog (same mechanism as the spec 011 write gate);
- placement: on create after the spec 012 limit checks, on update right after the write gate.

**Rationale**: owner decision 24 — a suspended customer must not undo the suspension with its own or its
reseller's key; admin keys stay unrestricted for support.

**Alternatives considered**: middleware on routes (rejected: payload-dependent, and child resources/services
would need per-route wiring); blocking all writes of a locked client (rejected: broader than decided).

## R7 — Write paths that bypass `BaseModel::save()`

**Decision**: model saves are covered by the guard in `BaseModel::save()`. The audit found one create path for a
lock-list table that bypasses it: `WebDomainService::create()` inserts `web_domain` with
`DB::table('web_domain')->insertGetId()` (line 154) and datalogs directly (lines 213/223), the same reason it
calls `ClientLimitService` explicitly. It must call `LockedClientGuard::check()` explicitly before the insert
(owner group resolved the same way as for limits). Updates of `web_domain` go through `$domain->save()` and are
covered. Other direct `datalog->log/updateRecord` callers touch non-lock tables (`web_database_user`, `dns_*`,
`spamfilter_*`, resync) or run after a guarded save (`WebChildDomainController` parent update). Lock/unlock side effects themselves call `DatalogService`
directly and are intentionally not guarded (they run with the acting admin/reseller context and implement the
lock).

**Rationale**: FR-013 must not be bypassable through a service path.

## R8 — Testing strategy

**Decision**:

- Schemas: `ClientApiTestCase` (ClientSchema: `client.locked/canceled/tmp_data`, `sys_user.active`) composed with
  `SitesSchema` (cron, ftp_user, shell_user, webdav_user, web_database, web_domain, web_folder, web_folder_user)
  and `MailCompletionSchema` (mail_domain, mail_user with `postfix`/`disablesmtp`, mail_forwarding, mail_get);
  guard tests use `TenantFixtures` + `TenantSchema`, which gains `locked`, `canceled`, `tmp_data` via
  `ensureColumns` when missing.
- Assertions: exact datalog rows (`dbtable`, `dbidx`, `action`, unserialized `data['new']` column values and
  `sys_userid`), exact `tmp_data` string equal to `serialize()` of the expected legacy-shaped array, one
  `session_id` per request, zero datalog rows for denied writes and unchanged flags.
- Legacy interop: a hand-built legacy snapshot (`serialize` of the legacy array shape, including the empty
  table arrays and string owner ids) unlocked through the API.
- Suite runs in Docker `php:8.3-cli` as the local user; baseline 761 tests must stay green.
