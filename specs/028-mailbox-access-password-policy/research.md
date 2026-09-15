# Research: Mailbox Access Switches and Password Policy

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-15.

## R1 — Where legacy validates mailbox passwords

**Legacy**: `mail/form/mail_user.tform.php` 129–145: `password` (formtype `PASSWORD`, CRYPTMAIL) has one validator,
`CUSTOM validate_password::password_check`. tform validators run for every user type, admins included.
`tform_base.inc.php` 1367 skips validators of an empty PASSWORD field (unchanged password on update);
`mail_user_edit.php` onSubmit reports `error_no_pwd` for an empty password on insert. When
`$global_config['mail']['mail_password_onlyascii'] == 'y'` (tform 350–354) the validator list is replaced by
`ISASCII` (`/[^\x20-\x7F]/` → `email_error_isascii`) — length and strength are then not checked. The mailbox
self-service form (`mailuser/form/mail_user_password.tform.php`) uses the same validator.

**Decision**: one validation rule (`App\Rules\MailboxPassword`) on `password` of `StoreMailUserRequest`,
`UpdateMailUserRequest` (only when non-empty; empty is removed before validation as today) and
`UpdateMailUserPasswordRequest`, for every key type. It replaces `min:5`; `required`/`max:255` stay.

## R2 — Policy values and messages

**Legacy**: `auth.inc.php` 211–228: `min_password_length` = [misc] value when set, else 8; `min_password_strength` =
[misc] value when set, else 0. `validate_password::password_check()`:

```
if value == ''            → valid
message = min_strength > 0 ? weak_password_txt({chars}, {strength name}) : weak_password_length_txt({chars})
strlen(value) < min_length → message
strength(value) < min_strength → message
```

`lib/lang/en.lng` 168–174: `strength_1..5` = Weak, Fair, Good, Strong, Very Strong; `weak_password_txt` = `The chosen
password does not match the security guidelines. It has to be at least {chars} chars in length and have a strength of
"{strength}".`; `weak_password_length_txt` = `… It has to be at least {chars} chars in length.`
`en_mail_user.lng` 70: `email_error_isascii` = `Please do not use special unicode characters for your password. This
could lead to problems with your mail client.` Empty settings compare as "no minimum" in PHP 8.

**Decision**: `AccountMailService::passwordPolicy()` (feature 025) supplies `{min_length, min_strength, ascii_only}`
with these defaults; `App\Support\MailPasswordPolicy::violation()` returns the legacy message or null. English texts
only (the API's language).

## R3 — Strength algorithm

**Legacy** `validate_password::_get_password_strength()`: length < 5 → 1; lowercase (class only), uppercase, digits
and specials (`[`~!@#$%^&*()_+|\=\-\[\]}{';:\/?.>,<" ]`) each add one class and — except lowercase — one point;
`points == 0 || classes < 3` → 5–6: 1, 7–8: 2, else 3; `points == 1` → 2/3/4 (unreachable); `points == 2` → 5–8: 3,
9–10: 4, else 5; `points == 3` → 5–6: 3, 7–8: 4, else 5; `points >= 4` → 4/5 (unreachable). `strlen` counts bytes.

**Decision**: exact port `MailPasswordPolicy::strength()` with the same regular expressions, covered by a unit table.

## R4 — Access switches

**Legacy**: tform 321–344 CHECKBOX `disablesmtp`, `disabledeliver`, `disableimap`, `disablepop3` (default `n`), on the
always-shown mailbox tab and template (`mail_user_mailbox_edit.htm` 104–130, outside `is_admin`). tform saves them with
the datalog insert/update; `mail_user_edit.php` then runs a direct `UPDATE`: onAfterInsert 363–371 sets `disablesieve` =
imap, `disablelda` = `disablelmtp` = deliver; onAfterUpdate 384–392 additionally `disablesieve-filter` = imap. Dovecot's
SQL passdb reads `disable%Ls` (e.g. `disableimap`, `disablepop3`, `disablesieve`) and the LDA/LMTP columns directly from
the database.

**Decision** (owner-delegated): expose the four switches on the `MailUser` resource (booleans, YesNoBoolean); set the
companion columns on the model before the single datalog'd save (`MailUserService::applyAccessDerivations()`): always on
create, on update only when the source switch is in the request.

## R5 — Lock guard

**Facts** (spec 019): `ClientLockService::LOCK_ENTRIES` has `['mail_user', 'mailuser_id', 'disablesmtp', true]`
(reversed: `y` = disabled) and `postfix`. `BaseModel::save()` runs `LockedClientGuard::check()` on updates for non-admin
scopes; until now `disablesmtp` was not writable through the API, so the path was never reachable.

**Decision**: no guard change — making `disablesmtp` fillable routes it through the existing check; tests cover `y → n`
(403 `account-locked`), `n → y` (200), other switches (200) and admin (200).

## R6 — Other password fields (follow-up, not changed)

Legacy forms using `validate_password`: `admin/form/remote_user`, `admin/form/users`, `client/form/client`,
`client/form/reseller`, `mail/form/mail_mailinglist`, `mail/form/mail_user`, `mail/form/xmpp_user`,
`mailuser/form/mail_user_password`, `sites/form/database_user`, `sites/form/ftp_user`, `sites/form/shell_user`,
`sites/form/web_folder_user`, `sites/form/web_vhost_domain`, `sites/form/webdav_user`, `tools/form/user_settings`.

API endpoints with passwords today: `POST/PUT /clients` and resellers (`min:8`), database users, FTP users, shell users,
WebDAV users and web folder users (`max:255` only). The API has no mailing-list or XMPP endpoints. Applying the policy
there changes provisioning behavior of the WHMCS module (generated client passwords) and many existing tests, so it is
not "trivial" — recorded as an open point; `MailboxPassword`/`MailPasswordPolicy` are reusable.
