# Data Model: SSH Authentication Mode for Scoped Keys

No migrations. One new read-only field and two refusals.

## AccountSitesCapabilities.shell.authentication

| Field | Type | Source |
|---|---|---|
| `authentication` | string enum `password_or_key` \| `password` \| `key` | `[sites] ssh_authentication` of the `sys_ini` blob |

Mapping: `password` → `password`, `key` → `key`, anything else (empty, missing, unknown) → `password_or_key`
(legacy's `else` branch). The field is required in the `shell` block and is reported even when
`available` is false, because it describes the installation, not the plan.

## Refusals

For client and reseller keys only; administrator keys are never refused.

| Endpoint | Condition | Result |
|---|---|---|
| `POST /sites/shell-users` | mode `key` and `password` present and non-empty | 422, `errors.password`, `error_types.password` = `feature-not-allowed` |
| `POST /sites/shell-users` | mode `password` and `ssh_rsa` present and non-empty | 422, `errors.ssh_rsa`, same type |
| `PUT /sites/shell-users/{id}` | same, unless the submitted value equals the stored one | 422, same fields |

Accepted in every mode: the allowed credential; the not-allowed field as `null` or `""`; on update, the not-allowed
field re-sent unchanged. Refusals happen during validation, so nothing is written and no `sys_datalog` row appears.

## Stored result

| Mode | Administrator key sends both | Scoped key sends the allowed one |
|---|---|---|
| `password_or_key` | both stored | stored |
| `password` | password stored, `ssh_rsa` cleared | password stored; an existing `ssh_rsa` is left untouched |
| `key` | key stored, `password` cleared | key stored; an existing `password` is left untouched |

Clearing is the administrator path only. A client or reseller key is refused when it tries to *change* the
not-allowed credential, and an accepted request from such a key never destroys one that is already stored — otherwise
a customer who only changes a quota would silently lose the credential, which is the defect this feature removes.

## Not changed

The setting itself stays administrator-only system configuration; this feature never writes it. `chroot`, `shell`
and every other shell-user field keep their current rules, and existing accounts are never rewritten.
