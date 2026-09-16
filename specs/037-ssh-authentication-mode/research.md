# Research: SSH Authentication Mode for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — Where the setting lives

`admin/form/system_config.tform.php:256-260` defines `ssh_authentication` as a SELECT with the values
`''` (password **and** key), `password` and `key`, on the form whose default tab is `sites`
(`system_config.tform.php:43`). The saved blob on isp-test confirms it: the `[sites]` section (line 24) contains
`ssh_authentication=` at line 41, before `[domains]` at line 49; the `[misc]` section starts at line 53 and does not
contain the key.

## R2 — Legacy never applies it (dead code)

| Call site | Section read | Effect |
|---|---|---|
| `shell_user_edit.php:100` | `$system_config['sites']['ssh_authentication']` | the form template hides the field the installation does not accept |
| `shell_user_edit.php:131-137` | `$system_config['misc']['ssh_authentication']` | would clear `ssh_rsa` (password mode) or `password` (key mode) — but the key is not in `[misc]`, so both comparisons are always false |

So in 3.3.1p1 the interface *hides* a field while the save path *never* clears anything. A value posted directly
(API, remoting, a stale form) is stored in full.

This is the same class of finding as spec 030's `limit_dns_record` (enforced inline, not via the helper) and spec
033's `validate_dns` (no call site): behaviour the specs must judge deliberately rather than copy.

## R3 — What the API does today

`SitesConfigService::sshAuthenticationMode()` reads `globalConfig('misc')['ssh_authentication']`, mirroring the buggy
legacy line, and `ShellUserController::applySshAuthenticationMode()` clears the other credential on create and
update. On a real installation the mode is therefore always `''` and nothing is ever cleared.

`ShellUserApiTest::test_ssh_authentication_mode_clears_the_other_credential` passes only because
`SitesApiTestCase::setSshAuthenticationMode()` rewrites `^ssh_authentication=.*$` in a fixture blob that places the
key in `[misc]`. The green test proves nothing about a real server.

**Decision** (owner-delegated 2026-09-16): read `[sites]`. The alternative — keeping the `[misc]` read for parity —
would make the reported capability always `password_or_key` and leave the enforcement inert, i.e. ship a feature that
does nothing. The correction is documented as a deliberate deviation, and the existing test fixture moves to
`[sites]` so it exercises the real path.

## R4 — Where the mode is reported

Spec 035 added `sites.shell` to `GET /me/capabilities` with `available` and `chroot_options` — the SSH form's other
two questions. The mode belongs next to them: it describes what an SSH account of this account may carry. No new
endpoint is needed, and panels already read capabilities on the page that renders the form.

**Decision**: add `authentication` to `sites.shell`, with the spelled-out values `password_or_key`, `password`,
`key`.

## R5 — How the refusal is raised

Spec 033's `DnsSoaRequest` establishes the pattern for a typed field refusal: an `after()` closure that skips
administrator scopes, accepts a value equal to the stored one, tags the field with
`ProblemTypeCollector::tag($field, ProblemType::FEATURE_NOT_ALLOWED)` and adds a validation error. The 422 renderer
then emits `error_types.<field>`.

**Decision**: add such a closure to `StoreShellUserRequest` and `UpdateShellUserRequest`. It fires only when the
field is present **and** non-empty, so a `null`/`""` value stays accepted (there is nothing to discard), and on
update it compares with the stored row obtained from the route model — the same shape `DnsSoaRequest::currentZone()`
uses.

## R6 — Administrator behaviour

Refusing administrator keys would break existing automation that posts both credentials, and legacy's
administrator-facing form is exactly where the permissive behaviour comes from.

**Decision**: administrator keys keep `applySshAuthenticationMode()` (silent clearing), which — with the section
corrected — finally does what the ISPConfig form promises. The existing regression test keeps this path honest.

## R7 — Effect on consumers

- The WHMCS module (spec 006 R11) can stop showing both credential fields once this ships; its dependency note names
  this spec.
- Installations that never set the mode see no change at all.
- Installations that set it see their choice enforced for the first time: customer keys are refused, administrator
  keys have the other credential cleared. Existing accounts are untouched.
