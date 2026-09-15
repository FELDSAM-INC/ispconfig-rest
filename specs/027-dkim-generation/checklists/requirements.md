# Specification Quality Checklist: DKIM Key Generation for Mail Domains

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-15
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- This project's template requires API Contract and ISPConfig Parity sections with legacy file, table and
  column references (constitution Principles I–III), so endpoint, field and file names are intentional.
- Legacy read on ISPConfig 3.3.1p1 (isp-test.feldhost.cz): `mail/ajax_get_json.php` (`create_dkim`),
  `mail/mail_domain_edit.php`, `mail/templates/mail_domain_edit.htm`, `server/plugins-available/mail_plugin_dkim.inc.php`,
  `admin/form/server_config.tform.php`; live: `test.cz` DKIM on (selector `default`, PKCS#8 private key header, DNS
  record 28 in zone 1), server 1 `dkim_path=/var/lib/amavis/dkim`, `dkim_strength=2048`, PHP OpenSSL generates a 2048-bit
  key in ~0.4 s.
- Consumer fit checked against the WHMCS module `specs/004-mail/contracts/ispconfig-rest-calls.md`
  (`POST /mail/domains/{id}/dkim`, `PUT /mail/domains/{id}` `dkim: false`, reads `dkim, dkim_selector, dkim_public`,
  never `dkim_private`).
- No clarification markers. Owner-delegated decisions (2026-09-15) recorded in the Parity section.
