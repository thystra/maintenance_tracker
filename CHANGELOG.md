# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- v0.1.10 JSON fitment runtime foundation with bounded fitment-pack-v1 validation/canonical SHA-256 identity, immutable pack revision/import provenance, canonical standardized slots/parts, source offers, and source-specific fitment assertions.
- Explicit target-to-local-asset matching/mapping with `exact`, `candidate`, `conflict`, and `insufficient` states; only Owner/Manager may import/map, and conflict/insufficient mappings require explicit override confirmation.
- Source JSON export and privacy-minimized community JSON export rebuilt through the same validator. CSV/ZIP runtime, profile-v2 part materialization, activity parts-used records, vendor management, and central costs remain pending v0.1.10 work.

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
