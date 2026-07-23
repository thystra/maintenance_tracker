# Maintenance Tracker

Maintenance Tracker is a self-hosted Nextcloud app for recurring maintenance,
usage meters, service history, costs, vehicle mileage, and supporting documents.

The project is in its foundation phase. The first vertical slice provides a
Nextcloud 34 app shell, a private per-user workspace, and an authenticated OCS
API for creating and listing maintained assets. The architecture deliberately
supports a future offline-first Android client without making the first release
depend on that client.

## Platform targets

- Nextcloud 34
- PHP 8.2 through 8.5, with PHP 8.5 as the primary deployment target
- nginx with PHP-FPM is supported by Nextcloud; the app adds no nginx routes
- PostgreSQL, MariaDB/MySQL, and SQLite through Nextcloud's database APIs
- Node.js 24 for frontend builds
- Future Android client: compile/target SDK 36 (Android 16), minimum SDK 23

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
- Bounded cursor pagination and account-lifecycle cleanup
- Change journal foundation for future Android delta synchronization
- Versioned, data-only JSON profile schema with a generic starter profile
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

Run the local checks:

```bash
composer lint
composer test:unit
npm run lint
npm run stylelint
npm run typecheck
npm run validate:profiles
npm run build
```

Run the disposable Nextcloud 34 integration suite when Docker is available:

```bash
sudo bash tests/integration/nextcloud34-smoke.sh
```

CI repeats these checks on PHP 8.2 and 8.5, builds the frontend, exercises the
API and user-lifecycle behavior in Nextcloud 34, and publishes an explicitly
unsigned install-candidate archive. App Store releases need a separate
integrity-signing step and must not use that unsigned candidate as a published
release.

For development, clone or mount this repository at:

```text
<nextcloud>/custom_apps/maintenance_tracker
```

Then enable it:

```bash
sudo -u www-data php occ app:enable maintenance_tracker
```

The target Nidhoggur deployment should use Nextcloud's recommended system cron,
not AJAX background jobs, before calendar synchronization and reminders are
enabled.

## Documentation

- [Architecture](docs/architecture.md)
- [Domain model](docs/domain-model.md)
- [OCS API](docs/api.md)
- [Profile format](docs/profile-format.md)
- [Security and privacy](docs/security.md)
- [Licensing and distribution](docs/licensing.md)
- [Roadmap](docs/roadmap.md)

## License

The Nextcloud server app is licensed under
[AGPL-3.0-or-later](LICENSE). Profile data can carry a separate compatible data
license and must declare its provenance.
