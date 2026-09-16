# Feature Specification: Database User Usage and Unlink Safety

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: sites (database users)  
**Input**: User description: "Database user usage and unlink safety: a database user in use by a database can be deleted, leaving the database broken; legacy blocks or warns (verify database_user_del.php / the form). Add the usage info (e.g. count/list of databases referencing the user, restricted to what the key may read) and refuse the delete like legacy with a 023 problem type."

## Context

A database in ISPConfig points at a database user for its credentials, and optionally at a second one for read-only
access. Legacy refuses to delete a user that is still referenced: `database_user_del.php::onBeforeDelete()` looks for
any `web_database` row whose `database_user_id` **or** `database_ro_user_id` is this user and stops with
*"The user cannot be deleted. It is still being used by a database."*

The API deletes it unconditionally. Worse, the contract currently documents the opposite of legacy — *"Databases
still referencing this user lose their credentials — reassign them first"* — so an integration following the
documentation can break a customer's working database with one call, and the database is left pointing at a user
that no longer exists.

A panel also has no way to warn beforehand: the database-user resource says nothing about how many databases use it,
so a "Delete" button either hides the consequence or has to fetch and scan every database of the account first.

This feature reports the usage on the resource and refuses the delete exactly as legacy does.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A user still in use cannot be deleted (Priority: P1)

A customer deletes a database user that one of their databases still uses. The request is refused with an
explanation, and the database keeps working.

**Why this priority**: it prevents silent breakage of a live database; it is also what ISPConfig's own interface does.

**Independent Test**: create a database bound to a user, delete the user, and check the refusal and that both rows
are untouched.

**Acceptance Scenarios**:

1. **Given** a database whose `database_user_id` is this user, **When** `DELETE /sites/database-users/{id}`,
   **Then** 409 with problem type `resource-in-use`, the legacy message, and nothing is written.
2. **Given** a database whose `database_ro_user_id` is this user, **When** the same call, **Then** the same refusal —
   legacy checks both columns.
3. **Given** the databases are reassigned or deleted first, **When** the same call, **Then** 204 as before.
4. **Given** a user no database references, **When** the same call, **Then** 204 as before.

---

### User Story 2 - A panel can warn before the click (Priority: P1)

The database user list shows "used by 2 databases", so a customer sees why a user cannot be removed before trying.

**Why this priority**: without it the refusal of US1 is the first time the customer learns anything, and a panel
would have to read every database to count.

**Independent Test**: read a database user and its list entry with a client key and compare the reported count with
the databases the key can see.

**Acceptance Scenarios**:

1. **Given** two databases using the user (one as owner, one read-only), **When** `GET /sites/database-users/{id}`,
   **Then** `databases_in_use` is 2.
2. **Given** none, **When** the same call, **Then** `databases_in_use` is 0.
3. **Given** a list request, **When** `GET /sites/database-users`, **Then** every entry carries the same field
   without one query per row.
4. **Given** a client key, **When** another client's database uses the same user (only possible for an administrator
   assignment), **Then** the count includes only databases the key may read, and the delete refusal still applies.

---

### User Story 3 - Administrator keys are protected too (Priority: P2)

The guard is not a permission check: an administrator deleting an in-use user would break the same database.

**Why this priority**: legacy refuses in the form for every user type; an unchecked administrator path is the one
provisioning integrations use.

**Independent Test**: repeat US1 with an administrator key.

**Acceptance Scenarios**:

1. **Given** an in-use user, **When** an administrator key deletes it, **Then** the same 409 refusal.
2. **Given** an administrator key, **When** it reads the resource, **Then** `databases_in_use` counts every database,
   since an administrator may read them all.

---

### Edge Cases

- A database referencing the user twice (owner and read-only on the same row) counts once.
- Deleting the *database* first and then the user succeeds — the guard reads the current state.
- The count is a plain number, not a list of names: a client key must not learn about databases it cannot read, and
  the field stays cheap on list pages.
- The guard runs before any write, so a refusal produces no `sys_datalog` row.
- Databases on another server are counted like any other; the reference is what matters, not placement.

## Requirements *(mandatory)*

- **FR-001**: `DELETE /sites/database-users/{id}` MUST refuse while any database the key may read references the user
  as `database_user_id` or `database_ro_user_id`, with 409, problem type `resource-in-use` and the legacy message.
- **FR-002**: The refusal MUST apply to every key type, including administrator keys.
- **FR-003**: Nothing MUST be written when a delete is refused.
- **FR-004**: The database user resource MUST carry `databases_in_use` — the number of databases the key may read
  that reference it — on both the single and the list representation.
- **FR-005**: The list MUST compute the counts without a query per row.
- **FR-006**: `resource-in-use` MUST be documented in `docs/problems.md` as a stable problem type with its status
  (409) and meaning.
- **FR-007**: The contract MUST be corrected: the delete description currently states the opposite of the enforced
  behaviour.

### Key Entities

- **Database user usage**: the number of `web_database` rows visible to the key that name this user in either
  credential column. Derived per request; never stored.

## Success Criteria *(mandatory)*

- **SC-001**: A database user that a database depends on can no longer be deleted through the API by any key type.
- **SC-002**: A panel can show the dependency count without reading the account's databases itself.
- **SC-003**: The refusal is machine-readable by problem type, so a panel maps it without reading English text.
- **SC-004**: Deleting an unused user keeps working exactly as before.

## Assumptions

Owner-delegated decisions (2026-09-16), recorded per the owner's standing instruction to apply recommendations:

- **409 with a new `resource-in-use` type**, following the existing in-use refusals of this API (in-use directive
  snippets and client templates already answer 409 through `ConflictHttpException`). Spec 023's types gain one entry;
  no existing type fits a dependency conflict.
- **A count, not a list.** `databases_in_use` avoids leaking database names to a key that cannot read them and keeps
  list pages cheap. A panel that wants names can filter the databases it already reads.
- **The count is scoped to what the key may read**, like every other list in this API; the delete guard uses the same
  scope, so a client key is never refused because of a database it cannot see.
- **Administrator keys are refused too** — this is data integrity, not a permission, and legacy's form refuses for
  every user type.
- **Out of scope**: automatically reassigning or clearing the references (legacy's `onAfterDelete` nulls them only
  after its own guard has passed), PostgreSQL-specific users, and a "force" parameter.
