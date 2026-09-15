# Implementation Plan: Administration and File-Transfer Links for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/036-admin-links/spec.md`

## Summary

- New read-only `GET /me/hosting-links` (`MeHostingLinksController`), with the spec 025/031 target resolution
  (`AccountCapabilitiesService::resolveTarget()`, `ReadsAccountQuery`).
- New `App\Services\HostingLinkService`: reads `phpmyadmin_url`, `dblist_phpmyadmin_link` and `webftp_url` from the
  `[sites]` section through `SitesConfigService`, and composes the account's database servers with the spec 031 rule
  (assigned `db` servers, then non-mirror database servers hosting the client's `web_database` rows).
- Contract: `api/modules/me/hosting-links.yaml`, schemas `HostingLinks`, `DatabaseAdministrationLink`,
  `HostingLinkServer`, `FileTransferLink`.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — reads `client`, `server`, `web_database`, `sys_group`, `sys_ini`; no writes
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1207 on `760276b`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: a bounded number of lookups per request (client row, assignment columns, hosting servers,
server names, `sys_ini`)
**Constraints**: expose exactly two settings; read-only
**Scale/Scope**: 1 endpoint, 1 service, 4 schemas

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: the module file and four schemas are written before the PHP (contracts/hosting-links.md).
- [x] **Datalog-only writes (II)**: no writes; the `sys_ini` blob is read through the existing documented exception
      (`SitesConfigService`).
- [x] **Legacy parity (III)**: placeholders and the link gate cite `database_phpmyadmin.php:63-66`,
      `database_list.php:72` and `ftp_user_list.php:58-60`; server composition follows spec 031 (research R2, R4, R5).
- [x] **Route discipline (IV)**: static `me/hosting-links` in `routes/api/me.php`, outside the admin gate like the
      other `/me` reads.
- [x] **HTTP contract (V)**: 200 / 400 / 401 / 404 / 422 problem+json, identical to `/me/mail-settings`.
- [x] **Tests required**: one feature test class covering both links, the server composition, the target rules and
      the exposure boundary.
- [x] **No schema changes**: no migrations.

## Project Structure

### Documentation (this feature)

```
specs/036-admin-links/
├── spec.md
├── checklists/requirements.md
├── plan.md
├── research.md
├── data-model.md
├── contracts/hosting-links.md
├── quickstart.md
└── tasks.md
```

### Source (repository root)

```
api/
├── components/schemas/HostingLinks.yaml                 # new
├── components/schemas/DatabaseAdministrationLink.yaml   # new
├── components/schemas/HostingLinkServer.yaml            # new
├── components/schemas/FileTransferLink.yaml             # new
├── components/schemas/_index.yaml                       # register
├── modules/me/hosting-links.yaml                        # new
├── modules/me/_index.yaml                               # register
└── openapi.yaml                                         # register
app/
├── Services/HostingLinkService.php                      # new
└── Http/Controllers/Api/V1/MeHostingLinksController.php # new
routes/api/me.php                                        # route
README.md                                                # endpoint note
tests/Feature/MeHostingLinksApiTest.php                  # new
```

## Phases

1. **Phase 0 — research** (done): R1–R6 in research.md.
2. **Phase 1 — contract**: schemas and module file, then `SwaggerSpecServerTest` proves the spec still parses.
3. **Phase 2 — tests first**: `MeHostingLinksApiTest`, failing.
4. **Phase 3 — implementation**: `HostingLinkService`, `MeHostingLinksController`, route.
5. **Phase 4 — docs**: README paragraph next to the other `/me` endpoints.
6. **Phase 5 — verification**: full suite in Docker, Pint on changed files, deploy to isp-test, quickstart with a
   temporary client, cleanup.

## Complexity Tracking

No constitution deviation requires justification. The judgement calls are recorded as owner-delegated decisions in
the spec: the separate endpoint, the unresolved `[DATABASENAME]`, the `available` gate following the interface's own
switch, and the verbatim file-transfer address.
