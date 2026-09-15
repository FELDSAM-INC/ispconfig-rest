# Contract change: `X-Change-Set-Id` on every write operation

**Feature**: 015-change-status (FR-001, FR-012, SC-004; owner decision 2026-09-14)

## Scope (counted on `main` at 06dc558)

| Method | Operations | 2xx codes |
|--------|-----------:|-----------|
| POST | 43 | 201 × 40, 200 × 3 |
| PUT | 62 | 200 × 62, plus one extra 201 |
| DELETE | 43 | 204 × 43 |
| **Total** | **148** | 149 inline 2xx responses, 0 `$ref` 2xx responses |

54 files under `api/modules/*/` contain write operations. Every 2xx response is inline, so the header
can be added to each response object directly (OpenAPI 3.0 forbids siblings next to `$ref`).

## Change per 2xx response of every POST/PUT/PATCH/DELETE

```yaml
      '201':
        description: Mail domain created successfully
        headers:
          X-Change-Set-Id:
            $ref: '../../components/headers/ChangeSetId.yaml'
        content:
          application/json:
            schema:
              $ref: '../../components/schemas/MailDomain.yaml'
```

`headers` goes directly under the response's `description`, before `content`; 204 responses get only
`description` and `headers`.

## Shared registration

- New file `api/components/headers/ChangeSetId.yaml` (draft: `ChangeSetId.header.yaml`) and
  `api/components/headers/_index.yaml`.
- `api/openapi.yaml`: new `components.headers.ChangeSetId` entry pointing at the file.

## How to apply

1. Insert the three `headers` lines with a text edit per file (not a YAML dump — the module files carry
   comments and hand-written ordering that a dump would destroy).
2. Review the diff per module.
3. `tests/Unit/ChangeSetHeaderContractTest.php` parses every `api/modules/*/*.yaml` with `symfony/yaml`
   (already in `composer.lock`) and fails for any POST/PUT/PATCH/DELETE 2xx response without the
   `X-Change-Set-Id` header reference. This guards future endpoints, including features 014, 016 and 018
   that add writes.

## Runtime behaviour documented by the header description

- Present only when the request journaled at least one `sys_datalog` entry.
- Absent for no-change updates, validation failures, and writes that only touch API-owned data (API keys,
  spec 014) or legacy direct-SQL writes without journal (spamfilter config).
