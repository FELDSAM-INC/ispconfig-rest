# ISPConfig REST API

Modern, contract-first REST API for ISPConfig. Laravel 12 (PHP 8.3+), MySQL (`dbispconfig`).

**Read first**: `.specify/memory/constitution.md` — the project constitution (v2). It defines the non-negotiables: OpenAPI spec in `api/` is the source of truth; all ISPConfig-table writes go through `App\Models\BaseModel` → `sys_datalog` (never direct); lists return `{data, meta}`; errors are RFC 9457 problem+json; write codes are 201/200/204; feature tests are required per endpoint.

**Feature specs** live in `specs/NNN-*/` (spec.md, plan.md, tasks.md). Migrated specs (001–004) describe the legacy Lumen implementation and its gap backlogs; draft specs (005–009) are implementation-ready for the unbuilt modules.

**Legacy reference**: `source_code/` (untracked) holds the original ISPConfig source — behavior parity is mandatory; the real DB schema is `source_code/install/sql/ispconfig3.sql`.

**Local dev**: PHP 8.5 lives at `/opt/homebrew/opt/php/bin/php` (shell default is 7.4 — always use the full path for artisan/composer). Run tests with `php artisan test` (sqlite in-memory). Dev server: `php artisan serve`; Swagger UI at `/api/documentation`; dev API key via `API_DEV_KEY` env (local/testing only).

<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan
<!-- SPECKIT END -->
