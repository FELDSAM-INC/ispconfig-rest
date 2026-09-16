# Research: Password Policy for Non-Mail Users

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — Where legacy attaches the validator

`validate_password::password_check` is attached through the tform definitions:

| Form | Field |
|---|---|
| `sites/form/database_user.tform.php:109` | `database_password` |
| `sites/form/ftp_user.tform.php:117` | `password` |
| `sites/form/shell_user.tform.php:125` | `password` |
| `sites/form/webdav_user.tform.php:110` | `password` |
| `sites/form/web_folder_user.tform.php:104` | `password` |
| `sites/form/web_vhost_domain.tform.php:620` | `stats_password` |
| `client/form/client.tform.php:217` | `password` |
| `client/form/reseller.tform.php:217` | `password` |
| `admin/form/users.tform.php:175`, `admin/form/remote_user.tform.php:107` | control-panel and remoting users — not managed by this API |

Mailboxes (`mail/form/mail_user.tform.php`) are already covered by spec 028.

## R2 — The policy and its defaults

`auth::get_min_password_length()` returns `[misc] min_password_length` or 8; `auth::get_min_password_strength()`
returns `[misc] min_password_strength` or 0. `validate_password::password_check()` refuses when the length is below
the minimum or the computed strength is below the minimum, with `weak_password_txt` (length **and** strength named)
when a strength is required and `weak_password_length_txt` otherwise.

isp-test has 8 and 3. The values are installation-wide: they sit in `[misc]`, not in a module section.

## R3 — What the API does today

| Request | Current rule |
|---|---|
| `StoreClientRequest`, `UpdateClientRequest` | `min:8`, `max:200` — length only, strength ignored |
| `StoreFtpUserRequest` / `Update…` | `string`, `max:255` |
| `StoreShellUserRequest` / `Update…` | `string`, `max:255` |
| `StoreWebdavUserRequest` / `Update…` | `string`, `max:255` |
| `StoreWebFolderUserRequest` / `Update…` | `string`, `max:255` |
| `StoreWebDatabaseUserRequest` / `Update…` | `string`, `max:64` (`database_password`) |
| `WebDomainRequest` (shared by store and update) | `stats_password`: `string`, `max:255` |

`StoreClientResellerRequest` and `UpdateClientResellerRequest` define no password rule of their own — resellers
travel the client requests' rule, so one change covers both.

Spec 028 ported the computation as `App\Support\MailPasswordPolicy` (strength table, message building, the mail-only
ASCII branch) and applies it through `App\Rules\MailboxPassword`, which reads the policy from
`AccountMailService::passwordPolicy()`.

## R4 — Where the shared computation should live

**Options**: (a) reuse `MailPasswordPolicy` as-is from the new rule; (b) move the neutral parts to a support class
and keep a mail wrapper.

**Findings**: the class mixes two things — the installation policy (length, strength) and the mail-only ASCII rule,
which short-circuits the other checks. Calling it from non-mail requests would either drag the ASCII branch along or
require passing `ascii_only: false` from every call site.

**Decision** (owner-delegated 2026-09-16): (b). `App\Support\PasswordPolicy` carries the strength table, the message
building and `violation()` for length+strength; `MailPasswordPolicy` keeps the ASCII branch and delegates the rest,
so every spec 028 expectation stays literally unchanged. A new `App\Services\PasswordPolicyService` reads the
installation values once per request, and `App\Rules\InstallationPassword` is attached to the fields of R1.

## R5 — Readable policy values (coordinator requirement)

`mail.password_policy` in `/me/capabilities` (spec 025) already exposes `min_length`, `min_strength` and
`ascii_only`. The first two are installation-wide, the third is mail-only, so a consumer of FTP or database
credentials cannot simply reuse the mail block without inheriting a rule that does not apply to it.

**Decision**: add `password_policy` (`min_length`, `min_strength`) to the `sites` block of spec 035. The mail block
keeps its three fields unchanged for backwards compatibility, and both read the same installation values.

## R6 — Test fixtures that become invalid

`tests/fixtures/sys_ini_config.ini` (used by `SitesApiTestCase`) sets `min_password_length=8` and
`min_password_strength=3`, so every sites-module test posting a throwaway password (`x`, `DavSecret1`,
`S3cretPass!`, `brand-new-pass1`, `s3cr3tP@ssw0rd`) would start failing once the rule lands. Those fixtures must move
to compliant values as part of the test phase, and `ClientApiTest`'s `short password` case must assert the policy
message instead of the old `min:8` message.

This is expected churn, not collateral damage: it is the same signal integrations will see.

## R7 — Scope boundary

Not enforced here: API keys (not an ISPConfig credential), control-panel and remoting users (not managed by this
API), mailbox passwords (spec 028), and `force_password_change_days` (a separate legacy feature). Storage formats
are untouched — the policy is judged on the plaintext before hashing.
