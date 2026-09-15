# Research: DNS Zone Wizard For Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only), 2026-09-16.
Decisions marked **(owner-delegated decision 2026-09-16)** were taken on the owner's behalf.

## R1 — What the legacy wizard does

`dns/dns_wizard.php` renders the form; `lib/classes/dns_wizard.inc.php::create($data)` does the work:

1. resolve the server (`$data['server_id']`, else `server_id_value`, else global `dns.default_dnsserver`, else the
   first `dns_server = 1` row); when the server came from the request and the user is not an admin, it must appear
   in the client's `dns_servers` list (`error_not_allowed_server_id`);
2. normalize `domain`, `ns1`, `ns2`, `email` with `idn_encode` + `strtolower`;
3. validate: domain `/^[\w\.\-]{1,64}\.[a-zA-Z0-9\-]{2,63}$/`, ns1/ns2 `/^[\w\.\-]{1,64}\.[a-zA-Z0-9]{2,63}$/`,
   `email` with `FILTER_VALIDATE_EMAIL`, each only when the key was submitted and empty;
4. `checkClientLimit('limit_dns_zone')` and `checkResellerLimit('limit_dns_zone')` for non-admins;
5. load the template row by id, replace `{DOMAIN}`, `{IP}`, `{IPV6}`, `{NS1}`, `{NS2}`, `{EMAIL}` in the text,
   inject `dnssec_wanted=Y` after `[ZONE]` when `dnssec` was posted, append a DKIM TXT row when `dkim` was posted;
6. parse the text into a `[ZONE]` key/value map and `[DNS_RECORDS]` rows `TYPE|name|data|aux|ttl`;
7. require `origin`, `ns`, `mbox`, `refresh`, `retry`, `expire`, `minimum`, `ttl` to be non-empty;
8. `datalogInsert('dns_soa', …, active = 'N')` with `sys_perm_user/group/other` = `riud`/`riud`/`''`,
   `serial = increase_serial(0)`, `mbox` with `@` replaced by `.`;
9. one `datalogInsert('dns_rr', …)` per parsed row, carrying the zone's `server_id` and `sys_groupid`,
   `active = 'Y'`;
10. `datalogUpdate('dns_soa', ['active' => 'Y'], 'id', $id)`.

**Decision**: mirror steps 1–10, including the inactive-then-active order, in one transaction and one change set.

## R2 — Who may see a template

`dns_wizard.php:73` lists templates with `SELECT * FROM dns_template WHERE visible = 'Y' ORDER BY name ASC` — no
`getAuthSQL`, so every user sees every visible template. The API's `GET /dns/templates` is row-scoped (spec 011),
and the shipped "Default" row is `sys_groupid = 1`, `sys_perm_other = ''`, so a client key sees nothing
(`ScopingDnsModuleTest::test_dns_template_reads_are_row_scoped_and_writes_admin_only` asserts exactly that).

**Decision**: add a separate read-only resource `GET /dns/zone-templates` that applies the legacy rule (visible
only, no row predicate) and exposes only `id`, `name` and `fields`. `GET /dns/templates` — the administrator's
management resource — keeps its row scoping and admin-only writes.
**Alternatives rejected**: relaxing `/dns/templates`' predicate (would leak administrator-authored template text and
break spec 011's guarantee); a `wizard=true` filter on the same path (same resource with two permission models).

## R3 — Placeholders and the `fields` column

`dns_template.fields` is a comma-separated list from `DOMAIN, IP, IPV6, NS1, NS2, EMAIL, DKIM, DNSSEC`
(`DnsTemplate::ALLOWED_FIELDS`, legacy `dns_template.tform.php`). `dns_wizard.php:177-190` shows one form input per
token; `DNSSEC` is suppressed when any mirrored DNS server exists.

**Decision**: the request accepts `domain` (always), `ip`, `ipv6`, `ns1`, `ns2`, `email` and the flags `dkim`,
`dnssec`; every token the template declares must be supplied, and a value or flag the template does not declare is
refused with 422 (deviation 2, below). Mapping: `IP → ip`, `IPV6 → ipv6`, `NS1 → ns1`, `NS2 → ns2`,
`EMAIL → email`, `DKIM → dkim`, `DNSSEC → dnssec`.

**Legacy bug not reproduced (owner-delegated decision 2026-09-16)**: legacy only validates a placeholder value when
the key is present in the POST, and replaces only non-empty values — a template using `{IP}` with no `ip` submitted
produces records containing the literal string `{IP}`. The API refuses instead.

## R4 — Template text format

```
[ZONE]
key=value            # origin, ns, mbox, refresh, retry, expire, minimum, ttl, xfer,
                     # also_notify, update_acl, dnssec_wanted, dnssec_algo
[DNS_RECORDS]
TYPE|name|data|aux|ttl
```

Rows are trimmed, empty rows skipped; a section header other than the two known ones ends the legacy request with
`die('Unknown section type')`.

**Decision**: parse the same way. A malformed template (unknown section, a `[ZONE]` row without `=`, an unparsable
record row, a record type outside `dns_rr.type`, or a missing required zone key) is refused with 422 on
`template_id` — never a 500 (deviation 4). Zone keys outside the `dns_soa` columns the contract allows are ignored.

**Short record rows (owner-delegated decision 2026-09-16)**: legacy reads `aux`/`ttl` from `$parts[3]`/`$parts[4]`
and stores NULL (→ 0) when the row has fewer parts — its own appended DKIM row is exactly such a row, so the panel
writes DKIM records with TTL 0. The API uses priority 0 and the zone's TTL instead (deviation 5).

## R5 — The optional DKIM record

Legacy, when `dkim` is posted and the domain matches `/^[\w\.\-\/]{1,255}\.[a-zA-Z0-9\-]{2,63}[\.]{0,1}$/`:

```sql
SELECT dkim_public, dkim_selector FROM mail_domain WHERE domain = ? AND dkim = 'y' AND <getAuthSQL('r')>
```

and appends `TXT|<selector>._domainkey.<domain>.|v=DKIM1; t=s; p=<public key without PEM headers and newlines>`,
with `default` as the selector fallback. No match → no record, no error.

**Decision**: same, with the lookup restricted to mail domains the key can read (spec 024's `readableQuery`), which
is what `getAuthSQL('r')` does. Spec 027 already keeps `dkim_private` away from scoped keys; only `dkim_public` is
read here.

## R6 — The DNSSEC flag

Legacy prepends `dnssec_wanted=Y` to the `[ZONE]` section when `dnssec` is posted; the template's own default is
`dnssec_wanted=N` with `dnssec_algo=ECDSAP256SHA256`.

**Decision**: same. `dnssec_wanted` and `dnssec_algo` are already writable on `dns_soa` for every key (spec 033
confirmed them as "still writable"), so no new permission question arises here. Managing DNSSEC after creation,
the DS data and the mirrored-server rule are spec 032.

## R7 — Limits

`dns_wizard.php:44-55` and `create()` check `limit_dns_zone` for client and reseller. **Neither checks
`limit_dns_record`** — `grep -rn limit_dns_record` over the interface finds it only in `dns_edit_base.php:105-118`,
the five record forms and the dashboard. The wizard is therefore a hole: a customer at its record cap creates a
seven-record zone through it.

**Decision (owner-delegated decision 2026-09-16, deviation 1)**: count the whole batch against `limit_dns_record`
before writing anything and refuse with 403 `limit-reached` (`limit.name = limit_dns_record`, `scope = client`,
`max`, `used` = records already counted). The zone cap is enforced the same way (client and reseller, as legacy).
Rationale: spec 030 made the cap real for `POST /dns/records`; leaving the wizard uncapped would make the cap
advisory, and the WHMCS module treats it as a release gate.

**Implementation note**: `ClientLimitService` already owns both specs (`limit_dns_zone` → `dns_soa` with a reseller
cap, `limit_dns_record` → `dns_rr` with the `grp` predicate and no reseller cap). The service gains a batch entry
point that denies when `used + n > max`; with `n = 1` it is exactly today's `used >= max` rule, so the existing
per-row chokepoint in `BaseModel::save()` is unchanged and stays as defence in depth.

## R8 — Server selection and ownership

Legacy restricts a non-admin to servers in the client's `dns_servers`; the API already models this as
`ResolvesAssignedServer` (spec 016), used by `StoreDnsSoaRequest` with the same legacy reference.

**Decision**: reuse `ResolvesAssignedServer('dns')` verbatim — assigned-server default when `server_id` is omitted,
422 with `error_types.server_id = server-not-assigned` otherwise; administrator keys may name any
`dns_server = 1 AND mirror_server_id = 0` row. Ownership follows `POST /dns/soa`: `client_id` resolved through
`ResolvesClientOwnership` for administrator and reseller keys, `BaseModel::applySysFieldDefaults()` otherwise.
Records inherit the zone's `server_id` and `sys_groupid`, as `DnsRecordController::store()` already does.

## R9 — Per-record validation

`POST /dns/records` runs the full contract validation plus spec 013's zone-level checks (CNAME conflicts, duplicate
A/AAAA/CAA, DMARC prerequisites) and spec 033's duplicate rules. Template records would trip some of those by
design — the "Default" template writes two NS records for the same name, which the duplicate rules permit, but a
provider template may legitimately contain rows the interactive form would question.

**Decision (owner-delegated decision 2026-09-16, deviation 6)**: template records are written as authored, with only
structural validation (known record type, non-empty name and data, numeric `aux`/`ttl`). Legacy does the same — the
wizard inserts rows directly without the record forms. Customer-supplied values only reach records through the
placeholders, which are validated (R3).

## R10 — Endpoint shape

**Decision**: `POST /dns/soa/from-template`, returning 201 with the created `DnsSoa` and `X-Change-Set-Id` — the
path the WHMCS module's contract already anticipates (`specs/005-dns/contracts/ispconfig-rest-calls.md`: "zone
wizard (e.g. `POST /dns/soa/from-template`)"). Registered before `dns/soa/{dnsSoa}` (constitution Principle IV);
`{dnsSoa}` is `whereNumber`, so no shadowing either way.
**Alternatives rejected**: `POST /dns/zones/wizard` (invents a second noun for zones), a `template_id` field on
`POST /dns/soa` (one endpoint with two very different request bodies and result shapes).

## R11 — One change set, all or nothing

`AttachChangeSetId` gives every journal entry of one request the same session id, so the change set comes for free.
The zone insert, the record inserts and the activation run inside `DB::transaction()`, so a refusal (limit, invalid
row) or a failure leaves no zone and no records — better than legacy, which can leave a zone stuck inactive.

## R12 — Consumer fit (WHMCS module spec 005)

The module's research R9 currently hard-codes the "Default" record set and marks the template choice blocked on this
spec (task T049). After this ships it can: list templates with `GET /dns/zone-templates`, show `name` and ask for
the declared `fields`, and create the zone with one call. The module still needs `GET /me/hosting-addresses`
(spec 031) to prefill `ip`/`ipv6`/`ns1`/`ns2`.
