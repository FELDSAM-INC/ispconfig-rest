# Alias DNS and mail services

Owner request (2026-09-20): website aliases always have a DNS zone; expose DNS synchronization and mail service switches, show enabled mail aliases in Mail, and protect synchronized zones from independent edits. Support shared aliases and vhost aliases.

## Contract / plan

- Add optional `dns_sync` and `mail_service` booleans to alias creation/update. Sending either opts into coordinated services; legacy callers omitting both retain their existing behavior. WHMCS always sends both on alias creation. Subdomains and primary vhosts reject these fields.
- Persist links only in the API-owned `api_alias_services` table. Never add ISPConfig columns. Responses include `alias_services` (zone ID, primary zone ID/domain, sync state and effective mail state), null for unmanaged aliases. DNS zones expose read-only `alias_sync` metadata.
- DNS creation/reuse and website creation are atomic and scoped to the parent's owning client. When a primary zone exists, initialize the alias from it; otherwise create independent starting NS/A/AAAA records from assigned hosting addresses. Enabling sync requires an existing owned primary zone. Disabling sync retains the current alias zone and records.
- Synchronize SOA configuration and all RR additions, changes and deletions, replacing only DNS names beneath the primary origin with the alias origin. Keep opaque TXT data unchanged (including DKIM). Preserve external targets. DNSSEC state, signatures and keys belong to the alias zone and are never copied. DNSSEC enablement is copied, so ISPConfig signs each zone separately.
- Direct API writes to synchronized zones/records return 409, including record moves and zone deletion. API-originated primary changes propagate in the same transaction/change set. A scheduled reconciliation command also detects ISPConfig-side changes and repairs secondary edits. Deleting a primary synchronized zone is refused; deleting a website alias detaches its zone and disables its managed mail alias.
- Mail service requires an owned primary mail domain. Create/reuse an owned source mail domain and an `@alias` -> `@primary` mail alias on the primary mail server. Reject conflicting routing/ownership. Turning mail off disables the alias and its service-created source domain; existing independent source domains are never silently disabled. No mailbox migration or deletion.
- Existing account limits and permissions apply to every side-effect model write. Errors roll back all changes. Public read permission alone never grants ownership.

## Verification

Feature tests: both alias types, tenant scoping, limits/rollback, DNS clone and subsequent add/update/delete/move, read-only enforcement, disabling sync, mail enable/disable/idempotence, independent zones, missing dependencies, external reconciliation and idempotent datalog. Contract parsing; full API suite. WHMCS: both themes/EN/CZ, real CSS browser renders, server-side locked DNS actions, Mail alias presentation, PHP 7.4/8.3.

## Reference and deployment

Existing REST models define the ISPConfig `dns_soa`, `dns_rr`, `mail_domain` and `mail_forwarding` schemas. Mail aliases use `mail_forwarding.type=aliasdomain` and require source/destination mail domains (spec 003/024). The relationship/synchronization behavior is an explicit extension requested by the owner, not an ISPConfig native field. Install/update creates the API-owned table and a one-minute scheduled reconciliation task; deploy API before WHMCS.

Mail alias metadata also supplies nullable `website` (id, type, parent_domain_id) for the single readable website alias with the same domain and ownership group. The lookup is batched. Missing, foreign or ambiguous website matches return null. This allows the mail list to link to website alias settings without additional per-row API requests.
