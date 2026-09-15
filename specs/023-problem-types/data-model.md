# Data Model: Machine-Readable Problem Types (023)

No tables. Problem body members (RFC 9457 extension members).

## Type URIs

Base: `https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#`

| Name | Status | Extension members |
|------|--------|-------------------|
| `account-locked` | 403 | — |
| `limit-reached` | 403 | `limit` |
| `quota-exceeded` | 403 | `limit` |
| `feature-not-allowed` | 403 | `feature` |
| `validation-failed` | 422 | `errors`, optional `error_types` |
| `server-not-assigned` | (field type only) | — |

## ProblemLimit (`api/components/schemas/ProblemLimit.yaml`)

| Field | Type | Notes |
|-------|------|-------|
| `name` | string | client limit column, e.g. `limit_web_domain`, `limit_mailquota` |
| `scope` | enum `client`, `reseller` | whose cap applied |
| `max` | integer | cap (count, or MB for quotas) |
| `used` | integer | counted rows, or MB already allocated |
| `unit` | string `MB` | quotas only |
| `requested` | integer \| null | quotas only; MB requested, null when unlimited was requested |

## ForbiddenProblem (`api/components/schemas/ForbiddenProblem.yaml`)

`allOf: [Problem]` + optional `limit` (`ProblemLimit`) and `feature` (string, client limit column).

## ValidationProblem additions

`error_types`: object, `additionalProperties: string` (type URI) — keys are a subset of `errors` keys.

## Field typing (FR-006)

| Source | Field(s) | Type |
|--------|----------|------|
| `ResolvesAssignedServer` — not available, no server of the service, no secondary DNS server | `server_id` | `server-not-assigned` |
| `WebPermissionService` plan flags (`ssl`, `ssl_letsencrypt`, `cgi`, `ssi`, `perl`, `ruby`, `python`), `errordocs`, `directive_snippets_id`, plan wildcard `subdomain`, forced `suexec` | same | `feature-not-allowed` |
| `WebPermissionService` PHP mode not allowed, PHP version not available | `php`, `server_php_id` | `feature-not-allowed` |
| `WebPermissionService` administrator-only options and SSL tab fields | each field | `feature-not-allowed` |
| identity fields, wildcard on child websites, immutable server, fetchmail server other than the destination mailbox's server, PHP version required, no PHP version on the server | — | untyped |
