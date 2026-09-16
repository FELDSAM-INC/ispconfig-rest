# Specification Quality Checklist: SSH Authentication Mode for Scoped Keys

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

- [x] The legacy behaviour is cited (`shell_user_edit.php:131-137` silent clearing;
      `admin/form/system_config.tform.php:256-260` for the setting's three values)
- [x] The deviation (refusing instead of clearing) is limited to scoped keys and recorded as an owner-delegated
      decision, with the precedents named (specs 033 and 025)
- [x] Administrator-key behaviour is stated explicitly and stays unchanged

## Notes

- US2's tests must assert an empty `sys_datalog` for every refusal.
- US3 is covered by the existing `ShellUserApiTest::test_ssh_authentication_mode_clears_the_other_credential`, which
  must keep passing unchanged — it is the regression guard for the administrator path.
