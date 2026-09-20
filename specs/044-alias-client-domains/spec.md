# Alias creation with Client Domains enabled

Owner request, 2026-09-20: WHMCS Add alias must register both shared and vhost
aliases in ISPConfig Client Domains when the global domain-module switch is on.

## Contract and legacy reference

`POST /sites/web-child-domains` (`type=alias`) and `POST /sites/web-domains`
(`type=vhostalias`) register the normalized alias name automatically. No new
endpoint, request field or administrator credential is needed in the client area.

Verified against the official ISPConfig stable source:
`interface/web/sites/web_childdomain_edit.php` reads `use_domain_module`, populates
the domain dropdown with `getDomainModuleDomains`, and validates the selection
with `checkDomainModuleDomain`; `interface/web/client/domain_edit.php` assigns
the client's system group and `sys_perm_group=ru` to a domain registration.

The requested billing-panel behavior deliberately combines the administrator's
domain registration and the client's permitted alias creation. It does not open
`/clients/domains` to client keys or expose installation settings. Existing alias
limits, locked-client guards and parent visibility checks still apply. The
`limit_domainmodule` toggle is not treated as a numeric quota (spec 012 NC-1).

## Requirements

- Only aliases and only when `domains.use_domain_module=y`; disabled installations
  and subdomains preserve their existing behavior.
- Owner comes from the parent website, never request-supplied client/group fields.
  A non-admin key must have that group in its scope. Admin-owned websites without
  a client do not need registration.
- Reuse a same-owner registration unchanged. A registration belonging to another
  client produces 409 without revealing that client's identity or reassigning it.
- Use `ClientDomain::save()` and the existing outer transaction: a failed alias,
  limit check, registration or ownership check leaves neither partial row nor
  datalog. Concurrent unique-key collisions are rechecked for same ownership.
- The domain and alias datalog rows belong to the same change set.
- Alias deletion keeps the registration; mail or DNS may also use it. Client
  termination retains its existing domain-table cascade. No bulk backfill.

## Plan and acceptance

Add one registration service and call it after successful alias creation inside
both controllers' transactions. Test both types with real scoped-key fixtures:
enabled/disabled, same owner, foreign owner, forged ownership, foreign/read-only
parent, reseller's child, denied limit, locked client, rollback and deletion.
Run the full API suite and a live test with a temporary client on isp-test.
The WHMCS request shape and both theme templates stay compatible; document the
companion API version in the module repository and extend its live smoke tool.
