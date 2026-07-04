# Feature Specification: [FEATURE NAME]

**Feature Branch**: `[###-feature-name]`  
**Created**: [DATE]  
**Status**: Draft  
**Module**: [ISPConfig module this feature belongs to: client / dns / mail / monitor / server / sites / system]  
**Input**: User description: "$ARGUMENTS"

## User Scenarios & Testing *(mandatory)*

<!--
  IMPORTANT: User stories should be PRIORITIZED as user journeys ordered by importance.
  Each user story/journey must be INDEPENDENTLY TESTABLE - meaning if you implement just ONE of them,
  you should still have a viable MVP (Minimum Viable Product) that delivers value.

  Assign priorities (P1, P2, P3, etc.) to each story, where P1 is the most critical.
  For this project a "user" is typically an API consumer (integration script, control
  panel, automation tool) calling the REST endpoints with an X-API-Key.
-->

### User Story 1 - [Brief Title] (Priority: P1)

[Describe this API consumer journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently - e.g., "Can be fully tested by calling POST /api/v1/... and verifying the sys_datalog entry"]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]
2. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

### User Story 2 - [Brief Title] (Priority: P2)

[Describe this API consumer journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

[Add more user stories as needed, each with an assigned priority]

### Edge Cases

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right edge cases.
-->

- What happens when [boundary condition]?
- How does system handle [error scenario]?
- [Typical for this project: missing/invalid X-API-Key, referencing a nonexistent parent entity, ISPConfig `y`/`n` flag fields, permission (sys_perm_*) restrictions]

## API Contract *(mandatory)*

<!--
  Principle I: the OpenAPI spec is the source of truth. List the spec files this
  feature implements or changes BEFORE describing implementation. If the spec
  files already exist under api/modules/, the implementation must mirror them
  verbatim — paths, methods, parameters, status codes.
-->

- **Spec file(s)**: `api/modules/[module]/[resource].yaml` [existing — implement as-is / new — to be authored first]
- **Shared schemas**: `api/components/schemas/[Entity].yaml` [existing / new]
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/[module]/[resource]` | List (paginated: `{data, meta:{total,limit,offset}}`) | 200 |
| GET | `/api/v1/[module]/[resource]/{id}` | Show | 200 |
| POST | `/api/v1/[module]/[resource]` | Create (via datalog) | 201 |
| PUT | `/api/v1/[module]/[resource]/{id}` | Update (via datalog) | 200 |
| DELETE | `/api/v1/[module]/[resource]/{id}` | Delete (via datalog) | 204 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

<!--
  Principles II & III. Document what the legacy ISPConfig implementation does
  and which tables this feature writes through sys_datalog. "Not applicable"
  is acceptable for read-only features — say so explicitly.
-->

- **Legacy reference**: `source_code/interface/web/[module]/` — [relevant form/list/action files consulted]
- **Legacy behaviors to mirror**: [field validations, defaults, side effects (e.g., DNS serial bump), permission checks]
- **Tables written (via datalog only)**: [e.g., `mail_domain` — actions i/u/d]
- **System fields handling**: [sys_userid/sys_groupid/sys_perm_* defaults for created records; server_id source]
- **Intentional deviations from legacy**: [none / list and justify]

## Requirements *(mandatory)*

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right functional requirements.
-->

### Functional Requirements

- **FR-001**: System MUST [specific capability, e.g., "list mail domains with limit/offset/sort/order parameters"]
- **FR-002**: System MUST [specific capability, e.g., "validate the domain field exactly as legacy ISPConfig does"]
- **FR-003**: API consumers MUST be able to [key interaction]
- **FR-004**: System MUST [data requirement]
- **FR-005**: System MUST [behavior]

*Example of marking unclear requirements:*

- **FR-006**: System MUST handle [NEEDS CLARIFICATION: behavior not specified in api/ spec nor legacy source]

### Key Entities *(include if feature involves data)*

<!-- Map each entity to its ISPConfig table and its OpenAPI schema. -->

- **[Entity 1]**: [what it represents] — table `[ispconfig_table]`, schema `api/components/schemas/[Entity1].yaml`, model `app/Models/[Entity1].php`
- **[Entity 2]**: [what it represents, relationships to other entities]

## Success Criteria *(mandatory)*

<!--
  ACTION REQUIRED: Define measurable success criteria.
  These must be technology-agnostic and measurable.
-->

### Measurable Outcomes

- **SC-001**: [Measurable metric, e.g., "All endpoints defined in api/modules/[module]/[resource].yaml respond as specified"]
- **SC-002**: [Measurable metric, e.g., "Every write operation produces a well-formed sys_datalog entry that legacy ISPConfig processes without error"]
- **SC-003**: [Metric, e.g., "Swagger UI renders and 'Try it out' works for every new endpoint"]
- **SC-004**: [Metric, e.g., "Behavior matches legacy ISPConfig for the documented validation cases"]

## Assumptions

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right assumptions based on reasonable defaults
  chosen when the feature description did not specify certain details.
-->

- [Assumption about scope boundaries, e.g., "Only the endpoints already specced in api/modules/ are in scope"]
- [Assumption about auth, e.g., "Existing X-API-Key middleware is reused; no per-endpoint permission model beyond ISPConfig's"]
- [Assumption about data/environment, e.g., "A populated dbispconfig database is available"]
- [Dependency, e.g., "Legacy behavior verified against source_code/ version currently vendored"]
