# Nextcloud App Engineering Guidance

## Purpose

This document captures reusable engineering lessons for Nextcloud application
development. It is intentionally broader than Maintenance Tracker so the same
guidance can be promoted into centralized agent/development documentation.
Project-specific architecture, current milestones, host inventory, deployment
credentials, and production paths belong elsewhere.

## 1. Treat Nextcloud as the platform boundary

A normal Nextcloud app should use the platform rather than recreate it.
Nextcloud should remain authoritative for authentication, sessions, database
connections, users/groups, Files, DAV services, background-job scheduling,
Activity/notifications, CSP, and request routing where those facilities apply.

Prefer public `OCP` interfaces and documented extension points. Private `OC` or
internal app namespaces may change without compatibility guarantees and should
not become accidental long-term dependencies.

Do not add reverse-proxy/web-server routes merely to bypass Nextcloud's request
pipeline. An app-specific fast path can also bypass authentication, CSRF, CSP,
rate limiting, logging, or upgrade behavior that the framework normally owns.

## 2. Keep server identity authoritative

When Nextcloud authentication identifies the current user, do not also accept
an owner/user ID from the client for the same decision. Derive identity from the
session/app password and authorize the requested resource server-side.

Opaque UUIDs are useful stable identifiers, particularly for offline clients,
but they are not authorization secrets. Resource lookup must remain scoped by
the authenticated user's workspace/permission boundary.

User deletion and external-identity detach/reassign events deserve explicit
tests. A reused textual UID must not inherit data that belonged to a previous
identity. If lazy per-user initialization can race cleanup, serialize those
operations at the database/authority boundary.

## 3. OCS is an application contract

Use versioned OCS routes for application APIs that need to serve web and mobile
clients. Keep response/error semantics deliberate rather than exposing mapper
or database behavior directly.

For an offline-capable API, define synchronization semantics before building the
mobile client:

- stable object identifiers;
- monotonic revisions or another explicit concurrency contract;
- idempotent creates/mutations;
- tombstones and a retention/full-resync policy;
- bounded pagination;
- opaque synchronization cursors;
- conflict responses that provide enough current state for reconciliation;
- explicit capability/API-version discovery.

An API that works for a single online web page is not automatically a safe
offline synchronization protocol.

## 4. Migrations are durable history

Treat Nextcloud migrations as product behavior, not editable setup scripts.
After a migration has shipped or has been applied to persistent installations,
new schema state belongs in a new migration.

Migration work should be:

- deterministic and restart-safe where the framework can retry;
- explicit about destructive changes;
- portable across every database the app declares;
- tested both as a fresh install and as an upgrade once upgrade paths exist;
- accompanied by focused tests for data transformations or invariants.

Avoid database-specific SQL when Nextcloud/Doctrine schema and query APIs provide
a portable implementation. Pay special attention to behavior that differs among
PostgreSQL, MariaDB/MySQL, and SQLite: autoincrement/sequence behavior, unsigned
integers, null/unique handling, collations, boolean representation, date/time
handling, locking, and transaction semantics.

SQLite is a useful fast test target, not proof that production PostgreSQL or
MariaDB behavior is correct.

## 5. Use disposable runtime integration tests

Unit tests do not prove that a Nextcloud app registers, migrates, routes, or
interacts correctly with the server runtime. Maintain a disposable integration
harness that can:

1. start a clean supported Nextcloud version;
2. install/enable the app;
3. exercise representative authenticated OCS behavior;
4. verify migrations and authorization boundaries;
5. test important user-lifecycle hooks;
6. inspect Nextcloud logs for app-level errors;
7. destroy all test state afterward.

Exercise the production database family before deployment. Before a stable
release, exercise every database the metadata claims to support.

Never point a destructive test harness at development or production data.

## 6. Respect remote Docker-runner boundaries

CI systems may execute the job in one container while a separate Docker daemon
creates integration containers. A Docker client can reach that daemon without
sharing the job container's filesystem.

Therefore, a command such as:

```text
docker run -v "$PWD:/app" ...
```

is not portable to a remote/isolated Docker daemon: `$PWD` is interpreted by the
daemon host, not by the client container. Prefer a staged runtime tree sent via
`docker cp`, a Docker build context, or another explicit transport supported by
the CI architecture.

Do not expose the host Docker socket merely to make bind mounts convenient.

## 7. Frontend source and generated runtime assets are different authorities

Nextcloud apps commonly compile TypeScript/Vue/Sass into runtime JavaScript and
CSS that ships with the PHP app. Make the boundary explicit:

- canonical editable source lives in the frontend source tree;
- generated assets live in documented runtime paths;
- clean-build before generation so stale chunks cannot survive;
- CI rebuilds from the lockfile and verifies the committed/generated result;
- packaging includes generated runtime assets but excludes contributor-only
  source/tooling when that is the distribution contract.

If generated assets are tracked, a source change is not complete when `npm run
build` produces an uncommitted diff.

## 8. Keep package metadata synchronized

Version/platform identity is often repeated across Nextcloud app metadata,
application constants, package-manager files, capability responses,
documentation, tags, and release artifacts.

Define which locations are authoritative and validate consistency in CI. Check
at least:

- app ID and namespace;
- application version;
- supported Nextcloud range;
- PHP range;
- API/capability version where exposed;
- package/archive root directory name;
- repository/support URLs.

Do not widen compatibility metadata until the new platform is actually tested.

## 9. Package from a deliberate runtime boundary

A source checkout is usually larger than an installable Nextcloud app. Keep an
explicit package boundary using `.gitattributes`, `.nextcloudignore`, or a
reviewed packaging script.

Exclude contributor-only material such as CI workflows, tests, source maps when
not intended, local caches, development dependencies, private guidance, and
build configuration. Include every generated/runtime asset required by the app.

Build a canonical candidate from an exact source identity, inspect its manifest,
record its checksum, and qualify those bytes. Signing/publication are later
states, not reasons to silently rebuild the candidate.

## 10. Files integrations preserve Nextcloud ownership

User documents, receipts, and photos should normally live in Nextcloud Files,
not database BLOBs or web-accessible app directories.

For linked files:

- use documented Files/WebDAV APIs;
- validate size, MIME/type expectations, quota, and ownership;
- generate safe/collision-resistant server-side paths where appropriate;
- never trust a caller-supplied filesystem path;
- re-check ownership/authorization when a linked file is read, moved, or deleted;
- keep database metadata as the relationship/provenance layer rather than a
  second file store.

Do not log file contents or sensitive filenames merely to simplify debugging.

## 11. Calendar, Activity, and notifications are projections

Maintenance or business-domain truth should live in the app's database. Calendar
events, Activity entries, and notifications are downstream projections that may
be retried, disabled, removed, or recreated.

Design integrations to be idempotent and to tolerate users deleting or changing
projected objects. Store enough binding identity to update the intended calendar
object without treating it as the authoritative maintenance record.

Calendar titles can expose sensitive context in shared calendars. Prefer neutral
safe defaults and make more revealing presentation an explicit user choice.

## 12. Background jobs must be bounded and idempotent

Use Nextcloud's background-job APIs and production cron rather than inventing an
independent scheduler. Avoid creating one framework job per domain object when a
bounded dispatcher can process due work in batches.

Background work should:

- be safe to retry;
- have bounded batch/run time;
- avoid duplicate notifications/calendar objects;
- record enough state for diagnosis without logging sensitive payloads;
- separate source-of-truth calculations from projection delivery.

An AJAX scheduler may be useful on tiny installations but should not be the
recommended production basis for time-sensitive reminders.

## 13. Security/privacy tests belong beside feature tests

Nextcloud apps frequently handle data that is more sensitive than the feature
name suggests. Authorization, UID reuse, cross-workspace existence disclosure,
unsafe rendering, untrusted imported JSON, arbitrary URLs, file ownership, and
logging boundaries deserve explicit negative tests.

Render imported/user text as text by default. Introducing raw HTML rendering,
arbitrary server-side URL fetching, or executable profile/template content is a
security architecture change, not a convenience refactor.

## 14. Separate deterministic CI from live advisory services

Syntax, lint, unit tests, deterministic builds, migrations, and local runtime
integration are product validation gates. Package-registry advisory queries are
valuable but network- and upstream-state-dependent.

Keep the distinction visible. A vulnerability finding requires action, while an
advisory endpoint outage should be classified as an external/harness failure
rather than encouraging arbitrary source changes. A practical pattern is a
separate scheduled/manual advisory workflow plus an explicit release review.

Composer lock/advisory controls and npm advisory checks complement one another;
neither substitutes for framework/runtime compatibility testing.

## 15. Compatibility expansion is an explicit gate

When a new Nextcloud major enters release-candidate/final status:

1. add a separate canary integration target;
2. run existing unit/frontend tests against the matching public OCP contract;
3. exercise installation/migrations and representative OCS behavior;
4. review documented deprecations/removals;
5. fix actual compatibility defects;
6. only then widen `appinfo/info.xml` support metadata.

Do not let a floating CI image silently expand the declared support contract.

## 16. Handoff evidence

For substantial Nextcloud changes, record:

- repository/branch/commit;
- clean or explicitly accepted working-tree state;
- migration(s) added and whether they are fresh/upgrade tested;
- PHP/frontend tests actually run;
- Nextcloud version/database combinations actually exercised;
- generated-asset status;
- package/checksum identity when built;
- CI state;
- known failures, including whether they are product, harness, environment, or
  external-service failures;
- the next authorized milestone.

Do not infer deployment or publication from a green source CI run.
