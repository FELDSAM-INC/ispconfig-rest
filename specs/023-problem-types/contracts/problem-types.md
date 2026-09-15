# Contract: Problem Types (023)

Shared components only; no path changes.

- `api/components/schemas/Problem.yaml` — `type` description lists the typed names and the base URI; default and
  example stay `about:blank`.
- `api/components/schemas/ProblemLimit.yaml` — new (data-model.md).
- `api/components/schemas/ForbiddenProblem.yaml` — new: `allOf` Problem + `limit`, `feature`.
- `api/components/schemas/ValidationProblem.yaml` — adds `error_types`.
- `api/components/schemas/_index.yaml` — registers `ProblemLimit`, `ForbiddenProblem`.
- `api/components/responses/Forbidden.yaml` — schema `ForbiddenProblem`; `examples` for `account-locked`,
  `limit-reached`, `quota-exceeded`, `feature-not-allowed`, `about:blank`.
- `api/components/responses/UnprocessableEntity.yaml` — examples with and without `error_types`, type
  `validation-failed`.
- `docs/problems.md` — sections `account-locked`, `limit-reached`, `quota-exceeded`, `feature-not-allowed`,
  `server-not-assigned`, `validation-failed`.

## Examples

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#limit-reached",
  "title": "Forbidden",
  "status": 403,
  "detail": "You have reached the maximum number of websites allowed for your account.",
  "limit": {"name": "limit_web_domain", "scope": "client", "max": 1, "used": 1}
}
```

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#quota-exceeded",
  "title": "Forbidden",
  "status": 403,
  "detail": "You have reached the maximum number of mailbox quota allowed for your account.",
  "limit": {"name": "limit_mailquota", "scope": "client", "unit": "MB", "max": 1000, "used": 900, "requested": 200}
}
```

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "errors": {"ssl_letsencrypt": ["The Let's Encrypt option is not included in the account's plan."]},
  "error_types": {"ssl_letsencrypt": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed"}
}
```
