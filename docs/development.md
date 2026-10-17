# Development

This page covers building, testing, and the CI pipeline. Read
[CONTRIBUTING.md](../CONTRIBUTING.md) first; the project rules that every change
must keep are in [AGENTS.md](../AGENTS.md) and
[NEXTCLOUD-DEVELOPMENT-GUIDANCE.md](NEXTCLOUD-DEVELOPMENT-GUIDANCE.md).

## Tooling

- PHP 8.2–8.5 with Composer
- Node.js 24 with npm
- Docker, for the integration tests (optional)

## Local development instance

Clone or mount the repository at `<nextcloud>/custom_apps/maintenance_tracker`
(the directory name must match the app id in `appinfo/info.xml`; the checkout
itself may have any name elsewhere), then enable it:

```bash
sudo -u www-data php occ app:enable maintenance_tracker
```

## Build

```bash
composer install
npm ci
npm run build
```

The built bundles in `js/` and `css/` are committed. Rebuild them with Node.js 24
and commit the result whenever you change anything under `src/`; CI fails if the
committed bundles differ from a fresh build.

## Checks

Run the deterministic checks before sending a change:

```bash
composer validate --strict
composer test
npm run validate:project
bash tests/validate-project-selftest.sh
npm run validate:profiles
npm run validate:fitment
npm run typecheck
npm run lint
npm run stylelint
npm run check:a11y
npm run l10n:check
npm run build
git diff --check
git diff --exit-code -- js css
```

### OpenAPI contract

`openapi.json` is generated from the controller docblocks with Nextcloud's
openapi-extractor. After changing a route, its parameters, or its status codes:

```bash
composer openapi:install   # once
composer openapi
```

Commit the regenerated file; CI fails when it is stale. See [api.md](api.md).

### Translations

See [localization.md](localization.md) for the rules on translatable strings and
`npm run l10n:extract`.

## Integration tests

The disposable Nextcloud integration suite needs Docker. It uses Nextcloud 34 by
default; set `NC_SMOKE_IMAGE=nextcloud:33.0.10-apache` or
`nextcloud:35.0.2-apache` for the other supported releases.

```bash
NC_SMOKE_DATABASE=sqlite bash tests/integration/nextcloud-smoke.sh
NC_SMOKE_DATABASE=pgsql bash tests/integration/nextcloud-smoke.sh
NC_SMOKE_DATABASE=mysql bash tests/integration/nextcloud-smoke.sh   # MariaDB 11.4
```

The upgrade test installs the app as of v0.1.10 (commit `6fc011d`; override with
`NC_UPGRADE_FROM`), adds data, upgrades to the working tree, and checks the
result. It needs the full git history:

```bash
NC_SMOKE_DATABASE=mysql bash tests/integration/upgrade-smoke.sh
```

PostgreSQL and MariaDB run in a sibling container on a user-defined Docker
network. On hosts whose firewall drops traffic on user-defined bridges (for
example mixed iptables-legacy/nft rules), set `NC_SMOKE_NETWORK_MODE=bridge` to
use the default bridge and address the database by container IP.

The harness stages the runtime app and copies it in with `docker cp`, so it does
not need to bind-mount the checkout and works with a remote or isolated Docker
daemon.

## Continuous integration

Forgejo CI is authoritative. It repeats the checks above, runs the integration
suite and the upgrade test on Nextcloud 33, 34, and 35 with SQLite, PostgreSQL,
and MariaDB, checks that `openapi.json` is current, and builds an explicitly
**unsigned** install-candidate archive with a checksum. That archive is for
testing only; App Store releases need a separate signing step.

Package-registry advisory queries are kept out of normal CI because registry
availability is an external input. Run the Forgejo **Dependency advisories**
workflow before a release and investigate real findings.

Deployment-specific details belong in infrastructure management, not in this
repository.
