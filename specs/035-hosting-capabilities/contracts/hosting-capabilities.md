# Contract: Hosting Capabilities for Scoped Keys

OpenAPI sources: `api/modules/me/capabilities.yaml`, `api/components/schemas/AccountCapabilities.yaml`,
`AccountSitesCapabilities.yaml`, `api/modules/usage/summary.yaml`, `api/components/schemas/UsageSummary.yaml`,
`api/modules/sites/cron-jobs.yaml`, `api/modules/sites/database-users.yaml`, `docs/problems.md`.

## GET /me/capabilities[?client_id=N] — extended

Unchanged target rules (client key: own account; reseller: own or a child; admin: `client_id` required). The response
gains one required block next to `web` and `mail`:

```json
{
  "client_id": 42,
  "account_type": "client",
  "locked": false,
  "canceled": false,
  "web": { "…": "unchanged" },
  "mail": { "…": "unchanged" },
  "sites": {
    "prefixes": {
      "database": "c42_",
      "database_user": "c42_",
      "ftp_user": "webhostingcz_",
      "shell_user": "webhostingcz_",
      "webdav_user": "webhostingcz_"
    },
    "databases": { "quota_limit_mb": 2048, "remote_access": true },
    "shell": { "available": true, "chroot_options": ["no", "jailkit"] },
    "cron": { "types": ["url", "chrooted"], "min_interval_minutes": 5 }
  }
}
```

- `quota_limit_mb` and `min_interval_minutes` are `null` when the plan does not constrain them.
- `chroot_options` is `[]` when the account may not use shell access.
- Prefix values are the exact strings the write endpoints prepend; `[DOMAINID]` remains unresolved.

Errors unchanged: 400 unknown parameter, 401, 404 foreign/unknown client, 422 admin without `client_id`.

## GET /usage/summary[?client_id=N] — one more count

```json
{
  "counts": {
    "databases": { "used": 2, "limit": 10 },
    "database_users": { "used": 2, "limit": 3 },
    "ftp_users": { "used": 1, "limit": null }
  }
}
```

`database_users` follows every other count: `used` counts the rows the key may read, `limit` is `null` when
unlimited. Required in the schema, so existing consumers see one additional key.

## POST /sites/cron-jobs, PUT /sites/cron-jobs/{id} — plan rules enforced

Client and reseller keys only; admin keys are unaffected.

### 403 — schedule too frequent

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#limit-reached",
  "title": "Forbidden",
  "status": 403,
  "detail": "The scheduled task runs more often than your plan allows.",
  "limit": { "name": "limit_cron_frequency", "scope": "client", "max": 60, "used": 5 }
}
```

`used` is the job's shortest interval in minutes, `max` the plan's minimum interval.

### 403 — task kind not allowed

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed",
  "title": "Forbidden",
  "status": 403,
  "detail": "Your plan only allows scheduled tasks that call a URL.",
  "feature": "limit_cron_type"
}
```

Raised when the account's `limit_cron_type` is `url` and the command is not an `http(s)://` URL. Nothing is written in
either case, and an update leaves the stored row unchanged.

Validation of the five schedule fields and the command (spec 013) runs first, so an invalid expression still returns
422 rather than a limit refusal.

## POST /sites/database-users — unchanged behaviour, now documented

The existing 403 `limit-reached` with `limit.name = limit_database_user` is documented in the contract and pinned by
tests; the count in `/usage/summary` is produced by the same definition.

## docs/problems.md

`limit-reached` gains `limit_cron_frequency` and `limit_database_user` in its examples of limit names;
`feature-not-allowed` gains `limit_cron_type` in its list of `feature` values. No new problem type is introduced.
