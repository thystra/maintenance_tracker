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
4. [x] v0.1.4 meters and immutable readings (distance, runtime hours, usage counts), including correction-by-supersession and role/capability boundaries; qualified in PR #6 with CI #12 and merge CI #13;
5. [x] v0.1.5 common work definitions and explicit scheduling foundation: required `schedule`, `schedule: none`, calendar/business-day/meter policies, profile-v2, authorization/audit/lifecycle integration; qualified in PR #7 with CI #14 and merge CI #15;
6. [x] v0.1.6 maintenance activity/execution ledger: immutable performed-work and meter snapshots, atomic reading capture, offline idempotency, authorization/audit/lifecycle integration; qualified in PR #8 with CI #16 and merged at `40b663a492f5d06dec57cfac28296f7e34c35a09`;
7. [x] v0.1.7 derived maintenance due-state projection: calendar/business-day/meter evaluation against completed activity and effective readings, including baseline-required and incomplete-data states; merged in PR #9 at `3f62c78096a6af2ecc35cac81436d8cb15d0dd69` with mainline checks green;
8. [x] v0.1.8 occurrence materialization and forecasting/reminder policy using the derived due-state projection; qualified in PR #10, merged at `383fdbe69c30d38aa66ba6d575787c459aec80d6`, with mainline CI #22 green;
9. [x] v0.1.9 validated local profile installation into real domain records, including immutable revision provenance, source bindings, authorization/audit/lifecycle integration, and desktop preview/install workflow; qualified in PR #11 with CI #23, merged at `79fc23158c29240c63ad315bcfd0100881f98608`, with mainline CI #24 green;
10. [x] v0.1.10 canonical parts plus portable fitment packs and compatibility matrix import/export: JSON plus bounded CSV/ZIP validation/import/export, standardized service-position persistence, equipment matching/manual mapping, source export, privacy-minimized community export, profile-v2 part materialization, activity part usage, vendor management, safe store/vendor links with an administrator switch, and the central cost ledger (integer minor units, optional tax lines and multi-currency without conversion); Nextcloud 33–35 qualified;
11. [x] v0.1.11 first-class evidence in Nextcloud Files (photo/video/receipt/invoice/document/other): file-ID references with many-to-many links to assets, activities, and costs; an app-managed per-workspace folder in the owner's Files, shared with members by role and reconciled on membership changes; a WebDAV upload and Files picker UI; viewer-scoped visibility; previews; audit and account-deletion cleanup;
12. [x] v0.1.12 Nextcloud Activity, notifications, and writable-calendar projection: background jobs project the audit trail into Activity (maintenance records/tasks, evidence, membership, ownership transfer) and ownership-offer notifications, expire stale offers, deliver due-soon/due/overdue reminders from the forecast projection, and write neutral all-day events to a user-selected writable calendar exactly once per task baseline; Nextcloud 33–35 smoke-qualified on SQLite and PostgreSQL 17;
13. [x] v0.1.13 JSON/CSV export and UI/accessibility hardening: role-filtered `GET /export` as JSON or ZIP (JSON plus per-table CSV with formula escaping, optional viewer-readable evidence files within size bounds), audited, with Files access only outside the snapshot transaction; static accessibility checks in npm/CI and fixes for unlabelled controls, live-region announcements, focus visibility, and disclosure state; Nextcloud 33–35 smoke-qualified on SQLite and PostgreSQL 17. This completes the planned 0.1 core-MVP sequence.

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

Partly delivered in 0.1.16 (the fuel log): fuel entries, per-item operating
regimes, fuel units and economy/spend statistics. Items marked *(0.1.16)* are
delivered in that form; the rest is still planned.

- Vehicle extension and odometer reconciliation. *(0.1.16: per-item fuel
  settings and units only; VIN/plate data and annual odometer reconciliation
  are planned.)*
- Fuel/energy entries with full/partial and missed-fill handling. *(0.1.16:
  liquid fuels; electric charging is planned.)*
- Simple fuel mode. *(0.1.16: fill-ups need only date, odometer and volume;
  regimes are optional.)*
- Advanced operating contexts: empty, hauling, towing a named trailer.
  *(0.1.16: user-defined regimes per item with a default; linking a regime to
  a related item such as a trailer is planned.)*
- Context-tagged trip/odometer segments for meaningful economy comparisons.
  *(0.1.16: stretches between full fills that mix regimes are marked mixed and
  left out of per-regime figures; trip segments are planned.)*
- Complete cost ledger categories.
- Tracked-cost, TCO-coverage, and cost-per-distance reports. *(0.1.16: fuel
  spend and fuel cost per distance on the Fuel tab.)*
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

Status notes describe work on development branches that has not been released;
an item is only complete once it passes on Forgejo `main`.

- Stable OCS/OpenAPI contract and compatibility policy. *In progress: a generated
  `openapi.json` with a CI freshness check exists; response schemas and a written
  compatibility policy remain.*
- Fresh-install and upgrade migrations on PostgreSQL, MariaDB, and SQLite.
  *In progress: smoke and upgrade-from-v0.1.10 tests run on all three databases.*
- Calendar and Files integration tests.
- Accessibility, localization, export, retention, and uninstall behavior.
  *In progress: static accessibility checks, translatable UI, export, and restore
  exist; real translations, retention, and uninstall behavior remain.*
- Signed Nextcloud App Store package.
- Privacy policy, support channel, security response process, and user/admin
  documentation. *In progress: README, CONTRIBUTING, and SECURITY cover
  installation, configuration, GitHub issues for support, and private
  vulnerability reporting; a privacy policy remains.*
- Packaged Android/iOS testing as applicable, store privacy/data-safety review, pricing decision, and store-ready notices/EULA.
