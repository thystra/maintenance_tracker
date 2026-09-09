# Maintenance Tracker Agent Guide

## 1. Scope and authority

This file contains project-specific engineering guidance for the Maintenance
Tracker Nextcloud app. General engineering principles are defined separately in
the shared ArgentWolf development guidance; this file adds the repository,
platform, architecture, and validation rules that are specific to this app.

Repository authority:

- authoritative repository: `https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud`;
- canonical branch: `main`;
- GitHub is a downstream mirror, not development or release authority;
- the GitHub private security-advisory endpoint documented in `SECURITY.md` is
  an explicit security-intake exception and does not make GitHub authoritative;

Do not put private infrastructure details, credentials, internal addresses, or
host-specific deployment instructions in this public application repository.
Environment-specific deployment belongs in infrastructure management.

## 2. Supported platform contract

Current application contract:

- Nextcloud 34 only;
- PHP 8.2 through 8.5, with PHP 8.5 the primary production target;
- Node.js 24 for frontend builds;
- PostgreSQL, MariaDB/MySQL, and SQLite through public Nextcloud database APIs;
- only public `OCP` APIs in application code;
- Vue 3 with maintained `@nextcloud/*` frontend packages;
- OCS API prefix: `/ocs/v2.php/apps/maintenance_tracker/api/v1`.

Do not widen the Nextcloud or PHP compatibility declaration merely because code
appears syntactically compatible. Add the compatibility target, exercise it in
CI/integration testing, then change `appinfo/info.xml` and documentation.

## 3. Repository invariants

Before modifying source, verify at minimum:

```bash
git status --short --branch
git rev-parse HEAD
git remote -v
```

For planned patches, require the expected branch/commit and a clean tree unless
an exact dirty set has been deliberately accepted. Never overwrite unknown
local work.

Forgejo Actions under `.forgejo/workflows/` are the authoritative CI path. Jobs
normally target `forgejo-workstation`. GitHub workflow files are not the primary
CI mechanism.

Generated frontend assets under `js/` and `css/` are tracked runtime artifacts.
`src/` is their canonical source. A frontend change is incomplete unless a
fresh build leaves the committed generated assets identical to the build output.

## 4. Architecture and security invariants

Nextcloud owns authentication, sessions, database connections, user files, and
the web application shell. Maintenance Tracker owns maintenance-domain data and
its OCS contract.

Preserve these invariants:

- normal application requests continue through Nextcloud; do not add
  app-specific nginx endpoints;
- authenticated identity comes from Nextcloud, never a caller-supplied owner UID;
- normal-user routes are not public routes;
- every workspace-bound query is authorization-scoped by capability before object access;
- Owner/Manager/Contributor/Viewer are capability bundles, not a role rank; Manager must never gain a new sensitive capability implicitly;
- legacy `editor` compatibility normalizes to `manager`; controllers must not authorize on raw role names;
- UUIDs identify objects but are never authorization secrets;
- cross-workspace errors do not disclose another user's object existence;
- writes use optimistic revisions and retain tombstones where synchronization
  requires deletion visibility;
- user deletion/external-ID detachment cleanup must remain serialized against
  lazy personal-workspace creation so UID reuse cannot inherit prior data;
- membership mutations must serialize lifecycle state for actor and target users in deterministic UID order;
- `maint_audit` is append-only: no update/delete mapper path, bounded structured details, no free-form notes/file contents; historical shared-workspace actor attribution survives member deletion;
- `maint_readings` is append-only: corrections create a new row linked by `supersedes_id`; no mapper/service update/delete path may rewrite an observation;
- meter canonical values remain integer `mm`, `s`, or `count` while readings retain the normalized original value/unit; monotonic corrections/inserts must be checked against effective neighboring readings;
- every capability-authorized write must retain workspace-wide write serialization so
  invariants spanning multiple rows remain safe when different members write
  the same shared workspace concurrently;
- every migration-created table with `workspace_id` must remain in the account
  deletion purge registry, with child/history tables removed before assets;
- profiles are bounded, data-only, non-executable input; runtime installation accepts profile v2 only, never fetches provenance URLs, and must fail rather than discard unsupported profile facts;
- the common work-definition scheduling field is named `schedule` and is REQUIRED on creation/profile-v2 input; never infer or default a missing schedule; `schedule: none` is explicitly unscheduled/ad-hoc work and any non-`none` policy is scheduled maintenance;
- receipt/photo bytes belong in Nextcloud Files, not database blobs or a public
  app directory;
- calendar, Activity, and notifications are projections/integrations, not the
  source of maintenance truth;
- sensitive values, notes, destinations, receipts, credentials, and user file
  contents must not enter logs or test fixtures.

See `docs/architecture.md`, `docs/security.md`, and
`docs/NEXTCLOUD-DEVELOPMENT-GUIDANCE.md` for the detailed contracts.

## 5. Database and migration discipline

Nextcloud migrations are durable product history. Once a migration has shipped
or been used by a persistent installation, do not edit it to represent a new
schema state. Add a later migration.

Schema and query work must remain portable across declared databases. At
minimum:

- use Nextcloud schema/query APIs instead of database-specific SQL where a public
  portable API exists;
- avoid assumptions about boolean, autoincrement, unsigned integer, date/time,
  collation, or identifier behavior that differ by database;
- exercise fresh-install migrations and important data behavior on the target
  database, not merely PHP unit tests;
- before 1.0, qualify fresh install and upgrade paths on PostgreSQL,
  MariaDB/MySQL, and SQLite.

SQLite is useful for fast integration testing but does not prove PostgreSQL or
MariaDB behavior.

## 6. Validation

Use the project's documented commands. Normal local deterministic checks are:

```bash
composer validate --strict
composer test
npm ci
npm run validate:project
bash tests/validate-project-selftest.sh
npm run validate:profiles
npm run typecheck
npm run lint
npm run stylelint
npm run build
git diff --check
git diff --exit-code -- js css
```

Disposable Nextcloud 34 runtime qualification:

```bash
NC_SMOKE_DATABASE=sqlite bash tests/integration/nextcloud34-smoke.sh
NC_SMOKE_DATABASE=pgsql bash tests/integration/nextcloud34-smoke.sh
```

The integration harness must use disposable data and must never point at a real
Nextcloud database or user store.

Network-backed package advisory checks are intentionally separate from the
normal deterministic CI workflow. A registry/advisory outage is not evidence of
a product regression. Advisory findings still require review before release.

Validation state must be reported precisely: local checks, Forgejo branch CI,
merge, mainline CI, packaging, signing, publication, deployment, and production
behavior are separate gates.

## 7. Forgejo runner/Docker boundary

`forgejo-workstation` jobs execute in an isolated job container and reach a
separate Docker-in-Docker daemon through `DOCKER_HOST`; the host Docker socket is
not exposed.

Project-owned CI images under `ci/images/` separate expensive toolchain
construction from routine behavior testing. Build and qualify those images
separately; once published, routine workflows must pin the reviewed Forgejo
registry digest rather than relying on a mutable tag. The exact qualified image
set consumed by routine CI is recorded in `ci/images/qualified-images.json`; do
not change those identities without corresponding build, qualification,
publication, and registry-digest evidence. Application dependencies remain
repository/lockfile-owned and must not be baked into CI images.

Do not assume a job-container path is bind-mountable by that daemon. Integration
harnesses that need repository content inside a Docker container should transfer
staged content through the Docker API (for example `docker cp`) or another
explicitly supported transport rather than mounting `$PWD` into a remote daemon.

## 8. Packaging and release

Application packages contain runtime files, not contributor/build machinery.
`git archive` plus explicitly generated frontend assets is the current unsigned
candidate construction path. Keep `.gitattributes` and `.nextcloudignore`
aligned with the intended package boundary.

For a release:

1. identify the exact source commit/tree;
2. run the required deterministic and database/runtime gates;
3. build the canonical candidate once;
4. record its checksum;
5. perform the required Nextcloud integrity-signing/App Store steps on that
   candidate or a precisely documented signing transformation;
6. publish from Forgejo/release authority;
7. verify the published artifact and installation behavior independently.

Do not treat an unsigned CI artifact as a published App Store release, and do
not silently rebuild equivalent-looking bytes after qualification.

## 9. Roadmap authority

`docs/roadmap.md` owns planned milestones and foundation exit criteria.
Architecture belongs in architecture/domain documentation, current API behavior
in `docs/api.md`, and user-visible release history in `CHANGELOG.md`.

Do not begin packaged mobile implementation until the OCS synchronization contract is versioned and tested as required by the roadmap. The mobile direction is Vue offline-first PWA with Capacitor Android/iOS packaging; do not reintroduce a separate Kotlin/Compose/Room architecture without an explicit design decision.

## Activity ledger invariants

- Work definitions are configuration; activities are execution history.
- Activity `performedAt`, work items, linked identity snapshots, and meter snapshots are immutable once created. Only header summary/notes may be revised; archive/recreate to correct execution facts.
- Every activity and immutable child uses a client UUID and must remain idempotent for offline retries.
- Activity-created readings require an explicit unit and `source.type = activity` provenance, and must remain in the same serialized workspace transaction as the activity.

## v0.1.7 due-state invariant

Maintenance due state is derived only. Do not add a mutable due-status table or accept client-supplied status. `baseline_required` means no linked completion exists; `unknown` means the completion exists but one or more required rule inputs cannot be evaluated. `combination: any` must never report `upcoming` while an unevaluable rule could already be due.


## v0.1.8 forecast/occurrence invariant

Forecasting is a policy layer over `MaintenanceStatusService`; it must never become a second due-calculation implementation. `due_soon` is configurable attention policy, not maintenance truth. Materialized occurrences are workflow rows only: they may store occurrence identity, the definition identity, the completion-baseline UUID, and lifecycle timestamps/reasons, but they must not store authoritative due dates, due states, remaining values, or meter thresholds. Reconciliation must preserve at most one open occurrence per work definition and must close an occurrence when a later linked completion establishes a new baseline.

## v0.1.9 profile-installation invariant

Profile installation is orchestration over canonical domain services, not a second
persistence path. Preserve the immutable canonical JSON/SHA-256 revision snapshot
and source type/key/ordinal bindings. Only exact bundled ID/version/hash matches
are first-party. `profile.read` is read-only for all workspace roles;
`profile.install` remains an Owner/Manager serialized write. Do not silently map
profile v1, select one instance from a multi-instance source key, fetch arbitrary
profile/source URLs, or ignore profile-v2 parts before the parts subsystem can
materialize them. Upgrade handling must be an explicit user-approved diff/merge,
not a reinstall that overwrites customized records.

## v0.1.10 fitment interoperability invariant

Fitment slots use stable machine keys as interoperability identity; labels and
aliases are presentation/candidate-matching aids only. Core slot/qualifier keys
are append-only and extension keys use reverse-DNS-style namespaces. Imported
equipment targets describe reusable model/configuration classes, never owned-unit
UUID/VIN/serial/private history. Alias similarity must never silently attach an
import: Owner/Manager explicitly confirms target/slot mappings and those mappings
do not rename either side. Canonical JSON is the lossless source/hash authority;
CSV/ZIP is a bounded, versioned spreadsheet projection with archive traversal and
formula-injection defenses. Community export is privacy-minimized and excludes
local UUIDs, private identity/history/cost/preference data. Product URLs remain
inert metadata; do not introduce arbitrary server-side fetch. Profile-v2 parts
must eventually materialize through the same canonical parts/fitment services,
not a profile-only catalog.

### v0.1.10 fitment runtime invariant

Fitment-pack import and local-asset mapping are separate operations. Immutable source revisions and source bindings preserve provenance; standardized slots and canonical parts are ordinary reusable workspace records. `fitment.read` is read-only for all workspace roles; only Owner/Manager may import or map. Never silently map by aliases, overwrite canonical user-editable part/slot data from a new pack, strip punctuation when deciding part identity, fetch imported offer/source URLs server-side, or leak local UUID/name/serial/private operational data into a community export. `conflict`/`insufficient` mapping requires explicit confirmation. The current runtime accepts canonical JSON and the reviewed bounded CSV/ZIP projection; both must converge through the same validator/canonical hash and persistence path. CSV/ZIP must retain the exact root-file/header allowlist, model/configuration identifiers, 8 MiB compressed/32 MiB expanded bounds, aggregate row bound, traversal/symlink/duplicate/comment/encryption defenses, and versioned reversible spreadsheet formula escaping. Do not claim profile-v2 part materialization, activity parts, vendor management, or central costs are implemented until their checkpoints qualify.
