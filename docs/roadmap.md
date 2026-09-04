# Delivery roadmap

## Foundation — v0.1.0 preview, qualified

The `v0.1.0` tag is the initial foundation preview. It is not the complete core
maintenance MVP.

Implemented foundation:

- Nextcloud 34/PHP 8.2–8.5 app shell.
- Vue web entry point.
- Authenticated OCS capabilities, workspace, and asset endpoints.
- Private workspace and role-ready membership.
- UUID, revision, tombstone, and change-journal foundation.
- Bounded cursor pagination.
- Serialized user-deletion/external-ID cleanup that prevents UID reuse from
  inheriting a previous account's personal workspace.
- Data-only profile schema and sample.
- Architecture, security, licensing, API, and engineering guidance.

Foundation qualification gates:

- [x] PHP/frontend deterministic checks were established for the `v0.1.0`
  foundation.
- [x] Disposable Nextcloud 34 + SQLite integration coverage exists for app
  enablement, asset CRUD/revision behavior, user lifecycle cleanup, page load,
  and app-error log inspection.
- [x] Authoritative Forgejo CI is green on current `main` after the repository
  authority migration.
- [x] The same Nextcloud 34 smoke contract is green on PostgreSQL.
- [x] Forgejo CI produced and validated the unsigned install candidate as part of
  foundation qualification; signed publication remains a later release gate.

Foundation exit criteria:

- PHP syntax, PHPUnit, frontend lint/typecheck/build pass in authoritative CI.
- App installs and migrations apply on a disposable Nextcloud 34 instance.
- Asset create/list/update/delete and stale-revision behavior are verified
  through OCS.
- User deletion/UID-reuse behavior remains verified.
- PostgreSQL is exercised before production deployment.

Do not mark a gate complete merely because its workflow or test code exists; the
corresponding Forgejo run must actually pass.

## 0.1 series — Core maintenance MVP

Implement this milestone in vertical tranches so each new domain layer can be
migrated, authorized, exposed through OCS, and tested before dependent layers
are added.

Planned sequence:

1. [x] custom categories, asset classes, component instances, and structured specifications;
2. [x] typed asset relationships and effective-dated assignments;
3. meters and readings (distance, runtime hours, usage counts);
4. maintenance plans, triggers, due calculation, and one open occurrence per
   plan;
5. service/completion records and free-form notes;
6. validated local profile installation into real domain records;
7. parts, compatible part numbers, store links, and central cost entries;
8. photos/receipts in Nextcloud Files;
9. Nextcloud Activity, notifications, and writable-calendar projection;
10. JSON/CSV export and UI/accessibility hardening.

Core MVP behavior includes:

- Custom categories.
- Assets and individually suppressible component instances.
- Typed relationships, contextual defaults, and effective-dated operational assignments.
- Validated local profile import and generic bundled profiles.
- Distance, runtime-hour, and usage-count meters/readings.
- Calendar and meter triggers with `ANY` (“whichever first”) behavior.
- Due dashboard and one open occurrence per plan.
- Service/completion records and free-form notes.
- Parts used, compatible part numbers, and safe store links.
- Central cost entries.
- Photos/receipts in Nextcloud Files.
- JSON/CSV export.
- Nextcloud Activity and notifications.
- Existing writable-calendar selection and idempotent event creation.

Design direction and deferred subsystems are recorded in `docs/product-architecture.md`.

Not in the 0.1 series:

- household sharing UI;
- remote profile marketplace;
- OCR or automatic ordering;
- predictive maintenance;
- GPS tracking;
- tax deduction calculation.

## 0.2 — Vehicle and business mileage

- Vehicle extension and odometer reconciliation.
- Fuel/energy entries with full/partial and missed-fill handling.
- Simple fuel mode.
- Advanced operating contexts: empty, hauling, towing a named trailer.
- Context-tagged trip/odometer segments for meaningful economy comparisons.
- Complete cost ledger categories.
- Tracked-cost, TCO-coverage, and cost-per-distance reports.
- Manual trip logging separated into business, commuting, personal, medical,
  charitable, and other.
- Effective-dated mileage rates with primary-source URLs.
- Annual mileage reconciliation, attestation, CSV, and PDF report snapshots.

Reports say “recordkeeping support” and “estimated deduction.” They do not claim
to decide eligibility or guarantee IRS compliance.

## 0.3 — Catalog and collaboration

- Signed/versioned model-specific profile bundles.
- Profile upgrade diff and merge.
- Vendor catalog, equivalent parts, offers, and reorder workflows.
- Inventory quantities where useful.
- Household workspaces with owner/editor/viewer UI.
- Nextcloud user migration/export integration.
- Retention policy and account-deletion workflows.

## Android 0.1

Begin only after the OCS sync contract is tested and versioned.

Baseline:

- Kotlin, Jetpack Compose, JDK 17.
- compile/target SDK 36 (Android 16).
- minimum SDK 23.
- single-activity, unidirectional data flow.
- Room as local source of truth.
- durable mutation outbox and opaque sync cursor.
- WorkManager per-account synchronization.
- Login Flow v2, app password encrypted by Android Keystore.
- Photo Picker and camera receipt capture.
- WebDAV uploads plus OCS metadata association.
- HTTPS required; no trust-all certificate behavior.

First mobile workflows:

- login/account selection;
- view due work;
- create/edit assets;
- enter readings;
- complete maintenance with notes/cost/photos;
- add fuel entries;
- add business trips.

Automatic background GPS is deliberately deferred.

## 1.0

- Stable OCS/OpenAPI contract and compatibility policy.
- Fresh-install and upgrade migrations on PostgreSQL, MariaDB, and SQLite.
- Calendar and Files integration tests.
- Accessibility, localization, export, retention, and uninstall behavior.
- Signed Nextcloud App Store package.
- Privacy policy, support channel, security response process, and user/admin
  documentation.
- Android closed test, Play Data Safety review, paid-app pricing decision, and
  store-ready notices/EULA.
