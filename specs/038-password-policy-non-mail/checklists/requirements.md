# Specification Quality Checklist: Password Policy for Non-Mail Users

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-16
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
- [x] No implementation leakage into requirements

## Legacy Parity (project-specific)

- [x] The enforced field list comes from the legacy forms that attach `validate_password`
      (`sites/form/{database_user,ftp_user,shell_user,web_folder_user,webdav_user}.tform.php`,
      `sites/form/web_vhost_domain.tform.php:620`, `client/form/{client,reseller}.tform.php`)
- [x] The policy source and defaults cite `auth::get_min_password_length()` / `get_min_password_strength()`
- [x] The message text is the legacy wording already ported in spec 028
- [x] Behaviour for every key type is stated, including that administrator keys are enforced as in legacy

## Notes

- Refusal tests must assert an empty `sys_datalog` and, for updates, an unchanged stored hash.
- The mail rule keeps its own tests; this feature must not change a single mailbox expectation.
- A consumer-visible consequence belongs in the final report: provisioning integrations that generate weak passwords
  begin to fail, which is the point of the change.
