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

Each user receives one private workspace on first use. Assets belong to the
workspace, and membership carries `owner`, `editor`, or `viewer` authorization.
Only the owner path is exposed in the foundation UI, but persisting workspace
membership now prevents a disruptive rewrite when household sharing is added.

Knowing a workspace or asset UUID never grants access. Controllers authenticate
the Nextcloud user; `WorkspaceService` verifies membership and role; mappers
then constrain the query by the workspace's internal ID.

Editor/owner mutations also acquire a database write lock by changing a dedicated random lock token on the authorized workspace row inside the request transaction. This guarantees a physical row update even for same-second requests and serializes cross-record invariants across different member accounts; the per-user lifecycle lock alone is not sufficient for a future shared workspace. Read-only viewer operations do not take the workspace write lock.

## Offline synchronization

Mutable entities use:

- stable UUIDs at the API boundary;
- integer revisions for optimistic concurrency;
- `created_at`, `updated_at`, and nullable `deleted_at`;
- an append-only `maint_changes` sequence.

The Android protocol will be completed before its first build:

1. A client writes a mutation to a durable local outbox.
2. Client-generated UUIDs and mutation IDs make retries safe.
3. The server rejects stale revisions with `412 Precondition Failed`.
4. The client pulls ordered changes after an opaque cursor.
5. Applying a change page and advancing its Room cursor happen atomically.
6. Tombstones are retained long enough for offline devices, with a documented
   full-resync path after expiry.

The current change table is foundation work, not yet a public sync endpoint.

## Profiles

Profiles are untrusted, data-only JSON documents validated against a versioned
schema. They cannot include PHP, JavaScript, templates, executable expressions,
or credentials.

Installing a profile materializes a snapshot of its components, plans, triggers,
and parts into an asset. A later profile revision produces an explicit diff.
It never silently rewrites user-adjusted schedules or re-enables suppressed
components.

Profile provenance includes an ID, semantic version, data license, source URL,
and content hash. Generic first-party profiles should use CC0 where possible.

## Scheduling

The maintenance plan is the source of truth. A plan can have multiple triggers:

- calendar duration (`day`, `week`, `month`, or `year`);
- distance;
- runtime/engine hours;
- usage count.

The common combination is `ANY`: six months or 5,000 miles, whichever becomes
due first. Month/year periods remain calendar units; they are never approximated
as seconds.

One periodic Nextcloud `TimedJob` will reconcile indexed due projections,
calendar events, and notifications. The design avoids one background job per
task. Production requires system cron; Nextcloud describes AJAX cron as the
least reliable option in its
[background-job documentation](https://docs.nextcloud.com/server/stable/admin_manual/configuration_server/background_jobs_configuration.html).

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

## Files and receipts

Receipt and photo bytes live in Nextcloud Files, not database BLOBs or the app
directory. The default is a user-visible folder such as
`Maintenance Tracker/Receipts`.

The database stores a verified file ID, owner/workspace association, MIME type,
hash, and last-known path. Every read or unlink revalidates file ownership.
Nextcloud then supplies quota enforcement, storage backends, versions, trash,
sharing, previews, and WebDAV access.

Android will upload bytes by WebDAV and associate the verified file through OCS.
Ordinary images use streaming `PUT`; large/resumable uploads can use Nextcloud's
chunk upload v2.

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
