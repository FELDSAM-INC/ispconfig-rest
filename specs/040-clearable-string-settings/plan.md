# Implementation Plan: Clearable String Settings in the System Configuration

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/040-clearable-string-settings/spec.md`

## Summary

- `SystemConfigService::normalizeInput()` maps `null` to `''` for fields whose type is `string`, so an empty value
  reaches the rules as a string and clears the setting (research R1, R3).
- Non-string types keep their validation, so `web_php_options` — the only `NOTEMPTY` field in the legacy form —
  still refuses an empty value (R2).
- The contract states that an empty string clears a text setting and which setting refuses it.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — `sys_ini` blob through the existing read-merge-write exception
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1244 on `bfa2e0a`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: unchanged — one blob read and one write per request
**Constraints**: unexposed keys must stay byte-identical; no existing expectation may change
**Scale/Scope**: 1 service method, 2 routes, contract wording

## Constitution Check

- [x] **Spec-first (I)**: the contract wording lands before the code.
- [x] **Datalog-only writes (II)**: `sys_ini` is the documented read-merge-write exception; unchanged here.
- [x] **Legacy parity (III)**: the blankable/required split comes from the legacy form (research R2), and a cleared
      setting is stored as `key=`, exactly as ISPConfig's own save writes it (R4).
- [x] **Route discipline (IV)**: no new route.
- [x] **HTTP contract (V)**: unchanged; refusals stay ordinary 422 validation problems.
- [x] **Tests required**: the four named settings cleared, `web_php_options` and a non-string setting still refused,
      blob preservation, and both routes.
- [x] **No schema changes**: no migrations.

## Project Structure

```
app/Services/SystemConfigService.php          # null -> '' for string fields in normalizeInput()
api/modules/system/*-config.yaml              # "an empty string clears this setting" + the refused one
api/components/schemas/System*Config.yaml     # per-field note where it helps
tests/Feature/SystemConfigClearableTest.php   # new
tests/Feature/SystemConfigApiTest.php         # unchanged expectations must keep passing
```

## Phases

1. **Phase 0 — research** (done): R1–R6.
2. **Phase 1 — contract**: the clearing rule in the section endpoints' descriptions.
3. **Phase 2 — tests first**: the new class, failing.
4. **Phase 3 — implementation**: the normalization change.
5. **Phase 4 — verification**: full suite, Pint, deploy, live check on isp-test (set and clear each of the four
   settings, restoring the original values and proving the blob byte-identical), cleanup.

## Complexity Tracking

| Deviation | Why | Alternative rejected |
|---|---|---|
| Coercing `null` to `''` instead of adding `nullable` rules | one place, keeps the rules truthful and the stored value a string | `nullable` on 15+ fields: the blob writer would then have to translate `null`, and every future string field would need the same flag |
