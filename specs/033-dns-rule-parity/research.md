# Research: Zone and Record Rule Parity for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only), 2026-09-16.

## R1 — Administrator-only zone fields

**Legacy**: `dns/form/dns_soa.tform.php` 344: `if(!$app->auth->is_admin()) unset($form["tabs"]['dns_soa']['fields']['update_acl']);`
— the field is removed from the form definition, so a posted value is ignored and the stored value kept.
`dns/templates/dns_soa_edit.htm` 139–142 shows `update_acl` inside `tmpl_if is_admin`, while `xfer` (132–133) and
`also_notify` (136–137) are outside it and the DNSSEC block is gated by `show_dnssec` (spec 032). `dns_soa_edit.php`
`onBeforeUpdate` 333–344: when the user is not an admin **and** `has_clients()` is false and `origin` is submitted,
a changed origin produces `soa_cannot_be_changed_txt` and the stored origin is restored. `server_id` is restored for
every non-admin on update (281–287) — already the API's spec 016 behaviour.

**Decision**: refuse `update_acl` for non-admin keys and a changed `origin` for non-reseller client keys, with the
typed 422 used for admin-only fields (spec 025 `custom_mailfilter`: `ProblemTypeCollector::tag()` →
`error_types.<field>` = `feature-not-allowed`). Re-sending the stored value is accepted, mirroring spec 016's
`server_id` rule and legacy's "nothing changes" outcome. `AuthScope::isReseller()` is the port of `has_clients()`.

## R2 — Record duplicate rules the API lacks

**Legacy** (all in `onInsert`/`onUpdate`, no user-type condition — they apply to administrators too):

| Form | Rule | Message |
|---|---|---|
| `dns_mx_edit.php` 50–66 | `zone`, `name`, `type`, `data` (update: `id != current`) | `Duplicate MX record.` |
| `dns_tlsa_edit.php` 110–130 | same four columns | `duplicate_tlsa_record_txt` |
| `dns_dkim_edit.php` 128–131 | `zone`, `type`, `data`, `name` | `DNS-Record already exists` |
| `dns_spf_edit.php` 165–188 | `zone`, `name`, `type = TXT`, `data LIKE 'v=spf1%'`: more than one → error; exactly one and it is not the record being edited → error | `SPF-Record already exists for hostname "{hostname}"…` |

`data` is the stored value, so MX compares the target host name only — the same target at another priority is a
duplicate (`aux` is not part of the comparison).

**Decision**: port all four into `DnsRecordRequest::zoneLevelChecks()` next to the spec 013 checks, zone-scoped and
self-excluded, comparing the composed `data` (as `checkCaa()` already does).

## R3 — What spec 013 already covers

CNAME conflict in both directions (FR-014, `dns_edit_base.php::checkDuplicate`), CNAME apex (FR-015) and target
existence (FR-016), A/AAAA identical + CNAME/ALIAS collision (FR-017), ALIAS/DNAME name collisions (FR-017), CAA
identical record (`checkCaa`, owner decision NC-2), DMARC prerequisites (FR-020), SRV target length (FR-018), and the
BIND-safety field rules FR-001…FR-011. The per-type `name`/`data` regexes come from the legacy tform validators
(e.g. `dns_a.tform.php` 88–91, `dns_mx.tform.php` 116–121) and are already mirrored.

**Decision**: no changes there; 033 adds only the four rules of R2.

## R4 — validate_dns is dead code in 3.3.1p1

`grep -rn "validate_rr\|validate_soa(\|validate_field(" interface/web interface/lib` returns only the internal calls
inside `lib/classes/validate_dns.inc.php`. The DNS pages load the class (`$app->uses(…,'validate_dns')`) solely for
`increase_serial()`. So the label rules (≤63 per label, character set, hyphen placement, wildcard only as the whole
first label), the "out of zone" check (which queries a non-existent `soa` table), the PTR trailing-dot rule, the
HINFO space rule and the `is_integer` ttl/aux bounds are never enforced by the panel.

**Decision**: do not add them (the feature's rule is "what legacy enforces for everyone"). Recorded as an explicit
non-gap so a later feature does not re-open it.

## R5 — Refusal shape

**API**: `ProblemAuthorizationException` (403) is used when a whole request is refused; spec 023 field refusals use
the 422 validation problem with `error_types.<field>`. `UpdateMailUserSpamFilterRequest` 79–87 is the precedent for
an admin-only field inside an otherwise valid request.

**Decision**: 422 with `errors.<field>` + `error_types.<field>` = `feature-not-allowed` for `update_acl` and
`origin`; plain 422 validation errors (no type) for the four duplicate rules, as the spec 013 checks do.
