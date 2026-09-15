# Implementation Plan: Machine-Readable Problem Types

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/023-problem-types/spec.md`

## Summary

`App\Support\ProblemType` defines the type names and URIs. Refusals thrown as exceptions use a new
`App\Exceptions\ProblemAuthorizationException` (subclass of `AuthorizationException`) carrying the type and extension
members; `Problem::fromThrowable()` renders them. Middleware refusals pass the type through `Problem::response()`
extensions. Validation problems get `type = validation-failed` and, for fields tagged in the request-scoped
`App\Support\ProblemTypeCollector` by the server assignment rules (016) and website permission checks (020), an
`error_types` map. Shared OpenAPI components, `docs/problems.md` and README document the types. Detail texts, titles
and status codes are unchanged.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: none (response metadata only)  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1048)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: no additional queries (values already computed by the checks)  
**Constraints**: byte-identical status, title, detail; other problems stay `about:blank`  
**Scale/Scope**: 6 type names, ~10 emitters, 4 shared contract components, 1 doc page

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: shared components `Problem.yaml`, `ValidationProblem.yaml`, new `ForbiddenProblem.yaml`,
  `ProblemLimit.yaml`, responses `Forbidden.yaml`, `UnprocessableEntity.yaml` updated before code
  (contracts/problem-types.md); every operation already references these responses.
- [x] **Datalog-only writes (II)**: no writes.
- [x] **Legacy parity (III)**: checks unchanged; legacy has only HTML error strings (spec Parity section).
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: RFC 9457 `type` URIs and extension members; status codes unchanged.
- [x] **No schema changes**: none.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/023-problem-types/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/problem-types.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/Problem.yaml               # type documentation
api/components/schemas/ValidationProblem.yaml     # error_types
api/components/schemas/ProblemLimit.yaml          # NEW
api/components/schemas/ForbiddenProblem.yaml      # NEW
api/components/schemas/_index.yaml                # register new schemas
api/components/responses/Forbidden.yaml           # ForbiddenProblem + examples
api/components/responses/UnprocessableEntity.yaml # examples
docs/problems.md                                  # NEW one section per type
app/Support/ProblemType.php                       # NEW names, URIs
app/Support/ProblemTypeCollector.php              # NEW request-scoped field tags
app/Exceptions/ProblemAuthorizationException.php  # NEW typed 403
app/Support/Problem.php                           # render typed 403 and 422 error_types
app/Providers/AppServiceProvider.php              # scoped ProblemTypeCollector
app/Services/LockedClientGuard.php                # account-locked
app/Services/ClientLimitService.php               # limit-reached / quota-exceeded + limit
app/Http/Middleware/RequireClientLimit.php        # feature-not-allowed
app/Http/Middleware/RequireBackupAccess.php       # feature-not-allowed
app/Http/Requests/Concerns/EnforcesBackupLimit.php
app/Services/WebPermissionService.php             # typedViolations(), certificate 403 type
app/Http/Requests/Concerns/EnforcesWebPermissions.php
app/Http/Requests/Concerns/ResolvesAssignedServer.php
tests/Feature/ProblemTypeRenderingTest.php        # NEW renderer + collector
tests/Feature/*                                   # type assertions added to existing emitter tests
README.md                                         # Problem types section
```

**Structure Decision**: typing lives next to each check so values (limits, columns) come from the check itself;
rendering stays centralised in `App\Support\Problem`.

## Legacy Research (Phase 0 focus)

Not applicable beyond confirming the checks are untouched (research R1).

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations.
