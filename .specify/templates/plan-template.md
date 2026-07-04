# Implementation Plan: [FEATURE]

**Branch**: `[###-feature-name]` | **Date**: [DATE] | **Spec**: [link]
**Input**: Feature specification from `/specs/[###-feature-name]/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

[Extract from feature spec: primary requirement + technical approach from research]

## Technical Context

<!--
  Pre-filled for this codebase (brownfield bootstrap 2026-07-04). Adjust only
  the feature-specific rows; the stack rows are fixed project facts.
-->

**Language/Version**: PHP 8.3+ (Laravel 12) — target platform; legacy Lumen 8 code is being ported (reboot Phase 2)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`)  
**Testing**: PHPUnit (`vendor/bin/phpunit`), feature tests in `tests/Feature/` — REQUIRED per constitution v2 for every endpoint (happy path, validation, auth, datalog assertion)  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: [feature-specific or N/A]  
**Constraints**: async write semantics via `sys_datalog` (spec status codes: 201 create / 200 update / 204 delete); behavioral parity with legacy ISPConfig (`source_code/interface/web/{module}/`)  
**Scale/Scope**: [feature-specific, e.g., number of endpoints/entities touched]

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [ ] **Spec-first (I)**: `api/modules/{module}/*.yaml` definitions exist (or are authored in Phase 1) and the plan implements them verbatim; bodies reference `api/components/schemas/`
- [ ] **Datalog-only writes (II)**: every new/touched model mapping an ISPConfig table extends `App\Models\BaseModel`; no direct table writes
- [ ] **Legacy parity (III)**: legacy implementation in `source_code/interface/web/{module}/` reviewed; validations/defaults/side-effects captured in the spec's Parity section
- [ ] **Route discipline (IV)**: new routes go in `routes/web.php` inside the `api.auth` group; specific-before-general ordering preserved
- [ ] **HTTP contract (V)**: lists `{data, meta:{total,limit,offset}}`; errors RFC 9457 `application/problem+json`; status codes per spec — 200 read/update, 201 create, 204 delete, 400/401/403/404/409/422 problem+json
- [ ] **No schema changes**: no migrations against ISPConfig tables

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit-plan command output)
├── research.md          # Phase 0 output (/speckit-plan command)
├── data-model.md        # Phase 1 output (/speckit-plan command)
├── quickstart.md        # Phase 1 output (/speckit-plan command)
├── contracts/           # Phase 1 output (/speckit-plan command)
└── tasks.md             # Phase 2 output (/speckit-tasks command - NOT created by /speckit-plan)
```

### Source Code (repository root)

<!--
  This is the project's real layout. List the concrete files this feature adds
  or changes under each path; delete rows that the feature does not touch.
-->

```text
api/
├── openapi.yaml                          # Root spec — register new module refs here if needed
├── modules/[module]/
│   ├── _index.yaml                       # Module index — reference new resource files
│   └── [resource].yaml                   # Endpoint definitions for this feature
└── components/
    ├── schemas/[Entity].yaml             # Shared request/response schemas
    ├── parameters/                       # limit, offset, sort, order (reuse)
    └── responses/                        # Shared error responses (reuse)

app/
├── Http/Controllers/Api/V1/[Entity]Controller.php   # index/show/store/update/destroy
├── Models/[Entity].php                                # extends BaseModel
├── Services/[Name]Service.php                         # only if reusable business logic exists
└── Casts/                                             # only if a new field cast is needed

routes/api.php                            # Route registrations (ordering rule!) — legacy code still in routes/web.php until ported

tests/Feature/[Entity]ApiTest.php         # REQUIRED per constitution v2
```

**Structure Decision**: [Reference the concrete files above; note where in routes/web.php the new routes slot to respect specific-before-general ordering]

## Legacy Research (Phase 0 focus)

<!-- What to look for in source_code/interface/web/{module}/ before designing -->

- Form definition (`form/*.tform.php`): field list, validators, defaults, value transformations
- Actions/lib: side effects on insert/update/delete (e.g., serial bumps, dependent records)
- Permission checks: required sys_perm / group behavior
- List definition: filterable/sortable columns (informs index endpoint parameters)

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| [e.g., direct DB read of sys tables] | [current need] | [why model/service insufficient] |
