# Specification Quality Checklist: Web Permission Enforcement for Scoped Keys

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
  column references (constitution Principles I–III), so field and file names are intentional.
- Legacy read on ISPConfig 3.3.1p1 (isp-test.feldhost.cz): `web_vhost_domain_edit.php`,
  `form/web_vhost_domain.tform.php`, `tform_base.inc.php` `applyValueLimit()`, `ajax_get_json.php`, live
  `client` column defaults, `sys_ini [sites]`, server 1 `[web]` config (`php_default_hide = y`).
- No clarification markers. Owner-delegated decisions (2026-09-15) recorded in the Parity section: server-side
  enforcement of UI-only restrictions, explicit refusal instead of silent clearing for requested changes, empty
  system PHP list does not restrict, wildcard refusal, no confirm-once dialog.
