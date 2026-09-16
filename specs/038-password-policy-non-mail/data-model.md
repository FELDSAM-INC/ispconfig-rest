# Data Model: Password Policy for Non-Mail Users

No migrations. One new read-only capability field and one validation rule.

## Installation password policy (read)

| Field | Type | Source | Default |
|---|---|---|---|
| `min_length` | integer | `[misc] min_password_length` | 8 (`auth::get_min_password_length()`) |
| `min_strength` | integer 0–5 | `[misc] min_password_strength` | 0 (no strength requirement) |

Read per request by `PasswordPolicyService`; never written by this feature.

## AccountSitesCapabilities.password_policy (`/me/capabilities` → `sites`)

| Field | Type | Meaning |
|---|---|---|
| `min_length` | integer | minimum number of characters |
| `min_strength` | integer 0–5 | minimum strength on ISPConfig's scale; 0 = not required |

No `ascii_only`: that option (`[mail] mail_password_onlyascii`) applies to mailboxes only and stays in the `mail`
block, which keeps its three fields unchanged.

## Strength (unchanged computation)

Ported in spec 028 and now shared: one point each for an uppercase letter, a digit and a symbol; a password using
fewer than three character classes, or none of those points, is capped by length alone. The result is 1–5. A
password shorter than 5 characters is always 1.

## Enforced fields

| Endpoint | Field | Applies when |
|---|---|---|
| `POST /clients`, `PUT /clients/{id}` | `password` | present and non-empty (an update drops a blank one first) |
| `POST /clients/resellers`, `PUT /clients/resellers/{id}` | `password` | same rule, inherited from the client requests |
| `POST`/`PUT /sites/ftp-users` | `password` | present and non-empty |
| `POST`/`PUT /sites/shell-users` | `password` | present and non-empty |
| `POST`/`PUT /sites/webdav-users` | `password` | present and non-empty |
| `POST`/`PUT /sites/web-folder-users` | `password` | present and non-empty |
| `POST`/`PUT /sites/database-users` | `database_password` | present and non-empty |
| `POST`/`PUT /sites/web-domains` | `stats_password` | present and non-empty |

Every key type is judged, as legacy validates the form regardless of the logged-in user.

## Refusal

422 `validation-failed` with the field in `errors` and the legacy message:

- with a strength requirement: *"The chosen password does not match the security guidelines. It has to be at least
  {chars} chars in length and have a strength of "{strength}"."* where `{strength}` is `Weak`, `Fair`, `Good`,
  `Strong` or `Very Strong`;
- without one: *"The chosen password does not match the security guidelines. It has to be at least {chars} chars in
  length."*

Nothing is written, and on an update the stored hash is unchanged.

## Not covered

Mailbox passwords (spec 028, including the ASCII option), API keys, control-panel and remoting users, and
`force_password_change_days`.
