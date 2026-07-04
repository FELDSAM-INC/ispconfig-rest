# ISPConfig REST API Constitution

<!--
v2.0.0 — the "reboot" constitution (2026-07-04). The project was resumed after
abandonment with an explicit goal: a modern, industry-standard REST API for
ISPConfig. v1 described the codebase as it was; v2 additionally sets the target
platform and conventions for the rebuild. Where v1 encoded legacy accidents,
v2 replaces them with deliberate decisions made by the project owner.
-->

## Project Identity

- **Name**: ISPConfig REST API
- **Purpose**: A modern, industry-standard REST API for ISPConfig, exposing its entities (clients, DNS, mail, sites, servers, monitoring, system config) over versioned HTTP endpoints while respecting ISPConfig's own change-management and permission systems.
- **Target stack**: PHP 8.3+ on **Laravel 12**, Eloquent ORM, MySQL (ISPConfig's `dbispconfig` database). *Transition status*: the existing implementation is Lumen 8 and is being ported (reboot Phase 2); until then legacy paths (`routes/web.php`, PHPUnit 9) remain on disk but all NEW work targets Laravel conventions.
- **Architecture**: Monolithic, contract-first REST API. The OpenAPI spec in `api/` is authored first; PHP implements it.

## Core Principles

### I. Spec-First (OpenAPI Is the Source of Truth)

For any new or changed endpoint, the definition in `api/modules/{module}/*.yaml` comes first and the implementation mirrors it exactly — paths, methods, parameter names, request/response bodies, and status codes. Never invent URL patterns or response shapes in PHP that are not in the YAML. All request/response bodies MUST reference shared schemas under `api/components/schemas/*.yaml`; shared parameters (`limit`, `offset`, `sort`, `order`) and shared responses live under `api/components/parameters/` and `api/components/responses/`. Schemas MUST describe the real database (verify against `source_code/install/sql/ispconfig3.sql`) — no phantom columns, no wrong table names or primary keys.

### II. Datalog-Only Writes (NON-NEGOTIABLE)

Direct modification of ISPConfig tables is prohibited. Every model mapping an ISPConfig table MUST extend `App\Models\BaseModel`, whose `save()` and `delete()` route all changes through ISPConfig's `sys_datalog` table via `App\Services\DatalogService`. System fields (`sys_userid`, `sys_groupid`, `sys_perm_user`, `sys_perm_group`, `sys_perm_other`, `server_id`) are handled on every write. ISPConfig processes datalog entries asynchronously — success responses confirm the datalog entry, not the applied change.

**Documented exceptions** (must be justified in the plan's Complexity Tracking, citing legacy behavior): resync's forced datalog emission on unchanged rows, `sys_ini`/`server.config` blob read-merge-write, and other cases where legacy ISPConfig itself bypasses standard datalogging. Framework boilerplate models are exempt from the BaseModel rule.

### III. ISPConfig Behavioral Parity

Before implementing business logic, locate the legacy implementation in `source_code/interface/web/{module_name}/` and mirror its field validations, default values, side effects (serial bumps, cascades, derived fields, hash formats), and permission checks exactly. The reverse-engineered specs in `specs/` catalogue these per module — consult them first. Behavior may differ from legacy only when explicitly agreed in the feature spec. `source_code/` is a read-only reference — never modify it, never track it in git.

### IV. Layered Flow & Route Discipline

Request flow: routes (`routes/api.php` on Laravel; legacy `routes/web.php` until ported) → middleware (API-key auth, request shaping) → controller in `App\Http\Controllers\Api\V1` → model/service. Controllers use the RESTful method set `index / show / store / update / destroy` and stay thin — validation via Form Requests (Laravel) or `$this->validate()`, business logic in `app/Services/`. All routes live in the versioned, authenticated group (`API_PREFIX`, default `api/v1`); **specific routes MUST precede general ones** to prevent shadowing.

### V. HTTP Contract (Industry-Standard Conventions)

- **List responses**: `{ "data": [...], "meta": { "total": n, "limit": n, "offset": n } }` with `limit`/`offset`/`sort`/`order` query parameters (shared components).
- **Single resources**: the resource object as `data` or bare object per the shared response components — one shape project-wide, defined once in `api/components/`.
- **Errors**: **RFC 9457 `application/problem+json`** — `{ "type", "title", "status", "detail" }`, plus an `errors` map for 422 validation failures. No ad-hoc `{message, error}` bodies.
- **Status codes**: 200 read/update, **201 create**, **204 delete**, 400/401/403/404/409/422 as problem+json, 500 for unexpected failures. Never 202.
- **Auth**: `X-API-Key` header (`apiKeyAuth` scheme in OpenAPI). Keys validated against ISPConfig's permission model (`can_use_api`), stored **hashed at rest**. No basic auth.
- **Field normalization**: custom Eloquent casts (e.g., `YesNoBoolean` for `y`/`n` columns) — API speaks booleans/ISO dates, database keeps ISPConfig's native formats.

## Code Boundaries

| Path | Contents | Rules |
|------|----------|-------|
| `api/` | OpenAPI 3 spec: `openapi.yaml`, `modules/{module}/`, `components/{schemas,parameters,responses}/` | Contract source of truth; kebab-case module files, PascalCase schema files, `_index.yaml` per module |
| `specs/` | Spec-kit feature specs (001–009 reverse-engineered; new features append) | Gap lists in migrated specs = fix backlog |
| `app/Http/Controllers/Api/V1/` | Versioned API controllers (`<Entity>Controller`); submodule namespaces allowed | Thin controllers; HTTP concerns only |
| `app/Models/` | Eloquent models extending `BaseModel` | No timestamps; explicit `$table`/`$primaryKey`; ISPConfig system fields |
| `app/Services/` | Business/domain services (`<Name>Service`) | No HTTP response construction |
| `app/Http/Middleware/`, `app/Casts/` | Auth/request middleware, custom casts | — |
| `routes/api.php` (target) / `routes/web.php` (legacy) | All route registrations | Ordering rule (Principle IV) |
| `tests/Feature/` (target) / `tests/` (legacy) | PHPUnit feature tests per endpoint | — |
| `database/` | Factories and seeders for tests | **Never migrations against ISPConfig tables**; API-owned tables (if ever needed) must be clearly separate |
| `source_code/` | Untracked legacy ISPConfig source + original SQL (`install/sql/ispconfig3.sql`) | Read-only reference (Principle III) |

## Naming & Git Conventions

- **PHP**: PSR-4 under `App\`; StudlyCase classes; controllers named `<Entity>Controller`.
- **OpenAPI files**: kebab-case for module path files, PascalCase for schemas.
- **Routes**: plural resources nested under module prefixes (`clients/circles`, `dns/soa`, `mail/domains`).
- **Branches**: spec-kit numbered feature branches (`NNN-feature-name`).
- **Commits**: short imperative subject lines. No Conventional Commits requirement.

## Testing (REQUIRED)

- Framework: PHPUnit 11+ on Laravel (legacy: PHPUnit 9 until ported); feature tests under `tests/Feature/`.
- **Every new or ported endpoint ships with feature tests** covering the happy path, validation failures, auth failures, and its datalog side effects (assert the `sys_datalog` row). No endpoint is "done" untested.
- *(Changed from v1's "tests optional" as a deliberate part of the industry-standard reboot.)*

## Quality Gates

Before a feature is considered done:

1. Implementation matches `api/modules/{module}/*.yaml` exactly; the spec parses and Swagger UI renders it.
2. All models mapping ISPConfig tables extend `BaseModel`; no direct ISPConfig-table writes (or a documented Principle II exception).
3. Legacy behavior consulted (via `specs/` + `source_code/`) and parity documented.
4. Route ordering verified — no shadowing.
5. Responses follow Principle V: `{data, meta}` lists, problem+json errors, 200/201/204 codes.
6. Feature tests exist and pass (`vendor/bin/phpunit`).
7. `.editorconfig` conventions respected.

## Governance

This constitution supersedes generic habits and v1 where they conflict. Amendments go through `/speckit-constitution` and must cite either a detected convention or an explicit owner decision. Decision log: Laravel 12, pragmatic-REST conventions, in-place rebuild, and mandatory tests were chosen by the project owner on 2026-07-04 (reboot decisions). Intentionally unregulated: dev-server/runtime environment policy, commit-message format beyond existing style.

**Version**: 2.0.0 | **Ratified**: 2026-07-04 | **Last Amended**: 2026-07-04

<!--
2.0.0 (2026-07-04): Reboot constitution — target Laravel 12/PHP 8.3+; Principle V
rewritten to pragmatic REST ({data,meta} envelope, RFC 9457 problem+json, hashed
X-API-Key); tests now REQUIRED; Principle II gains a documented-exceptions clause
(resync/config blobs); schemas must match real DB (ispconfig3.sql).
1.0.1 (2026-07-04): Validation fixes — write status codes corrected to match the
OpenAPI spec; BaseModel rule scoped; Lumen scaffolding dirs added to boundaries.
1.0.0 (2026-07-04): Initial brownfield constitution from codebase scan.
-->
