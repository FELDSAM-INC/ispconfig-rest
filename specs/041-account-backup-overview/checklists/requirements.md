# Requirements Checklist: Account-Wide Backup Overview

Quality gate for `spec.md` before planning. Each item is checked against the written specification, not
against the implementation.

## Scope and intent

- [x] The problem is stated in consumer terms (a list page cannot afford one call per website) rather than
      as an implementation wish.
- [x] The feature is read-only and says so explicitly.
- [x] What the feature deliberately does **not** cover (full backup list, jobs, settings) is named in the
      Assumptions.
- [x] The module the endpoint belongs to, and why, is justified (`me` describes the calling account).

## Requirement quality

- [x] Every functional requirement is testable without reading the code (FR-001 … FR-009).
- [x] No requirement prescribes an internal class or method name; parity references name legacy files, not
      API internals.
- [x] Pagination, ordering and the empty case are specified, not left to the implementation.
- [x] The plan gate and the admin `client_id` rule are specified as behaviour, with the status codes the
      other account endpoints already use.
- [x] The query-count requirement (FR-008) is stated as an observable property with a matching success
      criterion (SC-002).

## Parity and safety

- [x] Visibility is defined by reference to the existing per-website rule, so the overview cannot widen it.
- [x] The legacy source of the plan gate is named (`web_vhost_domain.tform.php`, ported in
      `RequireBackupAccess`).
- [x] Datalog impact is stated: none — no `sys_datalog`, no `sys_remoteaction`.
- [x] The response is explicitly limited to data the same key can already obtain per website (FR-009,
      SC-004).
- [x] No file system paths or server internals are exposed.

## Testability

- [x] Each user story carries an independent test that seeds two tenants and asserts isolation.
- [x] Edge cases are listed with the expected answer (foreign server backups, deleted websites, many
      websites, suspended account).
- [x] Success criteria are measurable: one request (SC-001), constant query count (SC-002), field equality
      with the per-website list (SC-003), no widened visibility (SC-004).
- [x] The contract table names every status code the endpoint can return.

## Consistency with shipped specs

- [x] Backup representation is delegated to spec 018 rather than redefined.
- [x] The refusal type (`feature-not-allowed`) and its member (`feature`) match spec 023.
- [x] Scoping follows specs 011/024; the account resolution follows the `client_id` rule of specs 021/031/036.
- [x] Locked accounts (spec 019) are addressed: reads stay readable.

**Result**: the specification is complete and consistent; planning may proceed.
