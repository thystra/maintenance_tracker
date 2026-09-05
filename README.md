# Maintenance Tracker

Maintenance Tracker is a self-hosted Nextcloud app for recurring maintenance,
usage meters, service history, costs, vehicle mileage, and supporting documents.

The project is in its early 0.1-series implementation phase. The current vertical
slices provide the Nextcloud 34 foundation plus inventory categories, component
instances, structured specifications, typed cross-asset relationships, and
effective-dated operational assignments. The architecture deliberately supports
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

- Nextcloud 34
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
- Bounded cursor pagination and account-lifecycle cleanup
- Change journal foundation for future mobile delta synchronization
- Versioned, data-only JSON profile schema with a generic starter profile
- Common future work-definition model where `schedule: none` means unscheduled/ad-hoc work
- Architecture, domain, API, security, licensing, and delivery roadmap

The UI and API are explicitly pre-release. Do not treat the current API as a
stable third-party contract yet.

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

Run the disposable Nextcloud 34 integration suite when Docker is available:

```bash
NC_SMOKE_DATABASE=sqlite bash tests/integration/nextcloud34-smoke.sh
NC_SMOKE_DATABASE=pgsql bash tests/integration/nextcloud34-smoke.sh
```

The integration harness stages the runtime app and transfers it with
`docker cp`; it does not require the Docker daemon to bind-mount the source
checkout. This makes the same harness usable with a local daemon and with the
isolated Docker daemon used by the Forgejo workstation runners.

Authoritative Forgejo CI repeats the PHP/frontend checks, exercises the app on
Nextcloud 34 with SQLite and PostgreSQL, and publishes an explicitly unsigned
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
