# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- v0.1.13 workspace export: `GET /export?format=json|zip&includeFiles=0|1` and an “Export data” panel let every role download what it can read. The ZIP holds `manifest.json`, `data.json`, one CSV per table plus `csv/evidence.csv` (formula cells escaped with `leading-apostrophe-v1`), and optionally the evidence files the caller can open in their own Files (100 MiB per file, 512 MiB total; skipped files are listed). Audit and membership sections stay Owner-only, unshared evidence is never named, internal columns and bookkeeping tables are left out, and links follow the administrator switch. New capability `workspace.export`, feature `workspace-export`, and audit event `workspace.exported`.
- v0.1.13 accessibility: `npm run check:a11y` (also in CI) statically checks every Vue template for unlabelled controls, unnamed/untyped buttons, missing alt text, unsafe `target="_blank"`, positive tabindex, mouse-only click handlers, and error notices without a live role. See `docs/accessibility.md`.

### Changed

- Localization readiness: every UI string in the Vue app and the admin settings page goes through Nextcloud l10n (`@nextcloud/l10n` via `src/l10n.ts`, `IL10N` in PHP), with sentence-level placeholders and plurals. `npm run l10n:check` (also in CI) rejects untranslated template text, attributes and script strings; `npm run l10n:extract` writes a `.pot` template; `.l10nignore` and `l10n/` follow Nextcloud's Transifex workflow. See `docs/localization.md`.

- MariaDB is part of the qualification matrix: the smoke harness accepts `NC_SMOKE_DATABASE=mysql` (MariaDB 11.4 with READ-COMMITTED isolation and row binlogs) and CI runs it on Nextcloud 33–35. No code change was needed.
- New `tests/integration/upgrade-smoke.sh` installs the v0.1.10 app state, creates data, upgrades to the working tree with `occ upgrade`, and checks that exactly the shipped migrations ran, old data survived, and later features work on it. CI runs it on Nextcloud 33–35 with SQLite, PostgreSQL, and MariaDB.
- The harnesses accept `NC_SMOKE_NETWORK_MODE=bridge` to reach the database container by IP on the default Docker bridge, for hosts whose firewall drops traffic on user-defined bridges.

- Accessibility fixes: every placeholder-only input and select now has an accessible name, completed saves are announced through a polite status region, the quota warning is announced, keyboard focus has a visible outline, and panel toggles expose `aria-expanded`/`aria-controls`.

### Fixed

- Documentation no longer claims Activity delivery is at most once per audit row: a crash while one row is being projected re-projects that row and can duplicate its activities.
- `appinfo/info.xml` elements are reordered so the file validates against Nextcloud's `info.xsd` (33–35).

### Added

- v0.1.12 Nextcloud Activity: background job `ProjectAuditTrail` turns the append-only audit trail into Activity events for maintenance records and tasks, evidence, membership changes, and ownership transfers (offers visible only to offerer and recipient). There are four Activity settings and a “Maintenance” filter. Events carry identifiers only, and names are rendered when read. The projection cursor starts at the end of the existing trail on upgrade.
- v0.1.12 notifications: ownership offers notify the recipient and are withdrawn when the offer closes. Declines and expiry notify the offerer. Background job `SendMaintenanceReminders` sends due-soon/due/overdue reminders from the forecast projection, once per task, completion baseline, and level. Reminders are withdrawn after a new completion and can be switched off per user. Background job `ExpireOwnershipTransfers` expires stale offers.
- v0.1.12 calendar projection: users may select one of their own writable calendars (`GET /integrations/calendars`, `GET/PUT /integrations/settings`). Dated tasks get one neutral all-day “Maintenance due” event per completion baseline, written through the public Calendar API with a deterministic UID and never duplicated. Meter-only tasks are not projected, and events are not removed after completion (public API limitation).
- `occ maintenance_tracker:integrations:run`, capabilities `activity-integration`, `notifications`, `calendar-projection`, a “Reminders and calendar” panel, and migration `Version1120` (`maint_notify_log`, `maint_cal_events` delivery bookkeeping, both in the account-deletion purge registry).

- Workspace ownership transfer: the owner offers ownership to an existing member, who must accept. Offers expire (`expiresInDays` 1–30, default 7), can be cancelled or declined, and are audited. Before accepting, the recipient sees the folder size against their free quota and must acknowledge it. On acceptance the evidence folder moves into the new owner's Files (same file ids; evidence outside the folder is re-pointed or reported), shares are re-created from the new owner, roles swap (the previous owner becomes Manager or leaves), and the workspace becomes shared. Transfers are a persisted, resumable step machine with `resume`/`abort`. Adds OCS endpoints under `/ownership-transfers`, the `workspace.ownership.transfer` capability, the `workspace-ownership-transfer` feature, an accept banner, and a workspace switcher.
- Owner account deletion no longer silently purges shared data. Ownership passes to the longest-standing Manager. With no Manager, or with app config `owner_deletion_policy=orphan`, the workspace is orphaned instead of purged. An administrator can then run `occ maintenance_tracker:workspace:list-orphaned` and `occ maintenance_tracker:workspace:assign-owner <workspace> <user> [--remove-previous]`. Workspaces with no other members are still purged.
- v0.1.11 evidence in Nextcloud Files: photo/video/receipt/invoice/document/other evidence references a file in Nextcloud Files by ID (bytes are never copied) and links to assets, activities, and costs. Each workspace has an app-managed `Maintenance Tracker/<Workspace>/<Asset>/` folder in the owner's Files, shared with members as Nextcloud user shares whose permissions follow the role (never reshare). Shares are reconciled on membership add, role change, and removal. Uploads go through WebDAV, so permissions and quota are enforced by Nextcloud. Existing files can still be linked with the Files picker. Renames and moves are followed by file ID. Unshared, moved, or deleted files are reported as `not_shared`/`missing` without disclosing names or paths. Includes previews, `evidence.read/create/manage` capabilities, audit events, account-deletion cleanup, and the `evidence-files` capability.

- v0.1.10 store and vendor links: https-only links on canonical parts and vendors under a shared strict outside-link policy (ASCII host, no credentials, no `javascript:`/`data:`/`http:`), rendered with `target="_blank" rel="noopener noreferrer"`, Owner/Manager-managed and audited. Profile-v2 part offers now materialize as links instead of failing closed. A Nextcloud admin settings section can disable outside links instance-wide: nothing new is stored and existing links, profile offers, and fitment source-export offers are hidden or refused until re-enabled.
- v0.1.10 central cost ledger: per-asset cost entries in exact integer minor units (decimal-string input, floats rejected) optionally linked to an activity, vendor, and part; per-workspace settings default to a single currency (USD) with no tax lines, with Owner-only switches for tax lines and multi-currency (ISO 4217 codes, no conversion, per-currency totals). `cost.read` for every role, `cost.create` for Contributors and up, Owner/Manager-only `cost.manage`, and the previously reserved `workspace.settings.manage` implemented for Owners only. Includes audit events, account-deletion cleanup, and a per-asset desktop cost panel.
- v0.1.10 profile-v2 part installation: the deliberate part refusal is removed; profile parts converge on canonical parts by identity with `part` source bindings, and `compatiblePartKeys` become informational per-component/per-definition compatibility facts (`maint_part_compat`, `GET /assets/{assetUuid}/compatible-parts`). Duplicate part identities within one profile remain a fail-closed conflict.
- v0.1.10 parts used per maintenance activity: optional immutable `parts` children on activity creation (Contributor-capable via `activity.create`) with exact decimal-string quantities stored as integer thousandths, a bounded unit vocabulary, optional vendor, part/vendor display snapshots, idempotent-retry comparison, account-deletion cleanup, and desktop capture/display.
- v0.1.10 canonical part catalog API over the existing `maint_parts` identity (manual create/correct/archive with identity convergence and archived-identity restore) plus a workspace vendor catalog, with `part.read`/`vendor.read` for every role and Owner/Manager-only `part.manage`/`vendor.manage`, audit events, account-deletion cleanup, and a desktop parts/vendors panel.
- v0.1.10 fitment runtime foundation with bounded fitment-pack-v1 validation/canonical SHA-256 identity, immutable pack revision/import provenance, canonical standardized slots/parts, source offers, and source-specific fitment assertions.
- Explicit target-to-local-asset matching/mapping with `exact`, `candidate`, `conflict`, and `insufficient` states; only Owner/Manager may import/map, and conflict/insufficient mappings require explicit override confirmation.
- Source/community JSON export plus bounded normalized CSV/ZIP spreadsheet import/export rebuilt through the same canonical validator, with exact file/header allowlists, model/configuration identifier preservation, 8 MiB compressed/32 MiB expanded bounds, archive traversal/symlink/encryption defenses, and reversible formula-injection escaping.

- v0.1.9 validated runtime profile-v2 installation for bundled and pasted/local JSON, with server-side preview, applicability/conflict reporting, and desktop management workflow.
- Immutable workspace profile revision snapshots with canonical SHA-256 identity, origin/trust classification, client installation UUID idempotency, and source-key/ordinal bindings to materialized records.
- Transactional profile materialization through the existing component, meter, work-group, work-definition, and asset services, including profile `meterKey` resolution to real meter UUIDs.
- `profile.read` for all workspace roles, Owner/Manager-only `profile.install`, bounded `profile.installed` audit events, and account-lifecycle cleanup of profile provenance tables.
- Runtime safeguards for multi-instance component ambiguity and component-parent cycles. Profile-v2 part-bearing documents validate but intentionally fail installation until the v0.1.10 parts subsystem can preserve those facts losslessly.

- v0.1.8 configurable maintenance forecast/reminder policy with explicit calendar lead days and meter lead percentage.
- Read-only maintenance forecast projection adds policy-layer `due_soon`, `not_due`, `setup_required`, and `blocked` states without changing v0.1.7 due truth.
- Materialized maintenance occurrence work queue with a portable one-open-occurrence-per-definition database invariant and explicit reconciliation.
- Occurrence rows store workflow lifecycle only; due dates, due states, and meter thresholds remain read-time projections.

- v0.1.7 derived maintenance due-state projection with `inactive`, `unscheduled`, `baseline_required`, `upcoming`, `due`, `overdue`, and `unknown` states.
- Calendar intervals use UTC calendar dates with end-of-month/leap-day clamping; configurable business-day schedules count only selected weekdays.
- Meter schedules derive thresholds from the latest completed linked activity plus effective meter history and preserve `combination: any` semantics without persisting stale status rows.
- Asset maintenance-status OCS endpoint and desktop status surface expose exact remaining days or canonical meter distance/runtime/count rather than inventing an unconfigured due-soon threshold.

- v0.1.6 maintenance activity/execution ledger with revisioned activity headers, immutable performed-work items, and immutable meter snapshots.
- Contributor activity creation/read access with Owner/Manager descriptive correction/archive capabilities, plus activity audit and lifecycle cleanup coverage.
- Atomic activity-created meter readings with explicit units and activity source provenance; offline retries remain idempotent across later work-definition renames.
- Desktop maintenance-history entry surface and expanded Nextcloud 34 SQLite/PostgreSQL activity-lifecycle integration coverage.

- v0.1.5 common work-definition and scheduling foundation with asset-scoped work groups and normalized schedule rules.
- Every new work definition and every profile-v2 work-definition template must explicitly provide `schedule`; omission is invalid and is never inferred as `none`.
- `schedule: none` explicitly represents unscheduled/ad-hoc work; non-`none` policies currently support `combination: any` with calendar, configurable business-day, and meter rules.
- Schedule meter rules reuse canonical meter conversion and block archival of referenced meters while the definition remains active.
- Owner/Manager definition-management capabilities, Contributor/Viewer read access, work-definition audit events, account-lifecycle purge coverage, desktop configuration UI, and Nextcloud 34 SQLite/PostgreSQL integration coverage.
- Profile schema v2 introduces `workGroups` and `workDefinitions` while retaining profile v1 as a separately validated compatibility format.

- v0.1.4 meter/readings foundation with asset- or component-targeted distance, runtime, and usage-count meters.
- Immutable timestamped readings retain original value/unit alongside canonical integer values (`mm`, `s`, or `count`) for deterministic scheduling inputs.
- Monotonic-series validation, historical insertion checks, and correction-by-supersession without rewriting prior observations.
- Capability boundaries allowing Contributors to record readings while reserving meter configuration and historical correction for Owner/Manager.
- Meter/reading audit events, account-lifecycle purge coverage, desktop entry UI, and Nextcloud 34 SQLite/PostgreSQL integration coverage.
- Enabling monotonic mode on an existing meter validates the complete effective history before accepting the configuration change.

- v0.1.3 capability-based workspace authorization with explicit Owner, Manager, Contributor, and Viewer bundles plus legacy `editor` normalization.
- Shared-workspace membership OCS endpoints with deterministic actor/target lifecycle locking.
- Append-only, versioned audit events for implemented inventory, relationship/assignment, and membership mutations.
- Multi-user integration coverage for Contributor/Manager boundaries, membership removal on account deletion, retained shared work, and retained historical audit attribution.
- Architecture/documentation synchronization for common work definitions (`schedule: none` for unscheduled work), evidence/retention, scoped public reports, external mechanic submissions, and Vue PWA -> Capacitor mobile direction.

- v0.1.2 relationship/assignment expansion: typed class-compatible asset relationships, contextual defaults, effective-dated assignments, and primary-assignment overlap protection.
- Relationship and assignment OCS lifecycle endpoints plus desktop configuration UI.
- Workspace-wide mutation serialization so shared-workspace members cannot race contextual-default or primary-assignment invariants.
- Lifecycle validation and UID-reuse coverage for every current workspace-scoped domain table.

- v0.1.1 inventory expansion: workspace custom categories, broad asset classes, nested component instances, and structured asset/component specifications with units, regimes, and provenance.
- OCS and desktop inventory UI for category, component, and specification creation/listing.
- Product architecture guidance covering desktop/mobile split, offline work bundles, relationships/assignments, usage bases, forecasting, towing/load configurations, weight tickets, and optional geodata.

- Project-specific `AGENTS.md` and reusable Nextcloud engineering guidance.
- Native Forgejo CI with Nextcloud 34 SQLite/PostgreSQL runtime qualification.
- Separate scheduled/manual dependency-advisory workflow.

- Initial Nextcloud 34 application scaffold.
- Private per-user workspace and asset API foundation.
- Opaque cursor pagination, optimistic revisions, and idempotent client UUIDs.
- Offline synchronization change-journal foundation.
- Nextcloud capability discovery and user-deletion/UID-reuse cleanup.
- Profile schema and generic starter profile.
- Architecture, security, licensing, API, and delivery documentation.

### Changed

- v0.1.11 evidence on PostgreSQL: evidence-folder and share setup no longer runs inside the membership database transaction. Nextcloud core caught a unique violation in its mount cache, which aborts the whole transaction on PostgreSQL (SQLSTATE 25P02) and rolled back the membership change. Folder and share reconciliation now runs after commit. Evidence endpoints do their Files work outside any transaction and lock only their database writes.

- Nextcloud compatibility widened from 34 only to 33–35 after the full disposable integration suite passed on 33.0.10, 34.0.5, and 35.0.2; CI now runs the suite on all three releases with SQLite and PostgreSQL. The harness is renamed `tests/integration/nextcloud-smoke.sh`.
- Workspace authorization no longer uses a role-rank gate; controllers request named capabilities and workspace writes retain row-level serialization.
- `GET /workspaces` now returns all workspaces accessible to the current user instead of only the personal workspace.
- Account lifecycle cleanup now includes personal-workspace audit rows while preserving shared-workspace history authored by a deleted member.

- Personal-workspace deletion now purges categories, components, specifications, relationships, and assignments in addition to assets, change records, memberships, and the workspace itself.
- Forgejo is now the authoritative source and CI repository; GitHub is a
  downstream mirror and private security-advisory intake exception.
- The disposable Nextcloud integration harness transfers a staged app through
  the Docker API so it works with an isolated/remote Docker daemon.
- The roadmap now distinguishes the `v0.1.0` foundation preview from the
  remaining 0.1-series core MVP.
