# Implementation Plan: Spam Filter Level Selection for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/026-spamfilter-level/spec.md`

## Summary

- New `SpamfilterUserService`: read the level of one or many recipient keys and upsert a companion
  `spamfilter_users` row with the legacy defaults through `DatalogService` (update `policy_id` only when it differs).
- `GET/PUT /mail/users/{id}/spamfilter` gain `policy_id`; `UpdateMailUserSpamFilterRequest` validates it with the
  spec 024 readable-reference rule; the controller checks `u` on the mailbox, then saves the tab fields and the level in
  one transaction.
- Mail domain resource gains `spamfilter_policy_id`: presented by `MailDomainController` (one batched query for lists),
  accepted by `POST`/`PUT` (`MailDomainRequest` rule), written with the domain in one transaction.
- `SystemSchema` test `spamfilter_users` gets the real columns.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — `spamfilter_users` (datalog `i`/`u`), reads `spamfilter_policy`, `mail_user`,
`mail_domain`  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1092)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: + 1 query per mailbox/domain read, + 1 per domain list page  
**Constraints**: datalog-only writes; companion row in the same change set; refusals write nothing  
**Scale/Scope**: 2 schema fields, 5 operations touched, 1 service, 1 request concern

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `MailUserSpamFilter.yaml` `policy_id`, `MailDomain.yaml` `spamfilter_policy_id`, descriptions
  in `user-spamfilter.yaml`, `domains.yaml`, `spamfilter-policies.yaml` before code (contracts/spamfilter-level.md).
- [x] **Datalog-only writes (II)**: `spamfilter_users` written only through `DatalogService::insertRecord/updateRecord`
  (legacy direct datalog calls, research R1).
- [x] **Legacy parity (III)**: `mail_user_edit.php` 99–110, 336–360, 395–480; `mail_domain_edit.php` 194–206, 364–390,
  468–492; `spamfilter_users.tform.php` 78–86 (research R1–R5); deviations owner-delegated in the spec.
- [x] **Route discipline (IV)**: no new routes; controllers stay thin (service + request concern).
- [x] **HTTP contract (V)**: 200/201 unchanged; problem+json 403/404/422; `X-Change-Set-Id` already documented on
  the touched writes.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/026-spamfilter-level/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/spamfilter-level.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/MailUserSpamFilter.yaml          # + policy_id
api/components/schemas/MailDomain.yaml                  # + spamfilter_policy_id
api/modules/mail/user-spamfilter.yaml                   # description
api/modules/mail/domains.yaml                           # description
api/modules/mail/spamfilter-policies.yaml               # readable levels note
app/Services/SpamfilterUserService.php                  # NEW read + upsert
app/Http/Requests/Concerns/ValidatesSpamfilterPolicy.php  # NEW readable policy rule
app/Http/Requests/UpdateMailUserSpamFilterRequest.php   # policy_id
app/Http/Requests/StoreMailDomainRequest.php            # spamfilter_policy_id
app/Http/Requests/UpdateMailDomainRequest.php           # spamfilter_policy_id
app/Http/Requests/MailDomainRequest.php                 # payload() drops spamfilter_policy_id
app/Http/Controllers/Api/V1/MailUserSpamFilterController.php  # view + write
app/Http/Controllers/Api/V1/MailDomainController.php    # present + write
tests/Support/SystemSchema.php                          # spamfilter_users columns
tests/Feature/SpamfilterLevelApiTest.php                # NEW US1–US3
README.md
```

**Structure Decision**: the companion-row logic sits in one service used by both controllers, so mailbox and domain
follow the same upsert rules; policy validation is a request concern shared by the three form requests.

## Legacy Research (Phase 0 focus)

See research.md: R1 storage and insert defaults, R2 field placement, R3 selectable policies, R4 permission, R5 domain
row timing, R6 interplay with 019/024/025, R7 test schemas.

## Complexity Tracking

None.
