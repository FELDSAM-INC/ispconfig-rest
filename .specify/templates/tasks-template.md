---

description: "Task list template for feature implementation"
---

# Tasks: [FEATURE NAME]

**Input**: Design documents from `/specs/[###-feature-name]/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/

**Tests**: Tests are REQUIRED (constitution v2) — every endpoint ships with feature tests covering happy path, validation failures, auth failures, and datalog side effects. Pattern: `tests/Feature/[Entity]ApiTest.php`, run with `vendor/bin/phpunit`.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/[module]/[resource].yaml` (+ register in `api/modules/[module]/_index.yaml`) |
| OpenAPI schema | `api/components/schemas/[Entity].yaml` |
| Model | `app/Models/[Entity].php` — **must extend `App\Models\BaseModel`** |
| Controller | `app/Http/Controllers/Api/V1/[Entity]Controller.php` (submodule dirs allowed, e.g. `Monitor/`) |
| Service | `app/Services/[Name]Service.php` (only for reusable business logic) |
| Cast | `app/Casts/[Name].php` (e.g., `YesNoBoolean` for `y`/`n` columns) |
| Routes | `routes/api.php` (target; legacy `routes/web.php` until ported) — inside the auth'd `api/v1` group, specific-before-general order |
| Tests (REQUIRED) | `tests/Feature/[Entity]ApiTest.php` |

**The per-resource implementation flow is always**: spec YAML → model → (service if needed) → controller → routes → Swagger verification.

<!--
  ============================================================================
  IMPORTANT: The tasks below are SAMPLE TASKS for illustration purposes only.

  The /speckit-tasks command MUST replace these with actual tasks based on:
  - User stories from spec.md (with their priorities P1, P2, P3...)
  - Feature requirements from plan.md
  - Entities from data-model.md
  - Endpoints from contracts/ and api/modules/

  DO NOT keep these sample tasks in the generated tasks.md file.
  ============================================================================
-->

## Phase 1: Setup

**Purpose**: Contract and legacy research are in place before any PHP is written

- [ ] T001 Verify/author endpoint spec in `api/modules/[module]/[resource].yaml` and register it in `api/modules/[module]/_index.yaml`
- [ ] T002 [P] Verify/author shared schema(s) in `api/components/schemas/[Entity].yaml` (reuse `components/parameters` and `components/responses`)
- [ ] T003 [P] Extract legacy behavior from `source_code/interface/web/[module]/` (validators, defaults, side effects, permissions) into the spec's Parity section

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared pieces that MUST exist before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [ ] T004 Create model `app/Models/[Entity].php` extending `BaseModel` (explicit `$table`, `$primaryKey`, `$fillable`, casts such as `YesNoBoolean` for `y`/`n` fields, `$timestamps = false` inherited)
- [ ] T005 [P] Create service `app/Services/[Name]Service.php` if the feature has reusable business logic (e.g., serial/meta handling) — skip if not needed
- [ ] T006 Confirm datalog behavior for the entity: correct table name and primary key reach `sys_datalog` via `DatalogService`

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - [Title] (Priority: P1) 🎯 MVP

**Goal**: [Brief description of what this story delivers]

**Independent Test**: [How to verify this story works on its own, e.g., "curl the endpoint with X-API-Key and check the response shape and sys_datalog row"]

### Tests for User Story 1 (REQUIRED) ⚠️

- [ ] T007 [P] [US1] Feature test for [endpoints] in `tests/Feature/[Entity]ApiTest.php` — happy path, validation, auth, sys_datalog assertions

### Implementation for User Story 1

- [ ] T008 [US1] Implement `index`/`show` in `app/Http/Controllers/Api/V1/[Entity]Controller.php` — list envelope `{data, meta:{total,limit,offset}}` with `limit`/`offset`/`sort`/`order` params per shared spec components
- [ ] T009 [US1] Implement `store`/`update`/`destroy` — validation rules mirroring legacy ISPConfig (Form Requests on Laravel); writes via model `save()`/`delete()` only; return 201 (create) / 200 (update) / 204 (delete) per the OpenAPI spec; DB transaction + rollback + contextual logging; errors as RFC 9457 `application/problem+json`
- [ ] T010 [US1] Register routes in `routes/web.php` inside the `api.auth` group — verify ordering: specific routes before `[resource]/{id}` patterns, no shadowing of existing routes
- [ ] T011 [US1] Verify against Swagger UI (`/api/documentation`): module renders, paths/params/status codes match `api/modules/[module]/[resource].yaml` exactly

**Checkpoint**: At this point, User Story 1 should be fully functional and testable independently

---

## Phase 4: User Story 2 - [Title] (Priority: P2)

**Goal**: [Brief description of what this story delivers]

**Independent Test**: [How to verify this story works on its own]

### Implementation for User Story 2

- [ ] T012 [P] [US2] [Spec/schema additions if this story adds endpoints, in `api/modules/[module]/`]
- [ ] T013 [US2] [Controller/service work in `app/...`]
- [ ] T014 [US2] [Route registration + ordering check in `routes/web.php`]
- [ ] T015 [US2] [Swagger verification]

**Checkpoint**: At this point, User Stories 1 AND 2 should both work independently

---

[Add more user story phases as needed, following the same pattern]

---

## Phase N: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [ ] TXXX [P] Update `README.md` endpoint list if the public surface changed
- [ ] TXXX Code cleanup: controllers thin, shared logic in services, casts instead of ad-hoc `y`/`n` handling
- [ ] TXXX Re-verify legacy parity for the documented validation cases
- [ ] TXXX Run `vendor/bin/phpunit` (full suite must pass)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — spec + legacy research first, always
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (model must exist and datalog correctly)
- **User Stories (Phase 3+)**: All depend on Foundational phase completion
  - Stories can proceed in priority order (P1 → P2 → P3)
- **Polish (Final Phase)**: Depends on all desired user stories being complete

### Within Each User Story

- Tests (if requested) MUST be written and FAIL before implementation
- Spec YAML before controller work (Principle I)
- Model before controller; controller before routes
- Swagger verification is the story's last task

### Parallel Opportunities

- Spec/schema authoring (T001–T002) and legacy research (T003) can run in parallel
- Different resources within a module (separate YAML + controller + model files) can be built in parallel — **except** `routes/web.php` edits, which are sequential (single shared file, ordering matters)

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (contract + legacy research)
2. Complete Phase 2: Foundational (model datalogs correctly)
3. Complete Phase 3: User Story 1
4. **STOP and VALIDATE**: exercise the endpoints via Swagger UI "Try it out" (dev key auto-auth in local env); verify `sys_datalog` rows
5. Deploy/demo if ready

### Incremental Delivery

1. Each story adds endpoints without breaking previously delivered ones
2. `routes/web.php` ordering re-checked at every story boundary

---

## Notes

- [P] tasks = different files, no dependencies — never mark two edits to `routes/web.php` as [P]
- [Story] label maps task to specific user story for traceability
- Every write path goes through `BaseModel::save()`/`delete()` — a task that writes to the DB any other way violates the constitution
- Commit after each task or logical group
- Avoid: vague tasks, same-file conflicts, endpoints not present in the YAML spec
