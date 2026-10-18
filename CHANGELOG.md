# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

Every change set now bumps the app version by one patch level (in
`appinfo/info.xml`, `Application::APP_VERSION`, `package.json` and
`package-lock.json`, checked by `npm run validate:project`) and gets its own
section below, so upgrades run new migrations. Versions are not tagged.

## [0.1.19] - 2026-10-18

### Changed

- CI upgrade-test policy: the starting versions come from one documented list, `NC_UPGRADE_FROM` in the top-level `env:` of `.forgejo/workflows/ci.yml`. The rule is the latest tagged release plus the oldest supported release, and adding a tag after a release is a one-line change. Until the first release tag it holds only `6fc011d` (v0.1.10). `tests/integration/upgrade-bases.sh` resolves the list and, with `NC_UPGRADE_LATEST_TAG=1` (set in CI), also adds the newest `vX.Y.Z` tag when its app version is newer than every listed base. The list is the fallback. The old `v0.1.0` foundation tag is therefore ignored. `upgrade-smoke.sh` runs once per resolved base. A base that already has every migration (usual for the latest tag) is now a valid version-only upgrade instead of an error (`NC_UPGRADE_REQUIRE_MIGRATIONS=1` restores the check). A base whose version equals the working tree's and ends in `.0` is relabelled `0.0.0` so the upgrade still runs.
- The upgrade matrix now runs on pushes to `main`, nightly (new `schedule` trigger), on `workflow_dispatch`, and on pull requests that touch `lib/Migration/**`, `lib/Db/**`, `appinfo/info.xml`, the upgrade harness or the workflow. A new `changes` job (`scripts/upgrade-needed.sh`) makes the decision. On other pull requests the upgrade jobs still run but skip their steps, so their checks always report. There is no `on.pull_request.paths` filter, because a skipped workflow would leave required checks pending. The fresh-install matrix still runs on every pull request.
- The upgrade jobs are renamed from "Upgrade from v0.1.10 on Nextcloud …" to "Upgrade on Nextcloud …", and their limit is raised from 40 to 60 minutes (at most two starting points).
- `docs/development.md` gains an *Upgrade policy* section, and the release checklist (`docs/releasing.md`, step 7) adds the new tag to `NC_UPGRADE_FROM`.

## [0.1.18] - 2026-10-18

### Fixed

- CI: Nextcloud jobs no longer fail intermittently with "Nextcloud did not become ready within 180 seconds". The smoke and upgrade harnesses share `tests/integration/lib/docker-ready.sh`. It waits up to `NC_SMOKE_READY_TIMEOUT` seconds (default 600, set in CI) on a real clock (the old loop counted 90 polls, not 180 s), prints progress every 30 s, and fails fast if the container exits. On failure it prints `docker ps -a`, the container state and log, `status.php`, `occ status`, `nextcloud.log` and the database log. Sections that cannot be collected, for example because the container never started, now say so instead of printing nothing. A start that never becomes ready is retried once on fresh containers (`NC_SMOKE_START_ATTEMPTS`, default 2).
- CI: images are pulled in their own step (`tests/integration/pull-images.sh`, retried with exponential backoff: `NC_SMOKE_PULL_ATTEMPTS`, `NC_SMOKE_PULL_BACKOFF`), so registry time never counts against the readiness wait. The harnesses pull the same way when an image is missing.
- CI: parallel jobs on the shared Docker daemon no longer collide on fixed names (for example "network with name maintenance-tracker-nc34-pgsql-smoke-network already exists"), and one job can no longer remove another job's containers through the old leftover cleanup. Container and network names carry a short per-run id (`NC_SMOKE_RUN_ID`: 8 hex characters hashed from the CI run id, job, attempt and a random part; each name can still be overridden), for example `mt-1a2b3c4d-nc33-mysql-db`. Names double as DNS names, so they stay within the 63-character DNS label limit: the harness fails fast on a longer name, and Nextcloud reaches the database by the short alias `db` (`NC_SMOKE_DB_ALIAS`) on the run's own network, not by its container name. Every resource gets this run's label. The exit trap removes only this run's labelled containers (with their anonymous volumes) and networks. An existing network is reused only if it carries this run's label. Containers no longer use `--rm`, so a container that crashed keeps its logs until the trap has printed them.

### Changed

- CI job limits: `nextcloud` 20 → 45 minutes and `upgrade` 20 → 40 minutes, each pre-pull step 15 minutes. This leaves room for the pull, two 10-minute readiness waits and the suite. Normal runs are unchanged.

## [0.1.17] - 2026-10-18

### Added

- Profile builder: workspaces keep their own maintenance profiles in the new table `maint_ws_profiles` (migration `Version1170`), listed by `GET /profiles` next to the bundled ones with `origin: workspace` and `trustState: local`. OCS endpoints `GET/PUT/DELETE /profile-library/{uuid}` and `POST /profile-library` (idempotent per UUID, optimistic revisions) and `POST /assets/{assetUuid}/save-as-profile`. Every document passes the same `ProfileValidator` as bundled profiles (1 MiB, depth and list bounds, unknown fields rejected, HTTPS-only URLs that are never fetched); ids of bundled profiles are reserved so a workspace profile cannot pose as first-party. New capability `profile.manage` (Owner, Manager). Audit events `workspace_profile.created/updated/archived` and `asset.setup_copied`. Export, restore and the account-deletion purge carry the new table.
- Profile v2 gains optional `specifications` and `fuel` (settings and driving regimes) sections; documents without them keep their content hash, and `provenance.sourceUrl` is now optional. Installation creates the specs per component instance, adds regimes by name (a name clash is a preview conflict, the default is applied only to items without regimes) and applies fuel settings only to items still on default settings (otherwise the preview shows a notice).
- *Save as profile* (item → Setup) snapshots an item's components, meters, specs, compatible parts, task groups, tasks with schedules and fuel settings/regimes into a workspace profile. It never copies readings, activities, fill-ups, costs, files, serial numbers or store links.
- *Copy setup from* when adding an item (`POST /assets` with `copySetupFrom`): the new item gets the source item's setup in the same transaction, without an installation record and without any history (regimes yes, fill-ups no). A retry with the same item UUID does not copy twice.
- Interface: a *Profiles* page (workspace and bundled profiles; new, duplicate, import a local JSON file up to 1 MiB, export JSON, delete) and a profile editor with sections for details and applicability, meters, components, specs, parts (imported store links are kept), task groups, tasks with a schedule editor (time, business days, meter) and fuel settings and regimes. Keys are derived from names until edited, and server validation errors stay visible above the save bar. The Setup tab lists workspace profiles in the profile picker and shows install notices.

### Changed

- The dashboard's fuel card labels its right-hand figure *Latest tank: …*.
- A smoke check after restore now edits a restored fill-up's odometer and asserts that its reading is corrected (superseded) rather than detached. Restore already remaps the reading's back-reference to the new fill-up UUID, so no code change was needed.

## [0.1.16] - 2026-10-18

### Added

- Fuel log: per-item fuel settings (`maint_vehicle`: Fuel tab automatic/on/off, distance, volume and economy units, default fuel type), user-defined operating regimes per item with one default (`maint_contexts`, for example Empty, Loaded, Towing) and fuel tickets (`maint_fuel`) in migration `Version1160`. A ticket records date and time, odometer (it creates a reading of source type `fuel` on the item's odometer meter, or links an existing reading, so schedules see the distance), volume, full or partial fill, a missed-previous-fill flag, fuel type, optional station, regime, total price and notes. The price is stored once as a cost entry of the new category `fuel` linked from the ticket (`cost_id`), never duplicated; receipts attach through the evidence API (target type `fuel`), so no Files calls happen inside the fuel transactions.
- Fuel economy is computed only between two valid full fills; a missed fill-up or a non-increasing odometer leaves the stretch unmeasured. A stretch whose fill-ups name different regimes is marked mixed: it counts in the overall figures but not per regime. Units: MPG (US gallons and miles) by default, km/L and L/100 km supported, stored canonically in millimetres and microlitres and converted for display; new items with a kilometre odometer default to metric units.
- OCS endpoints `GET/PUT /assets/{assetUuid}/fuel-settings`, `GET/POST /assets/{assetUuid}/fuel-regimes`, `PATCH/DELETE /fuel-regimes/{uuid}`, `GET/POST /assets/{assetUuid}/fuel`, `GET/PATCH/DELETE /fuel/{uuid}`, `GET /assets/{assetUuid}/fuel-stats` (periods 30/90/365 days, this year, all time or a custom range; overall average/best/worst, per-regime average/best/worst with sample counts and distance, fuel spend, cost per distance and chart points) and `GET /fuel-summary`. Fill-ups need `activity.create`, editing and deleting them `activity.manage`, settings and regimes `inventory.manage`. Audit events `fuel.*`, `fuel_regime.*` and `fuel_settings.updated`. Export and restore carry the three tables (regime and cost references remapped); the account-deletion purge covers them.
- Interface: a Fuel tab on items whose category or profile is a vehicle or that have a distance (odometer) meter, with a per-item *Show/Hide the Fuel tab* action; period and unit filters, economy and spend cards, a per-regime table, an accessible economy chart (with a data table for screen readers), the fill-up list with receipts, *Add fill-up* and edit dialogs (price per unit computes the total), an *Operating regimes* dialog and *Fuel settings*. The dashboard gains a *Fuel economy* card with the latest and 90-day economy per item.

### Fixed

- Dialogs no longer close themselves 300 ms after a button is pressed. `NcDialog` hides itself after any button callback that does not return `false`, which could close a dialog that had just been reopened and, for a failed save, briefly flashed the error before closing. The add/edit item, log maintenance, task, reading, meter, cost, component and specification, linked item, task group, part, vendor and confirmation dialogs now close only after a successful save (or on Cancel), so save errors stay visible.

## [0.1.15] - 2026-10-18

### Added

- Item groups: arbitrary, nested organisational containers for items (for example Vehicles › Fleet 1; House › Upstairs/Downstairs/Basement; Shop; Small engines). New tables `maint_item_groups` (tree through a nullable parent, revisioned, tombstoned) and `maint_asset_grp` (at most one group per item, a link table so moving an item never rewrites the item) in migration `Version1150`. OCS endpoints `GET/POST /item-groups`, `PATCH/DELETE /item-groups/{uuid}`, `POST /item-groups/{uuid}/move` (refuses cycles) and `PUT /assets/{assetUuid}/group`; reads need `inventory.read`, changes `inventory.manage` (Owner, Manager). Deleting a group moves its subgroups and items up to its parent. Audit events `item_group.created`, `item_group.updated`, `item_group.moved`, `item_group.archived` and `asset.group_changed`. Export and restore carry the groups (parents restored before children) and memberships; account-deletion purge covers both tables.
- Interface: a group tree under Items in the sidebar (expand/collapse, item counts including subgroups, add subgroup/rename/move/delete), group filtering on the Items page with breadcrumbs and subgroup shortcuts (`#/items?group=<uuid>`, `?group=none` for ungrouped items), a group picker in the add/edit item dialog (new items added from a group page land in that group), breadcrumbs on item pages, and a *By group* dashboard card with item, overdue and due-soon counts per top-level group.

## [0.1.14] - 2026-10-18

Development since the `v0.1.0` foundation preview, first versioned as 0.1.14
so upgrades run the new migrations. Entries name the development milestone
(v0.1.1–v0.1.13 and 1.0 preparation) that introduced them.

### Added

- 1.0 prep new interface: the early single-page UI is replaced by a Nextcloud-native app built from `@nextcloud/vue` components with left navigation (workspace switcher, Dashboard, Items, Parts, Vendors, Settings) and hash routing (`#/items/<uuid>/<tab>` deep links). The dashboard shows counts, what needs attention, what is coming up in 60 days, recent activity (Owner/Manager) and quick links, with an empty state for new workspaces. Items have a list with search and category filter, add/edit/delete with confirmation, and a detail view with Overview, Tasks, History, Readings, Costs, Files and Setup tabs (profiles, components and specifications, linked items, task groups, parts that fit, work queue). A single “Log maintenance” dialog records work from the dashboard, the item list or an item, with optional reading, part, cost and notes. Parts and Vendors have their own pages with add/edit/delete. Settings holds workspaces (switch, create, rename), members and roles, ownership transfer, costs and currency, the due-soon window, personal reminders and calendar, categories, and export and restore. Pages set the document title and move focus to the heading on navigation. See `docs/ui.md`.
- Shared workspaces from the UI and API: `POST /workspaces` creates a shared workspace owned by the caller and `PATCH /workspaces/{uuid}` renames one (Owner). Audit events `workspace.created` and `workspace.renamed`. Previously the workspace switcher stayed disabled because a second workspace could only come from an ownership transfer or a restore.
- Vendor contact details: `website` (https only, outside-link policy and administrator switch, blanked in exports while links are off), `address`, `phone`, `email` and `contactName`; the vendor list reports `websitesEnabled`. Part catalog details: `notes`, a preferred vendor, a typical price and currency, and manual “fits this item” facts (`POST/DELETE /parts/{uuid}/fits`) shown with profile-derived fits. Migration `Version1130` adds the columns; export and restore carry them. The privacy policy now names vendor contact details as personal data.
- `GET /maintenance-forecast` returns one workspace-wide, severity-ordered forecast for the dashboard (up to 500 items).
- v0.1.13 workspace export: `GET /export?format=json|zip&includeFiles=0|1` and an “Export data” panel let every role download what it can read. The ZIP holds `manifest.json`, `data.json`, one CSV per table plus `csv/evidence.csv` (formula cells escaped with `leading-apostrophe-v1`), and optionally the evidence files the caller can open in their own Files (100 MiB per file, 512 MiB total; skipped files are listed). Audit and membership sections stay Owner-only, unshared evidence is never named, internal columns and bookkeeping tables are left out, and links follow the administrator switch. New capability `workspace.export`, feature `workspace-export`, and audit event `workspace.exported`.
- v0.1.13 accessibility: `npm run check:a11y` (also in CI) statically checks every Vue template for unlabelled controls, unnamed/untyped buttons, missing alt text, unsafe `target="_blank"`, positive tabindex, mouse-only click handlers, and error notices without a live role. See `docs/accessibility.md`.
- Workspace restore: `POST /import` and a “Restore an export” panel turn a complete Owner export (JSON or ZIP) into a new workspace owned by the caller, with regenerated UUIDs, remapped IDs, schema-checked columns, size limits and one database transaction; evidence files go to `Maintenance Tracker imports/` in the caller's Files afterwards. Members and audit history are not restored. New feature `workspace-import` and audit event `workspace.imported`.
- Published OpenAPI contract: `openapi.json` generated by Nextcloud's openapi-extractor (`composer openapi`) covers all 123 OCS operations with documented parameters, status codes and errors; CI regenerates it and fails when it is out of date.
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

### Changed

- Localization readiness: every UI string in the Vue app and the admin settings page goes through Nextcloud l10n (`@nextcloud/l10n` via `src/l10n.ts`, `IL10N` in PHP), with sentence-level placeholders and plurals. `npm run l10n:check` (also in CI) rejects untranslated template text, attributes and script strings; `npm run l10n:extract` writes a `.pot` template; `.l10nignore` and `l10n/` follow Nextcloud's Transifex workflow. See `docs/localization.md`.
- MariaDB is part of the qualification matrix: the smoke harness accepts `NC_SMOKE_DATABASE=mysql` (MariaDB 11.4 with READ-COMMITTED isolation and row binlogs) and CI runs it on Nextcloud 33–35. No code change was needed.
- New `tests/integration/upgrade-smoke.sh` installs the v0.1.10 app state, creates data, upgrades to the working tree with `occ upgrade`, and checks that exactly the shipped migrations ran, old data survived, and later features work on it. CI runs it on Nextcloud 33–35 with SQLite, PostgreSQL, and MariaDB.
- The harnesses accept `NC_SMOKE_NETWORK_MODE=bridge` to reach the database container by IP on the default Docker bridge, for hosts whose firewall drops traffic on user-defined bridges.
- Accessibility fixes: every placeholder-only input and select now has an accessible name, completed saves are announced through a polite status region, the quota warning is announced, keyboard focus has a visible outline, and panel toggles expose `aria-expanded`/`aria-controls`.
- v0.1.11 evidence on PostgreSQL: evidence-folder and share setup no longer runs inside the membership database transaction. Nextcloud core caught a unique violation in its mount cache, which aborts the whole transaction on PostgreSQL (SQLSTATE 25P02) and rolled back the membership change. Folder and share reconciliation now runs after commit. Evidence endpoints do their Files work outside any transaction and lock only their database writes.
- Nextcloud compatibility widened from 34 only to 33–35 after the full disposable integration suite passed on 33.0.10, 34.0.5, and 35.0.2; CI now runs the suite on all three releases with SQLite and PostgreSQL. The harness is renamed `tests/integration/nextcloud-smoke.sh`.
- Workspace authorization no longer uses a role-rank gate; controllers request named capabilities and workspace writes retain row-level serialization.
- `GET /workspaces` now returns all workspaces accessible to the current user instead of only the personal workspace.
- Account lifecycle cleanup now includes personal-workspace audit rows while preserving shared-workspace history authored by a deleted member.
- Personal-workspace deletion now purges categories, components, specifications, relationships, and assignments in addition to assets, change records, memberships, and the workspace itself.
- Forgejo is now the authoritative source and CI repository; GitHub is a
  downstream mirror used for issues and private security reports.
- The disposable Nextcloud integration harness transfers a staged app through
  the Docker API so it works with an isolated/remote Docker daemon.
- The roadmap now distinguishes the `v0.1.0` foundation preview from the
  remaining 0.1-series core MVP.
- Public documentation rewritten for users and administrators: a new README (features, limitations, requirements, installation, configuration, `occ` commands, privacy, export/restore), `CONTRIBUTING.md`, a refreshed `SECURITY.md`, `docs/development.md` for build/test/CI details, a `docs/README.md` index, and a user-facing App Store description in `appinfo/info.xml`.
- Release packaging: `make appstore` (`scripts/build-appstore.sh`) builds a reproducible App Store archive with only runtime files, optionally signed with `APP_SIGNING_KEY` (and code-signed with `occ integrity:sign-app`). A tag-triggered Forgejo workflow checks the tag against the app version, runs the checks, builds and signs the archive, and creates a Forgejo release. See `docs/releasing.md`.
- Added a privacy policy (`docs/privacy-policy.md`) describing what the app stores, where, who can see it, retention, account deletion, and how the GDPR rights are supported; linked from the README, the security policy, the documentation index, and the App Store description. `appinfo/info.xml` now points bug reports to GitHub issues and links the user and admin documentation.

### Fixed

- Installing from `git archive` (or the CI install candidate) gave a blank page: `.gitattributes` export-ignored the committed `js/` and `css/` bundles, so Nextcloud loaded no app script and the stylesheet URL returned an HTML error page. Runtime paths are no longer export-ignored (the project validator enforces this), the CI candidate is built from `git archive` alone, and a headless browser check (`NC_SMOKE_BROWSER=1`, run in CI) asserts that the UI actually mounts.
- Documentation no longer claims Activity delivery is at most once per audit row: a crash while one row is being projected re-projects that row and can duplicate its activities.
- `appinfo/info.xml` elements are reordered so the file validates against Nextcloud's `info.xsd` (33–35).

## [0.1.0] - 2026-07-23

Foundation preview (tag `v0.1.0`; not published to the App Store).

### Added

- Initial Nextcloud 34 application scaffold.
- Private per-user workspace and asset API foundation.
- Opaque cursor pagination, optimistic revisions, and idempotent client UUIDs.
- Offline synchronization change-journal foundation.
- Nextcloud capability discovery and user-deletion/UID-reuse cleanup.
- Profile schema and generic starter profile.
- Architecture, security, licensing, API, and delivery documentation.
