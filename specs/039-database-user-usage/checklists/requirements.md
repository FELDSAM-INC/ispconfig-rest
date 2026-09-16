# Specification Quality Checklist: Database User Usage and Unlink Safety

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

- [x] The refusal cites its legacy call site (`database_user_del.php::onBeforeDelete()`, both `database_user_id` and
      `database_ro_user_id`) and reuses the legacy message
- [x] The status code follows this API's existing in-use convention (409, as for in-use directive snippets and client
      templates)
- [x] Behaviour for every key type is stated, including that administrator keys are refused
- [x] The contract correction is called out: the current delete description states the opposite of legacy

## Notes

- Refusal tests must assert an empty `sys_datalog` and that both the user and the database survive.
- The usage count must be scoped like every other read, and the list must not issue one query per row.
- `resource-in-use` is a new problem type for spec 023's registry; it needs an entry in `docs/problems.md`.
