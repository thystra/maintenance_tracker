# Architecture

## Product boundary

Maintenance Tracker is a classic Nextcloud PHP app. Nextcloud owns
authentication, sessions, database connections, user files, and the web shell.
The app owns maintenance-domain data and exposes it through a versioned OCS API
used by the Vue desktop management client and a future offline-first Vue mobile client/PWA, with Capacitor packaging planned when native Android/iOS capabilities are needed.

```text
Nextcloud web UI ─┐
                  ├─ OCS API ─ domain services ─ PostgreSQL/MariaDB/SQLite
Mobile/PWA client ─┘                    │
                                      ├─ Nextcloud Files (receipts/photos)
                                      ├─ Calendar projection
                                      └─ Activity/notifications
```

nginx has no app-specific endpoints. Requests continue through Nextcloud so its
authentication, CSRF handling, rate limiting, logging, and CSP remain in force.

## Runtime baseline

- Nextcloud 34 only for the initial release.
- PHP 8.2–8.5 syntax, tested on 8.2 and 8.5. PHP 8.5 is the production target.
- Only public `OCP` APIs. Private `OC` and `OCA\DAV` classes are not app
  dependencies.
- Vue 3 and current `@nextcloud/vue` packages for the web client.
- Portable migrations and queries for PostgreSQL, MariaDB/MySQL, and SQLite.

The platform choices follow the
[Nextcloud 34 requirements](https://docs.nextcloud.com/server/stable/admin_manual/installation/system_requirements.html),
[app-store rules](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html),
and [OCS guidance](https://docs.nextcloud.com/server/stable/developer_manual/basics/controllers.html).

## Application layers

`Controller`
: Translates authenticated OCS requests and domain failures. It never accepts an
  owner user ID from a client.

`Service`
: Applies authorization context, validation, transactions, recurrence rules,
  revision checks, and report calculations.

`Db`
: Nextcloud `Entity`/`QBMapper` classes. Every resource lookup is scoped to an
  already-authorized workspace.

`Integration`
: Narrow interfaces for Calendar, Files, Activity, notifications, exports, and
  profile imports. Domain logic must remain testable without those services.

`Vue`
: Treats OCS as the only backend contract. It does not access Nextcloud internal
  JavaScript globals or database concepts.

## Ownership and sharing

Each user receives one personal workspace on first use. Workspace memberships use
four stable roles: **Owner**, **Manager**, **Contributor**, and **Viewer**. A
legacy persisted `editor` role is migrated and runtime-normalized to `manager`.

Authorization is capability-based rather than role-rank-based. The role name is
only a bundle selector. Manager is an explicit bundle: it can manage current
inventory and read workspace membership/audit history, but it cannot administer
membership. Contributor and Viewer remain read-only on inventory configuration.
For meters/readings, Contributor may record new readings but cannot configure a
meter or correct historical readings; Viewer remains read-only. Later activity
capabilities can extend field-entry behavior without changing existing role meaning.

The authorization catalog also reserves future capability names for maintenance
definitions, activities, evidence, checkout, retention, public report shares,
external submissions, workspace settings, and workspace deletion. Reserved
capabilities are explicitly unimplemented and authorize nothing until their
subsystem lands.

Knowing a workspace or asset UUID never grants access. Controllers authenticate
the Nextcloud user, request a capability from `WorkspaceService`, and only then
allow mappers to access rows constrained by the workspace's internal ID.
Capability-authorized writes acquire the workspace-row write lock. Membership
mutations additionally lock lifecycle state for actor and target users in
stable UID order so account deletion/UID reuse cannot race a grant or role
change.

## Meters and immutable readings

v0.1.4 introduces asset/component meter configuration separately from immutable
observations. Initial meter dimensions are distance, runtime, and usage count.
Canonical persistence uses integer millimetres, seconds, and counts, capped at the JavaScript JSON safe-integer maximum, while each
reading also retains the normalized original value and unit supplied by the
client. This gives future scheduling logic deterministic values without erasing
what a user/device actually reported.

Meters may be marked monotonic. Writes validate a candidate against the nearest
effective reading before and after its observation timestamp, so historical
insertion cannot make an odometer/Hobbs series internally inconsistent. A
correction is another immutable reading linked by `supersedes_id`; it never
updates or deletes the earlier observation. `reading.correct` is intentionally a
stronger capability than `reading.create`.

Meter/read mutations use the same workspace serialization, client-generated UUID
idempotency, change journal, audit stream, and account-lifecycle purge invariants
as the existing domain foundation.

## Offline synchronization

Mutable entities use:

- stable UUIDs at the API boundary;
- integer revisions for optimistic concurrency;
- `created_at`, `updated_at`, and nullable `deleted_at`;
- an append-only `maint_changes` sequence.

The mobile synchronization contract will be completed before packaged mobile
clients depend on it:

1. A Vue/PWA client writes a mutation to durable local storage/outbox first.
2. Client-generated UUIDs and mutation IDs make retries safe.
3. The server rejects stale revisions with `412 Precondition Failed`.
4. The client pulls ordered changes after an opaque cursor.
5. Applying a page and advancing its durable cursor happen atomically.
6. Tombstones are retained long enough for offline devices, with a documented
   full-resync path after expiry.
7. A portable work bundle is an alternate transport through the same canonical
   validation/idempotency ingest path.

The current change table is foundation work, not yet a public sync endpoint.

## Profiles

Profiles are untrusted, data-only JSON documents validated against a versioned
schema. They cannot include PHP, JavaScript, templates, executable expressions,
or credentials.

Installing a profile materializes a snapshot of its components, structured information fields, work definitions, meters, and parts into an asset. A later profile revision produces an explicit diff. It never silently rewrites user-adjusted `schedule` policies or re-enables suppressed components. Profiles may define their own display groups (for example Engine, Transmission, Cooling, or HVAC); those groups are data, not application constants.

Profile provenance includes an ID, semantic version, data license, source URL,
and content hash. Generic first-party profiles should use CC0 where possible.

## Scheduling

Scheduling is a property of a common **work definition**, not a separate record
type. The canonical field name is `schedule`.

```text
schedule: none
```

means unscheduled/ad-hoc work. Any non-`none` schedule policy denotes scheduled
maintenance. This gives clients a built-in filter without splitting repairs and
recurring maintenance into different definition types:

```text
scheduled   = schedule != none
unscheduled = schedule == none
```

A non-`none` schedule may use calendar time, distance, runtime/engine hours,
usage counts, condition measurements, or a reviewed combination such as `ANY`
("six months or 5,000 miles, whichever comes first"). Month/year intervals stay
calendar units; they are not approximated as seconds. Unscheduled definitions
cover failures and ad-hoc work such as a turbocharger or transmission repair.

A future periodic Nextcloud `TimedJob` will reconcile indexed due projections,
calendar events, and notifications. The design avoids one background job per
work definition. Production requires system cron.

## Calendar integration

Maintenance schedules are authoritative. Calendar events are an idempotent
projection and contain a stable task/occurrence UID.

Nextcloud 34's public calendar API can list a user's calendars and create an
event in calendars implementing `ICreateFromString`. It does not expose a clear
public server API for creating a persistent CalDAV calendar or a complete
update/delete lifecycle. Therefore the MVP will:

1. ask the user to select an existing writable calendar;
2. explain how to create a “Maintenance” calendar when needed;
3. remain fully functional when Calendar/DAV is disabled;
4. integration-test event reconciliation on Nextcloud 34;
5. avoid private `OCA\DAV` classes.

Calendar titles default to neutral text such as “Maintenance due,” because a
shared calendar could otherwise expose health-equipment details.

## Files and evidence

Evidence bytes (photos, video, receipts, invoices, documents, and other supported files) live in Nextcloud Files, not database BLOBs or the app directory. A user-visible Maintenance Tracker folder is the default storage surface.

The database stores a verified file ID, owner/workspace association, MIME type,
hash, and last-known path. Every read or unlink revalidates file ownership.
Nextcloud then supplies quota enforcement, storage backends, versions, trash,
sharing, previews, and WebDAV access.

Mobile clients will upload bytes through Nextcloud Files/WebDAV and associate the verified file through OCS. Ordinary images use streaming `PUT`; large/resumable uploads can use Nextcloud's chunk upload v2.

## Audit trail

`maint_audit` is an append-only workspace event stream distinct from the sync
change journal. It records significant implemented mutations with an
independently versioned event vocabulary, actor UID, subject identity/revision,
timestamp, level, and tightly bounded structured metadata. It does not copy
free-form maintenance notes, receipt/invoice contents, or document bodies.

Current event families cover asset/category/component/specification,
relationship/assignment, and workspace membership mutations. Audit persistence
has no update/delete API. Deleting a member removes authorization membership but
retains that actor UID in audit history for shared work they already performed.
Deleting the owner of a personal workspace purges that workspace and its audit
rows with the rest of the personal data.

## Reports

Reports are deterministic projections over immutable or audited event records.

- Money is stored in integer minor units plus ISO 4217 currency.
- Measurements use exact integer base units and retain the entered unit.
- No automatic cross-currency TCO total is shown without an explicit exchange
  rate source.
- “Tracked cost” is used until acquisition, financing, depreciation, disposal,
  and mileage coverage are sufficient to call a result TCO.
- Mileage deduction reports preserve the rate revision and included trip IDs.
- Standard-mileage estimates stay separate from actual-expense/TCO analysis.

Tax output is recordkeeping support, not tax advice or an automatic
determination of deductibility.

## Release boundary

A Nextcloud App Store artifact is a signed `.tar.gz` containing exactly one
top-level `maintenance_tracker/` directory. Compiled `js/` and `css/` ship;
tests, source, lock/build tooling, and CI configuration do not. The final staged
tree must be integrity-signed before the archive is created.
