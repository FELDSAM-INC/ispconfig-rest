# Data Model: API Key Management over HTTP

**Feature**: 014-api-key-management | **Date**: 2026-09-14

## Stored entity: API Key (`api_keys`, API-owned — no migration)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | exposed as `id` |
| `name` | varchar(255) | label; required, 1–255 chars, duplicates allowed |
| `key_hash` | char(64) unique | SHA-256 of the plaintext; never exposed (`$hidden`) |
| `sys_userid` | int unsigned | ISPConfig identity the key acts as; immutable after creation |
| `sys_groupid` | int unsigned | ISPConfig group the key acts as; immutable after creation |
| `active` | boolean | `false` = revoked |
| `last_used_at` | timestamp null | set by `ApiKeyAuth` on every authenticated request |
| `created_at` / `updated_at` | timestamps | `created_at` exposed |

Model: `app/Models/ApiKey.php` (plain Eloquent, not `BaseModel` — API-owned table exemption).
New helper: `ApiKey::deactivateForClientIdentities(array $userIds, int $groupId): int`.

### Derived fields (read side, never stored)

| Field | Derivation (batched per request) |
|-------|----------------------------------|
| `scope` | `sys_userid = 1` or `sys_user.typ = 'admin'` → `admin`; no `sys_user` row → `unbound`; `client.limit_client != 0` for `sys_user.client_id` → `reseller`; else `client` |
| `client_id` | `sys_user.client_id` when > 0 and scope is `client`/`reseller`; otherwise `null` |

Source tables read: `sys_user` (`userid`, `typ`, `client_id`), `client` (`client_id`, `limit_client`),
`sys_group` (`groupid`, `client_id` — identity resolution only).

### Identity resolution for `client_id` (create)

`sys_group.groupid WHERE client_id = :client_id` → `sys_user.userid WHERE default_group = groupid` →
store `sys_userid = userid`, `sys_groupid = groupid`. Missing group or user → 422 `errors.client_id`.
Without `client_id`: `sys_userid = 1`, `sys_groupid = 1` (admin).

### Validation rules

| Field | Create | Update |
|-------|--------|--------|
| `name` | required, string, 1–255 | sometimes, filled, string, max 255 |
| `client_id` | sometimes, integer ≥ 1, must resolve (422) | sometimes, nullable integer; must equal current binding (422) |
| `active` | not accepted (new keys are active) | sometimes, boolean |
| `key`, `key_hash`, `sys_userid`, `sys_groupid`, `id`, `scope` | prohibited (422) | prohibited (422) |

### State transitions

```text
            create (201, plaintext shown once)
                     │
                     ▼
   ┌──────────── active ────────────┐
   │  PUT active:false (200)        │ DELETE (204)
   │  api:key:revoke                │
   │  DELETE /clients/{id} cascade  │
   ▼                                ▼
inactive ── PUT active:true ──► active     deleted (row removed)
   │
   └── DELETE (204) ──► deleted
```

Guards: a request cannot move its own key to `inactive` or `deleted` (409). Authentication accepts
only `active` rows; `unbound` keys (bound `sys_user` gone) fail closed regardless of `active`.

## Representations

### ApiKey (list items, show, update response)

```json
{
  "id": 17,
  "name": "whmcs service 17",
  "scope": "client",
  "client_id": 42,
  "active": true,
  "created_at": "2026-09-14T10:15:00Z",
  "last_used_at": null
}
```

### ApiKeyCreated (POST 201 only)

ApiKey fields plus `"key": "isp_<40 random chars>"`.

### ApiKeyIdentity (GET /me)

```json
{
  "key_id": 17,
  "name": "whmcs service 17",
  "scope": "client",
  "client_id": 42,
  "sys_userid": 57,
  "sys_groupid": 61
}
```

Development key: `key_id: null`, `name: "development key"`, `scope: "admin"`, `client_id: null`,
`sys_userid: 1`, `sys_groupid: 1`.

## Relationships

- API Key → ISPConfig `sys_user` / `sys_group` by `sys_userid` / `sys_groupid` (soft reference; the
  ISPConfig rows can disappear, giving `scope: unbound`).
- ISPConfig `client` → API Keys through `sys_user.client_id`; deleting a client via the API deactivates
  them (FR-010).
