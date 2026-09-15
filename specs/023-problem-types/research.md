# Research: Machine-Readable Problem Types (023)

## R1 — Emitters found in the code (main after 022)

| Refusal | Where | Current mechanism |
|---------|-------|-------------------|
| Locked account — services | `App\Services\LockedClientGuard::check()` | `AuthorizationException(MESSAGE)` → `Problem::fromThrowable` 403 |
| Locked account — backups | `LockedClientGuard::checkBackupWrite()` | `AuthorizationException(BACKUP_MESSAGE)` |
| Count limit (client/reseller) | `ClientLimitService::enforceClientCount()` / `enforceResellerCount()` → `deny()` | `AuthorizationException` |
| Quota sum (client/reseller) | `enforceClientQuota()` / `enforceResellerQuota()` → `denyQuotaIfExceeded()` → `deny()` | `AuthorizationException` |
| Limit gate | `App\Http\Middleware\RequireClientLimit` (`scope.limit:{column}`) | `Problem::response(403, …)` |
| Backups not enabled — endpoints | `RequireBackupAccess` | `Problem::response(403, …)` |
| Backups not enabled — `backup_*` fields | `Requests\Concerns\EnforcesBackupLimit::failedAuthorization()` | `AuthorizationException` |
| Certificate operations | `WebPermissionService::assertCertificateOperation()` | `AuthorizationException` |
| Server assignment (016) | `Requests\Concerns\ResolvesAssignedServer` rule closures and `server_id.required` message | validator messages |
| Website plan permissions (020) | `WebPermissionService::violations()` via `EnforcesWebPermissions::after()` | `$validator->errors()->add()` |

All 403s flow through `App\Support\Problem` (exception renderer in `bootstrap/app.php` or direct
`Problem::response()`); all 422s through `Problem::fromThrowable(ValidationException)`.

## R2 — Typed 403s

**Decision**: new `App\Exceptions\ProblemAuthorizationException extends AuthorizationException` carrying a type name
and extension members; `Problem::fromThrowable()` renders it with the type URI and members (status 403, title and
detail unchanged). Subclassing keeps every existing `catch`/renderer path and test that expects
`AuthorizationException`. Middleware that return `Problem::response()` pass `type` and `feature` in the extensions
array (the builder already merges extensions after the defaults).

**Alternatives rejected**: mapping detail texts to types inside the renderer (the fragile heuristic this feature
removes); a new exception hierarchy not extending `AuthorizationException` (breaks `BaseModel` write-gate handling).

## R3 — Typed field errors

**Decision**: a request-scoped `App\Support\ProblemTypeCollector` (container `scoped` binding like `IspContext`)
records `field → type name` when a typed rule fails: `ResolvesAssignedServer` rule closures (they receive the
attribute) and, for the "no server assigned" `required` failure, `mergeAssignedServerDefault()` /
`mergeSlaveDnsServerDefault()` tag `server_id` when no default exists; `EnforcesWebPermissions` tags each violation
reported as typed by `WebPermissionService::typedViolations()`. `Problem::fromThrowable(ValidationException)` adds
`error_types` for tagged fields that are present in the error bag. A tag without a matching error is ignored, so a
passing rule that tagged defensively cannot leak.

**Alternatives rejected**: overriding `failedValidation()` in every FormRequest (14+ requests); message matching.

## R4 — Limit extension values

`enforceClientCount()` has `$limit` and `$query->count()`; `enforceResellerCount()` the reseller limit and count;
quota paths have `$limit` (MB), `$used` (MB after `sumToMb`) and `$newQuota` (MB, `quotaToMb`). `deny()` gains the
spec and values; the unlimited-request case passes `requested = null`. `LimitSpec::$limitColumn` is the name. The
reseller count covers the reseller's own group set — the same rows the check compares, no other tenant's data.

## R5 — URI base

**Decision** (owner-delegated): `https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#<name>`;
GitHub renders `## <name>` headings with that anchor. Constant `ProblemType::BASE`.

## R6 — Compatibility

No test in the suite asserts `about:blank` (grep); OpenAPI examples for `Forbidden`/`UnprocessableEntity` show
`about:blank` and are updated. Status codes, titles and detail constants are not touched.
