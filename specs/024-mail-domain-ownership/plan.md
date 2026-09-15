# Implementation Plan: Scoped Parent References (Mail Domain Ownership and Siblings)

**Branch**: `024-mail-domain-ownership` (committed directly to `main`) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/024-mail-domain-ownership/spec.md`

## Summary

Non-admin writes that name another row by value (a mail domain in an address, a destination mailbox, a parent website,
database user, web folder, DNS zone or spam filter user) currently check only that the row exists. This feature applies
the spec 011 read predicate (`AuthScope::applyReadPredicate`, legacy `getAuthSQL('r')`) to every such lookup, so a
foreign row behaves exactly like a missing one. No endpoints, schemas or status codes change; admin keys are untouched.

Technical approach:

1. A request concern `ScopesReferences` wraps `Rule::exists(...)` with a `using` callback that adds the read predicate
   (no-op for admin), so 422 messages stay byte-identical to the nonexistent case.
2. Controller/service lookups of mail domains and spam filter users add the same predicate before `first()` /
   `exists()`, keeping the existing 400/404 bodies.
3. Alias destinations get a scoped mailbox rule (non-admin, type `alias` only), reusing the query shape from 016's
   fetchmail destination rule.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` (no schema change; no new writes)  
**Testing**: PHPUnit feature tests with `TenantFixtures` (admin, reseller, clientA, clientB)  
**Target Platform**: Linux server alongside ISPConfig 3.3  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: one extra predicate on existing single-row lookups; no additional queries  
**Constraints**: legacy parity (`getAuthSQL('r')`), no information leak (foreign = missing), admin unchanged  
**Scale/Scope**: 4 mail controllers/services, 1 mail request base, 14 sites/DNS request classes, 3–4 new test classes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Check | Result |
|-----------|-------|--------|
| I. Spec-First | No new paths or shapes; affected operation descriptions in `api/modules/**` updated first to document the scoped checks | PASS |
| II. Datalog-Only Writes | No new writes; rejections happen before any save, so no datalog rows | PASS |
| III. Legacy Parity | Mirrors `getAuthSQL('r')` lookups in mail/sites/dns edit pages (file:line in spec + research); two documented deviations (datasource-only checks become validation; admin alias destinations unchanged) | PASS (deviations recorded) |
| IV. Layered Flow & Route Discipline | Validation in Form Requests via a concern; domain lookups stay in their service/controller helpers; no route changes | PASS |
| V. HTTP Contract | Existing 400/404/422 problem+json bodies reused verbatim | PASS |
| Testing (REQUIRED) | Two-tenant feature tests written first and shown failing; full suite green | PASS |

Re-check after design: unchanged — PASS.

## Project Structure

### Documentation (this feature)

```text
specs/024-mail-domain-ownership/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/reference-checks.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
app/Http/Requests/Concerns/ScopesReferences.php          # new: readable(DatabaseRule), readableQuery(table)
app/Services/MailUserService.php                         # resolveMailDomain scoped
app/Http/Controllers/Api/V1/MailForwardingController.php # resolveSourceDomain scoped
app/Http/Controllers/Api/V1/MailAliasDomainController.php# resolveMailDomain scoped
app/Http/Controllers/Api/V1/SpamfilterWBListController.php # guardRidReference scoped
app/Http/Requests/MailForwardingRequest.php              # aliasDestinationsRule (non-admin, type alias)
app/Http/Requests/StoreMailForwardingRequest.php         # uses aliasDestinationsRule
app/Http/Requests/UpdateMailForwardingRequest.php        # uses aliasDestinationsRule with stored type
app/Http/Requests/MailGetRequest.php                     # readableMailboxQuery moved onto the concern (no behaviour change)
app/Http/Requests/{Store,Update}WebDomainRequest.php     # parent_domain_id
app/Http/Requests/{Store,Update}WebChildDomainRequest.php
app/Http/Requests/{Store,Update}FtpUserRequest.php
app/Http/Requests/{Store,Update}ShellUserRequest.php
app/Http/Requests/StoreWebdavUserRequest.php
app/Http/Requests/{Store,Update}CronJobRequest.php
app/Http/Requests/StoreWebFolderRequest.php
app/Http/Requests/StoreWebFolderUserRequest.php          # web_folder_id
app/Http/Requests/{Store,Update}WebDatabaseRequest.php   # parent_domain_id, database_user_id, database_ro_user_id
app/Http/Requests/{Store,Update}DnsRecordRequest.php     # zone
api/modules/{mail,sites,dns}/*.yaml                       # description notes only

tests/Feature/ScopedReferenceMailTest.php
tests/Feature/ScopedReferenceSitesTest.php
tests/Feature/ScopedReferenceDnsTest.php
```

**Structure Decision**: validation-level checks live in Form Requests through one concern so every `Rule::exists` for a
reference reads the same way; name-based mail domain lookups stay where they are (service/controller helpers) and gain
the predicate there, keeping the contract's 400 responses. No routes change, so ordering is unaffected.

## Legacy Research (Phase 0 focus)

See [research.md](research.md): R1 predicate source, R2 error parity, R3 alias destinations, R4 datasource-only
references, R5 update paths, R6 audit results and non-affected resources.

## Complexity Tracking

No constitution violations to justify.
