# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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

- Personal-workspace deletion now purges categories, components, specifications, relationships, and assignments in addition to assets, change records, memberships, and the workspace itself.
- Forgejo is now the authoritative source and CI repository; GitHub is a
  downstream mirror and private security-advisory intake exception.
- The disposable Nextcloud integration harness transfers a staged app through
  the Docker API so it works with an isolated/remote Docker daemon.
- The roadmap now distinguishes the `v0.1.0` foundation preview from the
  remaining 0.1-series core MVP.
