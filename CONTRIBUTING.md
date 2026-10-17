# Contributing

Thank you for helping with Maintenance Tracker.

## Where things live

- The primary repository, CI, and releases are on Forgejo:
  <https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud>
- GitHub is a mirror, used for issues and security reports:
  <https://github.com/thystra/maintenance_tracker>

## Reporting bugs and asking questions

Open an issue at <https://github.com/thystra/maintenance_tracker/issues>. Include
your Nextcloud, PHP, and database versions, the app version, steps to reproduce,
and any relevant lines from the Nextcloud log. Do not attach real receipts,
personal data, or credentials.

Report security problems privately as described in [SECURITY.md](SECURITY.md),
not in a public issue.

## Changing code or documentation

1. Read [docs/development.md](docs/development.md) for building and testing, and
   [AGENTS.md](AGENTS.md) for the rules every change must keep (authorization,
   transactions, privacy, localization, accessibility).
2. Keep changes small and focused, with one topic per commit and clear commit
   messages.
3. Run the checks listed in [docs/development.md](docs/development.md#checks).
   Rebuild and commit `js/` and `css/` when you change `src/`, and regenerate
   `openapi.json` when you change the API.
4. Update the documentation and add an entry under `[Unreleased]` in
   [CHANGELOG.md](CHANGELOG.md) for user-visible changes.
5. Every UI string must be translatable ([docs/localization.md](docs/localization.md))
   and every control accessible ([docs/accessibility.md](docs/accessibility.md)).

Open a pull request on either repository. Changes are merged on Forgejo and
then mirrored to GitHub.

## Licensing of contributions

The app is licensed under [AGPL-3.0-or-later](LICENSE), and contributions are
accepted under the same license. New source files carry SPDX headers
(`SPDX-FileCopyrightText` and `SPDX-License-Identifier: AGPL-3.0-or-later`).
Profile and fitment data must declare their source and license; see
[docs/licensing.md](docs/licensing.md).
