# Research: DKIM Key Generation for Mail Domains

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-15.

## R1 — Legacy key generation

**Legacy**: `mail/ajax_get_json.php` 42–113 (`type=create_dkim`, called by the "Generate DKIM" button of
`mail_domain_edit.htm`): mail server of the domain → `get_server_config($server_id, 'mail')['dkim_strength']`
(`intval`, 0/empty → 2048) → `openssl rand` seed file → `openssl genrsa <strength>` → `openssl rsa -pubout -outform PEM`;
returns `dkim_private`, `dkim_public`, `dkim_selector`, `dns_record` (public key without PEM armor and newlines). The
form then saves the domain normally. isp-test: OpenSSL 3 → private keys in PKCS#8 (`-----BEGIN PRIVATE KEY-----`),
`test.cz` key 1732 bytes.

A selector rotation branch (`substr($old_selector,0,53).time()` when the hosted zone already has the selector) is dead:
`$old_selector` is undefined and `$selector = $dkim_selector` overwrites the result in both branches.

**Decision**: `MailDomainDkimService::generate()` with `openssl_pkey_new(['private_key_bits' => $bits,
'private_key_type' => OPENSSL_KEYTYPE_RSA])`, `openssl_pkey_export()` (PKCS#8) and `openssl_pkey_get_details()['key']`
(`-----BEGIN PUBLIC KEY-----`, identical to `openssl rsa -pubout`). `$bits` = server `dkim_strength` when 1024, 2048 or
4096, else 2048 (the form's options and default). Selector: request `selector`, else stored, else `default` — the
effective legacy behavior. isp-test: 2048-bit generation ≈ 0.4 s in PHP-FPM.

## R2 — Storing the key and DNS

**Legacy**: `mail_domain_edit.php` onSubmit 348–351 derives `dkim_public` when empty; onAfterUpdate 704–735 calls
`update_dns()` for active domains when DKIM is on (and selector/key changed or DKIM enabled): purge `v=DKIM1` records of
the old selector, insert the new TXT record with zone sys fields, bump the SOA serial; when DKIM is off, downgrade the
DMARC record to `p=none`. Legacy never deletes the DKIM TXT record when DKIM is switched off.

**Decision**: set `dkim = y`, `dkim_private`, `dkim_public`, `dkim_selector` on the bound `MailDomain` and save it
(BaseModel: update gate `u`, lock guard — `mail_domain.active` only —, one `mail_domain` datalog entry), then call the
existing `MailDomainService::syncDnsAfterUpdate($domain, $oldRecord)` in the same transaction. No new DNS code.

## R3 — Availability

**Legacy**: `mail_plugin_dkim.inc.php::check_system()` refuses to write keys unless `dkim_path` is set, not empty and not
`/`; the form still saves and only the server log shows the error.

**Decision**: `AccountMailService::dkimPathUsable($serverId)` (feature 025) gates generation → 409
`DKIM signing is not available on the mail server of this domain.` (plain problem, no 023 type: it is a server
capability, not a plan feature). `GET …/dkim` reports it as `available`.

## R4 — Status view

**Legacy**: the form shows selector, private key, public key and — whenever `dkim_public` is not empty — the record
`<selector>._domainkey.<domain>. 3600 IN TXT "v=DKIM1; t=s; p=<key>"`; the `dkim_auto_dns` hint appears when
`find_soa_domain()` finds a hosted zone.

**Decision**: `MailDomainDkim` view: `id`, `domain`, `enabled`, `selector` (stored or `default`), `public_key`,
`key_bits` (`openssl_pkey_get_details()['bits']` of the public key, null when unparseable), `dns_record` {`name`,
`type: TXT`, `value`} when a public key exists, `dns_managed` (`MailDomainService::findSoaZone()` finds an active
zone), `available` (R3). The TTL is left to the zone (legacy's 3600 is display only).

## R5 — Private key visibility

**Facts**: `MailDomain::$hidden` only hides `relay_pass`; `dkim_private` is serialized for every key type by show, list,
store and update. Legacy shows the private key in the form to anyone who can edit the domain.

**Decision** (owner-delegated): `MailDomainController` removes `dkim_private` from its presented arrays when the acting
scope is not admin (single presentation path added in feature 026). Input stays accepted (customers may bring their own
key); admin keys keep the field for migrations.

**Alternatives considered**: hide for all keys — rejected, existing admin tooling (spec 003) reads it; `$hidden` with a
dynamic `makeVisible` for admins — rejected, easy to miss on new serialization paths.

## R6 — Contract and change sets

`POST /mail/domains/{id}/dkim` journals → its 200 response documents `X-Change-Set-Id`
(`tests/Unit/ChangeSetHeaderContractTest.php`). New module file `api/modules/mail/domain-dkim.yaml`, registered in
`api/modules/mail/_index.yaml` and `api/openapi.yaml`.

## R7 — Tests

Test server config `dkim_strength=1024` keeps generation fast; one case covers the 2048 default. DNS publication and
rotation reuse `DnsSchema` (as `MailDomainApiTest`).
