# Contract: Password Policy for Non-Mail Users

OpenAPI sources: `api/components/schemas/PasswordPolicy.yaml` (shared shape),
`api/components/schemas/AccountSitesCapabilities.yaml`, `api/modules/me/capabilities.yaml`, and the endpoint files
listed below.

## GET /me/capabilities — one more field in `sites`

```json
{
  "sites": {
    "prefixes": { "…": "unchanged" },
    "databases": { "…": "unchanged" },
    "shell": { "…": "unchanged" },
    "cron": { "…": "unchanged" },
    "password_policy": { "min_length": 8, "min_strength": 3 }
  }
}
```

`min_strength` is 0 when the installation requires no particular strength. The mail block keeps
`mail.password_policy` with its three fields (`min_length`, `min_strength`, `ascii_only`) unchanged; the ASCII option
is mail-only.

## Endpoints that begin to enforce the policy

| Endpoint file | Operation | Field |
|---|---|---|
| `api/modules/client/clients.yaml` | POST, PUT | `password` |
| `api/modules/client/resellers.yaml` (`/resellers`, admin-only) | POST, PUT | `password` |
| `api/modules/sites/ftp-users.yaml` | POST, PUT | `password` |
| `api/modules/sites/shell-users.yaml` | POST, PUT | `password` |
| `api/modules/sites/webdav-users.yaml` | POST, PUT | `password` |
| `api/modules/sites/web-folder-users.yaml` | POST, PUT | `password` |
| `api/modules/sites/database-users.yaml` | POST, PUT | `database_password` |
| `api/modules/sites/web-domains.yaml` | POST, PUT | `stats_password` |

Each description gains one sentence: the value must satisfy the installation's password policy
(`[misc] min_password_length` and `min_password_strength`, readable per account as `sites.password_policy` of
`GET /me/capabilities`); a weaker value is refused with 422 on that field and nothing is written.

### 422 example

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#validation-failed",
  "title": "Unprocessable Entity",
  "status": 422,
  "detail": "The given data was invalid.",
  "errors": {
    "password": [
      "The chosen password does not match the security guidelines. It has to be at least 8 chars in length and have a strength of \"Good\"."
    ]
  }
}
```

No new problem type: this is ordinary field validation.

## Consumer mapping (WHMCS module and other provisioning integrations)

| Consumer need | Source |
|---|---|
| Generate a compliant password | `sites.password_policy.min_length` and `min_strength`; mix at least three character classes |
| Show the requirement next to an input | the same two values |
| Mailbox passwords | `mail.password_policy` (unchanged, includes `ascii_only`) |
