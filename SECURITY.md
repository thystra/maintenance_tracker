# Security policy

## Reporting a vulnerability

Please **do not** open a public issue for a suspected vulnerability.

Report it privately through GitHub's private vulnerability reporting on the
mirror repository:

<https://github.com/thystra/maintenance_tracker/security/advisories/new>

(On the repository page: **Security** tab → **Report a vulnerability**.)

Please include:

- the affected app version or commit, and your Nextcloud, PHP, and database versions;
- steps to reproduce;
- the impact you expect;
- any suggested fix or mitigation.

Do not include real receipts, location data, credentials, or other personal data
in a report.

General bugs and support questions belong in
[GitHub issues](https://github.com/thystra/maintenance_tracker/issues).

## Supported versions

The project is pre-release. Security fixes are made on the latest development
version only.

## Scope

How the app handles personal data is described in the
[privacy policy](docs/privacy-policy.md).

The app's security and privacy design is described in
[docs/security.md](docs/security.md).

The GitHub mirror is used for issue and security intake only; the Forgejo
repository remains the source of truth for code, CI, and releases.
