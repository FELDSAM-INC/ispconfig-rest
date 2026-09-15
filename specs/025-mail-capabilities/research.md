# Research: Mail Capabilities and Email Program Settings for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-15.

## R1 — Target account

**Decision**: reuse `AccountCapabilitiesService::resolveTarget()` (→ `UsageService::resolveTargetClient()`, spec 021
R1) and `ReadsAccountQuery` for `GET /me/mail-settings`: unknown parameters 400, `client_id` positive integer (422),
admin keys must name the client (422/404), client keys own client only (404 otherwise), reseller keys own client or
one of their clients (404 otherwise).

**Rationale**: the module already calls `/me/capabilities` and `/usage/summary` with these rules.

## R2 — Mailbox tab switches

**Legacy**: `mail/form/mail_user.tform.php`

- 350–354: `if ($global_config['mail']['mail_password_onlyascii'] == 'y')` the password validators become `ISASCII`.
- 356: the Autoresponder tab (`autoresponder`, `autoresponder_subject`, `autoresponder_text`,
  `autoresponder_start_date`, `autoresponder_end_date`) exists only when `mailbox_show_autoresponder_tab === 'y'`.
- 427: the Mail Filter tab (`move_junk`, `purge_trash_days`, `purge_junk_days`, plus the `filter_records` plugin listing
  `mail_user_filter` rows) exists only when `mailbox_show_mail_filter_tab === 'y'`.
- 474: the Custom Rules tab (`custom_mailfilter`) exists only when `$_SESSION['s']['user']['typ'] == 'admin'` and
  `mailbox_show_custom_rules_tab === 'y'`.

tform only processes the fields of the submitted tab, so a client can never write `custom_mailfilter`, and cannot
write tab fields of a tab that does not exist. `mail_user_filter_edit.php` itself does not re-check the switch (the
only entry point is the list inside the tab).

`admin/form/system_config.tform.php` defaults the three switches to `y`; `/system/config` (`SystemConfigService`)
presents missing keys with those defaults.

**Decision** (owner-delegated):

- `mail.autoresponder` = `mailbox_show_autoresponder_tab` is `y` or missing; `mail.mail_filters` =
  `mailbox_show_mail_filter_tab` is `y` or missing; `mail.custom_rules` = `false` (the described accounts are never
  administrators).
- Enforcement for non-admin keys: `PUT`/`DELETE /mail/users/{id}/autoresponder` and `POST`/`PUT`/`DELETE
  /mail/users/{id}/filters…` behind a route gate `mail.tab:<setting>` (403 `feature-not-allowed`, `feature` = setting
  name, before route-model binding like `scope.limit`); `PUT /mail/users/{id}/spamfilter` refuses only when the body
  carries `move_junk`, `purge_trash_days` or `purge_junk_days` (feature 026 adds the always-shown spam filter level to
  this resource); `custom_mailfilter` from a non-admin key → 422 on the field typed `feature-not-allowed`.
- Reads stay allowed (legacy shows mailbox data in lists and the API has no tab concept for reads).

**Alternatives considered**: silently ignore refused fields (legacy form behavior) — rejected, an API caller would
believe the change was stored; treat a missing key as disabled (legacy `=== 'y'`) — rejected for consistency with
`/system/config`, which reports the form default.

## R3 — Spam filter level availability

**Legacy**: `mail_user_edit.php` 99–110 (mailbox tab, always shown) builds the policy select from `SELECT id,
policy_name FROM spamfilter_policy WHERE getAuthSQL('r')`, with option 0 "inherit"; `mail_domain_edit.php` does the
same for the domain. No client limit gates the choice (`limit_spamfilter_policy` limits creating policies in
`spamfilter_policy_edit.php`). isp-test: policies 1–7, all `sys_perm_other = 'r'`.

**Decision**: `mail.spamfilter_policy` = at least one `spamfilter_policy` row passes the `'r'` predicate of the
client's own control-panel scope (`AuthScope::forClient()`; without an identity only the world clause).

## R4 — DKIM availability

**Legacy**: `mail/form/mail_domain.tform.php` 105–141 — the DKIM fields are part of the domain form for every user
type (no limit). `server/plugins-available/mail_plugin_dkim.inc.php::check_system()` refuses to write keys unless the
server's [mail] `dkim_path` is set, not empty and not `/`. isp-test server 1: `dkim_path=/var/lib/amavis/dkim`,
`dkim_strength=2048`.

**Decision**: `mail.dkim` = any mail server of the account (R6) has a usable `dkim_path`.

## R5 — Password policy

**Legacy**: `lib/classes/auth.inc.php` 211–228 — `get_min_password_length()` returns `misc.min_password_length` when
set, else 8; `get_min_password_strength()` returns `misc.min_password_strength` when set, else 0;
`validate_password.inc.php` checks length then strength (1 Weak … 5 Very Strong). `mail_password_onlyascii` is read
from [mail] (R2). isp-test: `min_password_length=8`, `min_password_strength=3`, `mail_password_onlyascii` unset.

**Decision**: `password_policy = {min_length: int(misc.min_password_length) or 8 when missing, min_strength:
int(misc.min_password_strength) or 0, ascii_only: mail.mail_password_onlyascii === 'y'}` read from the raw blob (the
presented `/system/config` default `5` is the form default, not the runtime default). Enforcement is feature 028.

## R6 — Mail servers of the account

**Legacy**: new mail domains use the client's `mail_servers` list (spec 016); existing domains stay on their server.

**Decision**: `AccountMailService::accountMailServers($clientId)` — valid assigned mail servers
(`ServerAssignmentService::assignedServerIds(…, 'mail')`: `mail_server = 1`, `mirror_server_id = 0`, list order), then
non-mirror mail servers hosting the client's mail domains (`mail_domain` joined on `sys_group.client_id`) by id,
without duplicates. Same structure as `accountWebServers()` (spec 021 R5).

## R7 — Host names and ports

**Legacy**: ISPConfig stores no client-facing ports; its installer configures Postfix `submission` (587, STARTTLS)
and `smtps` (465) and Dovecot IMAP/POP3 with TLS (993/995 implicit, 143/110 STARTTLS). The host a customer enters is
the mail server's `server_name` (the name `webmailer.php` uses).

**Decision** (owner-delegated): per server `host = server.server_name`, `imap = {port: 993, security: ssl}`, `pop3 =
{port: 995, security: ssl}`, `smtp = [{port: 587, security: starttls}, {port: 465, security: ssl}]` — implicit TLS
for mailbox access (RFC 8314), submission first. Shape from the module contract (`imap`/`pop3` objects, `smtp` list).
Port 25 is not listed (server-to-server).

**Alternatives considered**: all four IMAP/POP3 variants as lists — rejected, the module shows one recommended
setting and asked for objects.

## R8 — Webmail URL

**Legacy**: `mail/webmailer.php` 55–76 — `webmail_url` non-empty → `str_replace('[SERVERNAME]', server_name, …)`;
otherwise `http(s)://server_name/webmail`, with an nginx branch `http://server_name:8081/webmail` that never runs
because `$web_config` is never loaded. `mailboxlist_webmail_link` decides whether the mailbox list shows the link.
isp-test: `webmail_url=https://[SERVERNAME]:8081/webmail`, `mailboxlist_webmail_link=y`.

**Decision**: `webmail_url` per server as legacy with `https` fallback; nginx (`server.config` [web] `server_type =
nginx`) → `https://<host>:8081/webmail`. `webmail_link` = `mailboxlist_webmail_link === 'y'` (missing → form default
`n`).

## R9 — Usage counts

**Legacy/spec 012**: `ClientLimitService` already counts `limit_mailcatchall` / `limit_mailaliasdomain`
(`mail_forwarding` by type, `'u'` predicate), `limit_mailfilter` (`mail_user_filter`), `limit_fetchmail` (`mail_get`)
for create checks.

**Decision**: add `mail_catchalls`, `mail_alias_domains`, `mail_filters`, `fetchmail_accounts` to
`ClientLimitService::USAGE_COUNT_COLUMNS` and `countSpecForColumn()`; `/usage/summary` iterates the constant, so the
values use the same predicate as the create checks. Capabilities do not duplicate counts.

## R10 — sys_ini access without the singleton

**Facts**: `SystemConfigService::getSection()` throws 500 when `sys_ini` row 1 is missing (test databases of other
features have no `sys_ini`).

**Decision**: `SystemConfigService::rawSection(string $section): array` — the parsed section, `[]` when the table or
row is absent. The tab gates, capabilities and settings use it, so existing mail tests keep passing and a broken
installation still answers reads with defaults.

## R11 — Response shapes and problem documentation

**Decision**: new schemas `AccountMailSettings.yaml`, `AccountMailServer.yaml`, `MailConnection.yaml`,
`MailPasswordPolicy.yaml` (shared by capabilities and settings); `AccountCapabilities.yaml` gains required `mail`;
`UsageSummary.yaml` counts gain four required keys. `docs/problems.md` `feature-not-allowed`: `feature` may also name a
system setting (`mailbox_show_autoresponder_tab`, `mailbox_show_mail_filter_tab`); field type also marks
`custom_mailfilter`.
