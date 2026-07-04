---
trigger: always_on
---

The project's engineering rules live in `.specify/memory/constitution.md` — read and follow it. It supersedes everything that used to be in this file.

Highlights (see the constitution for the full, current versions):

1. The OpenAPI spec in `api/` is the source of truth — implement it exactly.
2. All ISPConfig-table writes go through `App\Models\BaseModel` → `sys_datalog`. Never write directly.
3. Mirror legacy ISPConfig behavior (`source_code/interface/web/{module}/`); the reverse-engineered specs in `specs/` catalogue it.
4. Lists return `{data, meta:{total,limit,offset}}`; errors are RFC 9457 `application/problem+json`; write codes are 201/200/204 — never 202.
5. Feature tests are required for every endpoint (`php artisan test`, PHP binary at `/opt/homebrew/opt/php/bin/php` locally).
