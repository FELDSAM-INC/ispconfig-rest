# Research: DNSSEC Management For Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only), 2026-09-16.
Decisions marked **(owner-delegated decision 2026-09-16)** were taken on the owner's behalf.

## R1 — The write side already works

`DnsSoa::$fillable` contains `dnssec_wanted` and `dnssec_algo`, and `DnsSoaRequest::commonRules()` validates them
(`boolean`, `in:NSEC3RSASHA1,ECDSAP256SHA256`). Spec 033 confirmed them as "still writable for every key", and the
spec 029 verification on isp-test proved it end to end: a **client** key created a zone with `dnssec: true`, and
the name server generated `Kqa029c-….+013+05269.key`/`.private` (KSK), a second pair (ZSK) and a
`dsset-qa029c-….` file, then filled `dnssec_info`.

**Consequence**: this feature is not about making DNSSEC writable. It adds the read side, the availability gate and
the masking. Spec 002's note that DNSSEC is "unmanageable via API" is stale and is corrected by 029/032.

## R2 — Who may use DNSSEC in legacy

The `client` table has `limit_dns_zone`, `limit_dns_slave_zone` and `limit_dns_record` — **no** DNSSEC limit column
(verified with `SHOW COLUMNS FROM client`). `dns_soa_edit.php` computes `show_dnssec` in both the administrator
branch (92–102) and the non-administrator branch (157–168), and `dns_soa_edit.htm` 155–172 wraps the whole block in
`<tmpl_if name="show_dnssec">`.

**Decision**: DNSSEC is a customer-facing feature in legacy, so no plan gate is invented. The only gate is R3.
**Alternative rejected**: gating on a new client limit column — it would diverge from the panel and require schema
changes the constitution forbids for ISPConfig tables.

## R3 — The mirror rule

```php
// existing zone (dns_soa_edit.php:95-98)
SELECT count(*) AS count FROM server WHERE mirror_server_id = <the zone's server_id>
// new zone
SELECT count(*) AS count FROM server WHERE mirror_server_id > 0 AND dns_server = 1
// non-admin (157-168): the same query per server id in the client's dns_servers, break on the first hit
$show_dnssec = ($rec['count'] > 0) ? 0 : 1;
```

ISPConfig signs on the master only; a mirrored DNS server would serve the zone unsigned, so the panel hides the
block entirely rather than producing a half-signed zone.

**Decision**: expose it as `available` on the sub-resource (false ⇒ `state` `unavailable`), and refuse an attempt to
*enable* with 422 `feature-not-allowed` on `dnssec_wanted` (deviation 1). Switching off and re-sending the stored
value stay allowed — the same rule spec 016/033 already use for immutable fields. isp-test has a single
non-mirrored DNS server, so `available` is true there; the false case is covered by a test fixture and by a
temporary mirror row during the live check.

## R4 — What the server writes into `dnssec_info`

Captured live on isp-test (zone `qa029c-…`, bind9):

```
DS-Records:
qa029c-….example.test. IN\tDS 5269 13 2 44F315FBF85AC547DBE621FEB51C011A735ECA5D44E0BDF07C47C4A7 217F7D81

------------------------------------

DNSKEY-Records:
; This is a key-signing key, keyid 5269, for qa029c-….example.test.
; Created: 20260915233801 (Wed Sep 16 01:38:01 2026)
qa029c-….example.test. IN DNSKEY 257 3 13 HId4lryEWgDLIwtbwAyHy6N/…

; This is a zone-signing key, keyid 53069, for qa029c-….example.test.
qa029c-….example.test. IN DNSKEY 256 3 13 6KqunwKuQyqUuw5Am3IY12gELTDVq05so+…
```

Written by `bind_plugin.inc.php::soa_dnssec_sign()` 178–194: the `dsset-` file verbatim, a dashed separator, then
every `K<domain>.+0NN+*.key` file. `dnssec_initialized` becomes `Y` and `dnssec_last_signed` a unix timestamp, both
with a **direct UPDATE** (a documented legacy exception — the API never writes these columns).

Notes: the digest is split across a line by `dnssec-signzone`, so whitespace inside it must be removed; `\t`
separates `IN` and `DS`; comment lines start with `;`; DS is `key_tag algorithm digest_type digest`; DNSKEY is
`flags protocol algorithm public_key`, with 257 = key-signing key and 256 = zone-signing key.

**PowerDNS** (`powerdns_plugin.inc.php` 527–560) assembles the same column from the public keys **plus** a
`== Raw log ====` section containing the `pdnsutil` command output — the plugin's own comment says having commands
in `dnssec_info` "will trigger the IDS if you try to save the record using the interface afterwards".

**Decision**: parse DS and DNSKEY lines, ignore everything else (so a PowerDNS log simply yields no extra records),
and never return the raw column to a non-admin key (deviation 2). A parse failure is not an error: the flags stay
the source of truth for `state`.

## R5 — State machine

| `dnssec_wanted` | `dnssec_initialized` | mirrored server | `state` |
|---|---|---|---|
| any | any | yes | `unavailable` |
| false | any | no | `off` |
| true | false | no | `pending` |
| true | true | no | `signed` |

`bind_plugin.inc.php` 392–406: signing starts when `wanted = Y` and `initialized = N`; switching off only deletes
the `.signed` file and leaves `dnssec_initialized`, the notes and the key files in place (`soa_dnssec_delete` runs
only on an origin change or zone removal). So a zone switched off and on again keeps its DS record — worth telling
the customer, and the reason `state` follows `dnssec_wanted` rather than `dnssec_initialized`.

## R6 — Endpoint shape

**Decision**: `GET /dns/soa/{id}/dnssec`, modelled on spec 022's `GET /sites/web-domains/{id}/ssl/status`:
read-only, no plan gate, 404 through the route-model binding for a zone the key cannot read, no
`X-Change-Set-Id`. A `DnssecStatusService` parses the notes, mirroring `LetsEncryptStatusService`.
Route registered before `dns/soa/{dnsSoa}` is irrelevant here (distinct suffix) but the sub-resource is registered
next to the zone routes per Principle IV.
**Alternatives rejected**: adding parsed fields to the zone resource (it would grow `DnsSoa` for every list row);
a `?include=dnssec` parameter (not a convention in this API).

## R7 — Masking the raw column

`DnsSoa` has no `$hidden`, so `dnssec_info` is currently returned to every key that can read the zone.

**Decision (deviation 2)**: return `null` for client and reseller keys, unchanged for administrator keys —
implemented on the model's serialization so it applies to show, list and any nested read. The other DNSSEC columns
(`dnssec_wanted`, `dnssec_algo`, `dnssec_initialized`, `dnssec_last_signed`) stay visible to everyone: they carry
no secrets and the panel needs them.

## R8 — Consumer fit (WHMCS module spec 005)

Module research R14 currently reads `dnssec_wanted`/`dnssec_initialized` from the zone and parses `dnssec_info`
itself, with switching blocked (module US7 scenarios 2–3, task T092). After this ships the module reads
`GET /dns/soa/{id}/dnssec` for status and DS data, hides the switch when `available` is false, and maps the typed
422 like its other `feature-not-allowed` fields.
