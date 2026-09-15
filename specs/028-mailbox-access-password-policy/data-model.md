# Data Model: Mailbox Access Switches and Password Policy

No migrations. Existing `mail_user` columns.

## MailUser (`api/components/schemas/MailUser.yaml`) — additions

| Field | Type | Column | Companion columns (same datalog entry) |
|---|---|---|---|
| `disableimap` | boolean (default false) | `disableimap` y/n | `disablesieve`, `disablesieve-filter` = same value |
| `disablepop3` | boolean (default false) | `disablepop3` y/n | — |
| `disablesmtp` | boolean (default false) | `disablesmtp` y/n | — (lock-managed, reversed) |
| `disabledeliver` | boolean (default false) | `disabledeliver` y/n | `disablelda`, `disablelmtp` = same value |

Companion columns are written on create, and on update when the source switch is sent.

## Password policy

| Input | Source | Default |
|---|---|---|
| `min_length` | `sys_ini` [misc] `min_password_length` | 8 when missing; empty → 0 |
| `min_strength` | `sys_ini` [misc] `min_password_strength` | 0 when missing or empty |
| `ascii_only` | `sys_ini` [mail] `mail_password_onlyascii = y` | false |

| Mode | Check | Message |
|---|---|---|
| `ascii_only` | any byte outside `\x20-\x7F` | `Please do not use special unicode characters for your password. This could lead to problems with your mail client.` |
| otherwise, `min_strength > 0` | `strlen < min_length` or strength < `min_strength` | `The chosen password does not match the security guidelines. It has to be at least {min_length} chars in length and have a strength of "{Weak\|Fair\|Good\|Strong\|Very Strong}".` |
| otherwise, `min_strength = 0` | `strlen < min_length` | `The chosen password does not match the security guidelines. It has to be at least {min_length} chars in length.` |

Applied to `password` on `POST /mail/users` (required), `PUT /mail/users/{id}` (non-empty only),
`PUT /mail/users/{id}/password` (required); `max:255` unchanged; `min:5` removed.

## Strength (legacy table)

| Classes / points | 5–6 chars | 7–8 | 9–10 | 11+ |
|---|---|---|---|---|
| fewer than 3 classes or 0 points | 1 | 2 | 3 | 3 |
| 2 points, 3+ classes (lower + upper + digit) | 3 | 3 | 4 | 5 |
| 3 points (upper, digit, special) | 3 | 4 | 5 | 5 |

Fewer than 5 characters → 1.

## Refusals

| Case | Status |
|---|---|
| password violates the policy | 422 `errors.password` (message above) |
| locked client, non-admin key, `disablesmtp` `y → n` | 403 `account-locked` |
| switch not boolean / `y` / `n` | 422 |
