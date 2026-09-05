# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
