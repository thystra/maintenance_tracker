# Maintenance Tracker

Maintenance Tracker is a self-hosted Nextcloud app for recurring maintenance,
usage meters, service history, costs, vehicle mileage, and supporting documents.

The project is in its early 0.1-series implementation phase. The current vertical
slices provide the Nextcloud 33–35 foundation plus inventory, relationships, meters,
work definitions, activity history, due/forecast workflow, and validated local or
bundled profile installation into canonical domain records. The architecture deliberately supports
a future offline-first mobile client without making the first release depend on it.

## Repository authority

The authoritative development repository is:

```text
https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud
```

GitHub is maintained as a downstream mirror. The private GitHub security
advisory endpoint documented in `SECURITY.md` remains an explicit vulnerability
reporting channel, but GitHub is not the source, CI, or release authority.

## Platform targets

- Nextcloud 33, 34, and 35 (Nextcloud 35 itself requires PHP 8.3 or newer)
- PHP 8.2 through 8.5, with PHP 8.5 as the primary deployment target
- nginx with PHP-FPM is supported by Nextcloud; the app adds no nginx routes
- PostgreSQL, MariaDB/MySQL, and SQLite through Nextcloud's database APIs
- Node.js 24 for frontend builds
- Future mobile client: Vue offline-first PWA with Capacitor packaging for Android/iOS when native capabilities are needed

The repository directory may have any name. When installed in Nextcloud, the
app directory must be named `maintenance_tracker` so it matches
`appinfo/info.xml`.

## Current foundation

- Classic Nextcloud PHP app using only public `OCP` APIs
- Vue 3 web shell based on the current Nextcloud app template
- Authenticated, versioned OCS API under
  `/ocs/v2.php/apps/maintenance_tracker/api/v1`
- Private workspace created lazily for each Nextcloud user
- Asset records with stable UUIDs, revisions, timestamps, and tombstones
- Custom categories, broad asset classes, nested component instances, and structured specifications
- Typed class-compatible asset relationships and effective-dated assignments
- Workspace-wide mutation serialization for invariants that span multiple members
- Capability-based Owner/Manager/Contributor/Viewer workspace authorization
- Shared-workspace membership API with lifecycle-safe grants and role changes
- Append-only audit events for implemented domain and membership mutations
- Asset/component meters with immutable distance, runtime, and usage-count readings
- Work groups and common work definitions with explicit calendar, business-day, and meter schedules
- Maintenance activity ledger with immutable performed-work items and meter snapshots
- Configurable maintenance forecasting plus a materialized one-open-occurrence work queue
- Bounded cursor pagination and account-lifecycle cleanup
- Change journal foundation for future mobile delta synchronization
- Validated profile-v2 installation from bundled or local JSON, with immutable SHA-256 revision provenance, source bindings, preview/conflict checks, and a generic starter profile
- Common work-definition/scheduling foundation with required explicit `schedule`; `schedule: none` means unscheduled/ad-hoc work
- Architecture, domain, API, security, licensing, and delivery roadmap

The UI and API are explicitly pre-release. Do not treat the current API as a
stable third-party contract yet.

Maintenance due state is derived from schedules, completed activities, and effective meter readings; it is not persisted. Forecast/reminder policy may classify an upcoming item as `due_soon`, while occurrence rows materialize workflow attention without copying due truth into storage.

## Development

Install PHP dependencies:

```bash
composer install
```

Install frontend dependencies and build:

```bash
npm ci
npm run build
```

Run the deterministic local checks:

```bash
composer validate --strict
composer test
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

Run the disposable Nextcloud integration suite when Docker is available (it uses
Nextcloud 34 by default; set `NC_SMOKE_IMAGE=nextcloud:33.0.10-apache` or
`nextcloud:35.0.2-apache` for the other supported releases):

```bash
NC_SMOKE_DATABASE=sqlite bash tests/integration/nextcloud-smoke.sh
NC_SMOKE_DATABASE=pgsql bash tests/integration/nextcloud-smoke.sh
```

The integration harness stages the runtime app and transfers it with
`docker cp`; it does not require the Docker daemon to bind-mount the source
checkout. This makes the same harness usable with a local daemon and with the
isolated Docker daemon used by the Forgejo workstation runners.

Authoritative Forgejo CI repeats the PHP/frontend checks, exercises the app on
Nextcloud 33, 34, and 35 with SQLite and PostgreSQL, and publishes an explicitly unsigned
install-candidate archive plus checksum. App Store releases need a separate
integrity-signing step and must not treat that unsigned candidate as a published
release.

Live package-registry advisory queries are intentionally separate from normal
CI because registry availability and advisory state are external inputs. Run the
Forgejo **Dependency advisories** workflow before release and investigate real
findings without treating a registry outage as a product regression.

For development, clone or mount this repository at:

```text
<nextcloud>/custom_apps/maintenance_tracker
```

Then enable it:

```bash
sudo -u www-data php occ app:enable maintenance_tracker
```

Production deployments should use Nextcloud's recommended system cron, not AJAX
background jobs, before calendar synchronization and reminders are enabled.
Environment-specific deployment details belong in infrastructure management,
not this public application repository.

## Documentation

- [Project/agent guidance](AGENTS.md)
- [Architecture](docs/architecture.md)
- [Product architecture](docs/product-architecture.md)
- [Domain model](docs/domain-model.md)
- [OCS API](docs/api.md)
- [Profile format](docs/profile-format.md)
- [Security and privacy](docs/security.md)
- [Nextcloud app engineering guidance](docs/NEXTCLOUD-DEVELOPMENT-GUIDANCE.md)
- [Licensing and distribution](docs/licensing.md)
- [Roadmap](docs/roadmap.md)

## License

The Nextcloud server app is licensed under
[AGPL-3.0-or-later](LICENSE). Profile data can carry a separate compatible data
license and must declare its provenance.

### v0.1.10 parts, fitment, links, and costs

v0.1.10 implements portable fitment compatibility packs through canonical JSON and a bounded normalized CSV/ZIP spreadsheet projection: standardized service-position slots, canonical parts, immutable import provenance, reasoned equipment matching with explicit Owner/Manager mapping, source export, and privacy-minimized community export. It also adds a part and vendor catalog, parts used per activity, profile-v2 part installation, https-only store/vendor links that a Nextcloud administrator can switch off (Administration settings → Maintenance Tracker), and a central cost ledger. Costs default to one currency (USD) without tax lines; a workspace Owner can enable tax lines and multiple currencies. Amounts are exact integer minor units, and totals are per currency with no exchange-rate conversion. Every member can view costs, Contributors can add them, and Owners/Managers correct or archive them.

### Workspace ownership transfer

Owners can hand a workspace and its evidence folder to another member, who must accept.
When an owner's account is deleted, a Manager inherits the workspace. Without a Manager it
is kept as orphaned for an administrator to reassign with occ. See `docs/architecture.md`.

### v0.1.12 Activity, notifications, and calendar

Workspace changes (maintenance records and tasks, evidence, membership, ownership transfers) appear in the Nextcloud Activity app under “Maintenance”. Members get Nextcloud notifications when a task becomes due soon, due, or overdue (they can switch this off under “Reminders and calendar”), and ownership offers notify the recipient. Unanswered offers expire automatically. Each user can choose one of their own writable calendars to receive all-day “Maintenance due” entries for dated tasks. Delivery runs from Nextcloud background jobs, so configure system cron.

### v0.1.11 evidence in Nextcloud Files

Attach photos, receipts, invoices, documents, and video to assets, activities, and costs. "Upload" stores the file in the workspace folder `Maintenance Tracker/<Workspace>/<Asset>/` in the workspace owner's Files. That folder is shared with every member, read-only for Viewers and writable for Contributors and Managers, so everyone can browse it in Files. "Attach from Files" links any file you already have. Evidence follows renames and moves made in Files. Members only see files Nextcloud lets them open, and uploads count against the workspace owner's quota.
