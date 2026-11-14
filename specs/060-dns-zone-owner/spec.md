# Spec 060: Move a DNS zone to another client

Owner request, 2026-11-14: a WHMCS customer's domain whose zone lives in their
"domain services" client (WHMCS ispconfig module, spec 015 there) gets a
hosting service later; the zone must move to the hosting client with its
records instead of the hosting being unable to create it (`origin` is unique).

## Contract and legacy reference

`PUT /dns/soa/{id}` accepts `client_id` (already write-only in `DnsSoa.yaml`
for create). Legacy: the admin/reseller zone form's "Client" select
(`interface/web/dns/dns_soa_edit.php`) and
`interface/lib/plugins/dns_dns_soa_plugin.inc.php`, which on update sets the
zone's `sys_groupid` (and `sys_userid` to the client's login when the owner is
not the administrator) and runs `UPDATE dns_rr SET sys_groupid/sys_userid
WHERE zone = ?` directly, without datalog. The zone file does not change.

## Requirements

- FR-001: `client_id` on update resolves to the client's sys_group with the
  rules of create (`ResolvesClientOwnership`): admin any client, reseller only a
  client in its scope, client only itself, otherwise 403; unknown client 422
  (`exists`), groupless client 422.
- FR-002: the zone takes the group; `sys_userid` becomes the group's login
  unless the zone belongs to the administrator (userid 1). Its datalog entry
  ('u') carries the new owner (BaseModel save).
- FR-003: every record of the zone takes the group; records not owned by the
  administrator take the login. Written directly in the same transaction as the
  zone (documented exception to the datalog rule, legacy parity).
- FR-004: an owner change alone does not bump the serial; with other changed
  settings the serial is bumped once. The current client again writes nothing.
- FR-005: client domains (`PUT /clients/domains/{id}` with `client_id`) are
  unchanged and move separately.

## Plan

- `UpdateDnsSoaRequest`: `client_id` sometimes|integer|exists:client.
- `DnsSoaController::update`: owner resolution, serial only for settings,
  records moved after the zone save in one transaction.
- OpenAPI: owner section in the PUT description, `client_id` description.

### Complexity tracking

| Deviation | Why | Simpler alternative rejected |
| --- | --- | --- |
| `dns_rr` owner written without datalog | legacy `dns_dns_soa_plugin` does the same; ownership is not part of the DNS server's configuration, so a datalog row per record would only make the server rewrite the zone once per record | a BaseModel save per record (one datalog row and one zone rewrite per record) |

## Acceptance

`tests/Feature/DnsZoneOwnerApiTest.php`: admin move with records and logins,
serial rules, same client no-op, reseller boundary, client refused, unknown
client 422; the new owner reads the zone and its records, the old one gets 404.
