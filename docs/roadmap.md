# Delivery roadmap

## Foundation — current

- Nextcloud 34/PHP 8.5 app shell.
- Vue web entry point.
- Authenticated OCS capabilities, workspace, and asset endpoints.
- Private workspace and role-ready membership.
- UUID, revision, tombstone, and change-journal foundation.
- Data-only profile schema and sample.
- Architecture, security, licensing, and API decisions.

Exit criteria:

- PHP syntax, PHPUnit, frontend lint/typecheck/build pass.
- App installs and migration applies on a disposable Nextcloud 34 instance.
- Asset create/list/update/delete is verified through OCS.
- PostgreSQL is exercised before deployment to Nidhoggur.

## 0.1 — Core maintenance MVP

- Custom categories.
- Assets and individually suppressible component instances.
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

Not in 0.1:

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
