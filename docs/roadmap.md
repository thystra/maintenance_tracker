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
3. [x] v0.1.3 capability authorization, multi-user membership lifecycle, and append-only audit foundation (Forgejo CI #10 qualified);
4. [ ] v0.1.4 meters and immutable readings (distance, runtime hours, usage counts), including correction-by-supersession and role/capability boundaries;
5. common work definitions with `schedule: none` for unscheduled work, non-`none` scheduling policies, due calculation, and occurrences;
6. activity/service records and free-form notes;
7. validated local profile installation into real domain records;
8. parts, compatible part numbers, store links, and central cost entries;
9. first-class evidence in Nextcloud Files (photo/video/receipt/invoice/document/other);
10. Nextcloud Activity, notifications, and writable-calendar projection;
11. JSON/CSV export and UI/accessibility hardening.

Core MVP behavior includes:

- Custom categories.
- Assets and individually suppressible component instances.
- Typed relationships, contextual defaults, and effective-dated operational assignments.
- Validated local profile import and generic bundled profiles.
- Distance, runtime-hour, and usage-count meters/readings.
- Common work definitions where `schedule: none` is unscheduled and non-`none` policies may use calendar, meter, usage, or condition limits.
- Due dashboard and occurrence materialization for scheduled definitions.
- Activity/service records and free-form notes.
- Parts used, compatible part numbers, and safe store links.
- Central cost entries.
- Photos/receipts in Nextcloud Files.
- JSON/CSV export.
- Nextcloud Activity and notifications.
- Existing writable-calendar selection and idempotent event creation.

Design direction and deferred subsystems are recorded in `docs/product-architecture.md`.

Not in the 0.1 series:

- polished household sharing UI beyond the v0.1.3 membership API foundation;
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
- Household workspace UX using Owner/Manager/Contributor/Viewer roles; the membership API and capability foundation begin in v0.1.3.
- Scoped/revocable public maintenance report shares and external-submission review workflows.
- Nextcloud user migration/export integration.
- Evidence/blob retention policies, Protect/Keep overrides, storage reporting, and account-deletion workflows.

## Mobile 0.1

The mobile client starts only after the OCS sync contract is tested and versioned.
Its architecture is **Vue offline-first PWA -> Capacitor Android/iOS**, not a separate native data model.

Baseline:

- purpose-built Vue mobile UI sharing API/schema/sync code where useful;
- installable offline-first PWA with durable local records and mutation outbox;
- Capacitor packaging when Android/iOS native capabilities are required;
- Login Flow v2/app-password authentication with platform-appropriate protected
  credential storage in packaged clients;
- explicit pending/synced/conflict status and a detailed sync/storage page;
- portable unsynced work-bundle export for desktop import;
- camera/photo-picker and Nextcloud Files/WebDAV evidence upload;
- HTTPS required; no trust-all certificate behavior.

First mobile workflows are task-first: choose asset, choose activity/work,
record task-specific facts, optionally add notes/evidence, and submit to the log.
Fuel, readings, usage events, maintenance/repair completion, and trips then grow
from that same offline transaction model. Automatic background GPS remains
deferred.

## 1.0

- Stable OCS/OpenAPI contract and compatibility policy.
- Fresh-install and upgrade migrations on PostgreSQL, MariaDB, and SQLite.
- Calendar and Files integration tests.
- Accessibility, localization, export, retention, and uninstall behavior.
- Signed Nextcloud App Store package.
- Privacy policy, support channel, security response process, and user/admin
  documentation.
- Packaged Android/iOS testing as applicable, store privacy/data-safety review, pricing decision, and store-ready notices/EULA.
