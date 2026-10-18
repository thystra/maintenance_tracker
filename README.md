# Maintenance Tracker for Nextcloud

Maintenance Tracker is a self-hosted Nextcloud app for keeping track of the things
you maintain: vehicles, trailers, homes and building systems, appliances, tools,
medical devices, and anything else with a service schedule. It records what you
own, what needs doing and when, what was done, which parts were used, what it
cost, and the receipts and photos that go with it. All data stays in your
Nextcloud database and Files.

> **Status: pre-release (0.1 series).** The app is usable but has not had a
> signed App Store release yet. The web UI and the API may still change between
> versions, and some features are available only through the API so far (see
> [Current limitations](#current-limitations)).

## Features

- **A Nextcloud-style interface** – a dashboard of what is overdue, due and
  coming up; pages for items, parts, vendors and settings in the left
  navigation; and one quick “Log maintenance” dialog from the dashboard, the item
  list or an item. See [docs/ui.md](docs/ui.md).
- **Inventory** – maintained items with categories (built-in and custom), broad
  asset classes, nested components, structured specifications, typed
  relationships between items (for example a trailer towed by a truck), and
  effective-dated assignments.
- **Meters and readings** – distance, runtime hours, and usage counts. Readings are
  never edited in place; a mistake is corrected by a superseding reading, so the
  history stays intact.
- **Maintenance schedules** – work groups and work definitions scheduled by
  calendar interval, business days, or meter interval, or left unscheduled for
  ad-hoc work.
- **Due status and forecast** – each task is shown as upcoming, due soon, due,
  overdue, or as needing a baseline reading, calculated from your schedules,
  completed work, and meter readings. The forecast window and reminder policy are
  configurable per workspace.
- **Service history** – record completed maintenance with the work performed,
  meter values at the time, notes, and the parts used.
- **Maintenance profiles** – install a validated maintenance profile (profile-v2 installation
  from the bundled generic profile or your own JSON file) to create
  work definitions and parts in one step, with a preview before anything is
  written.
- **Parts, vendors, and fitment** – a parts catalog (usual vendor, typical price,
  which items a part fits), vendors with website, address, phone, e-mail and
  contact person, compatible parts per item, and portable fitment compatibility packs in JSON or a spreadsheet-
  friendly CSV/ZIP format.
- **Costs** – a cost ledger per item with exact amounts. By default one currency
  (USD) without tax lines; a workspace owner can choose the default currency and
  allow tax lines and multiple currencies. Currencies are never converted; totals
  are shown per currency.
- **Receipts, photos, and documents** – attach files to items, service records, and
  costs, either by uploading them or by picking files you already have in
  Nextcloud Files.
- **Shared workspaces** – every user gets a private workspace and can create
  shared ones, add members and rename them in Settings. Shared workspaces have Owner, Manager, Contributor, and Viewer roles, an append-only audit trail,
  and an ownership transfer that the new owner must accept.
- **Nextcloud integration** – changes appear in the Activity app, due and overdue
  tasks send Nextcloud notifications, and each user can choose one of their own
  calendars to receive all-day “Maintenance due” entries.
- **Export and restore** – download everything your role can see as JSON or a ZIP
  (JSON, spreadsheet-safe CSV, and optionally your evidence files), and restore an
  owner's export into a new workspace.
- **Accessibility and translations** – the interface is checked for labelled
  controls, keyboard use, and screen-reader announcements, and all interface text
  is ready for translation.

### Screenshots

No screenshots are published yet.

### Current limitations

- Importing fitment packs and mapping them to items are available through the
  [OCS API](docs/api.md) only; there is no UI for them yet.
- Calendar entries are not removed when a task is completed, because Nextcloud's
  public Calendar API does not support it.
- Restoring an export does not restore workspace members or the audit trail.
- The interface is English only until translations are contributed.
- There is no mobile app yet. A mobile client is planned (see the
  [roadmap](docs/roadmap.md)).

## Requirements

| Component | Supported |
|-----------|-----------|
| Nextcloud | 33, 34, 35 |
| PHP | 8.2 – 8.5, 64-bit, with the `zip` extension (Nextcloud 35 itself needs PHP 8.3 or newer) |
| Database | SQLite, PostgreSQL, MariaDB/MySQL |
| Background jobs | System cron is strongly recommended (reminders, Activity, and calendar entries run as background jobs) |

Each supported Nextcloud version is tested with SQLite, PostgreSQL, and MariaDB,
including upgrades from version 0.1.10.

## Installation

### Nextcloud App Store

Not available yet. The App Store release is planned for 1.0.

### Manual installation

No signed release archive is published yet, so install from the source
repository. Once releases are published on Forgejo, you can instead extract
`maintenance_tracker.tar.gz` from a release into your apps directory (see
[docs/releasing.md](docs/releasing.md#installing-an-archive-by-hand)). The built JavaScript and CSS are committed, so no build step is
needed to run the app.

1. Clone the repository into your Nextcloud apps directory. The app directory
   **must** be named `maintenance_tracker`:

   ```bash
   cd /path/to/nextcloud/custom_apps   # or another configured apps path
   git clone https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud.git maintenance_tracker
   ```

2. Enable the app as the web server user:

   ```bash
   sudo -u www-data php occ app:enable maintenance_tracker
   ```

3. Make sure Nextcloud's background jobs use [system cron](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/background_jobs_configuration.html).

To update, run `git pull` in the app directory and then `sudo -u www-data php occ upgrade`.

If you change the frontend sources, rebuild with Node.js 24 (`npm ci && npm run build`);
see [docs/development.md](docs/development.md).

## Configuration

### Administrator settings

Under **Administration settings → Maintenance Tracker**:

- **Allow store and vendor links** (on by default) – parts and vendors can carry
  https-only store links, which the server never opens. When switched off, no new
  links are stored and existing ones are hidden from everyone; they come back if
  the switch is turned on again. The app config key is `outside_links_enabled`;
  prefer the settings page, because changing it with `occ` may only take effect
  after the memory cache refreshes.

### Owner account deletion

When the owner of a shared workspace deletes their account, ownership passes to
the longest-standing Manager. Set the app config key `owner_deletion_policy` to
`orphan` to keep such workspaces without an owner instead, so an administrator
decides who takes over:

```bash
sudo -u www-data php occ config:app:set maintenance_tracker owner_deletion_policy --value=orphan
```

The default is `transfer_to_manager`. A workspace without a Manager is always
kept as orphaned. Personal workspaces, and shared workspaces that have no other
members, are deleted with the account.

### Workspace settings

Workspace owners set the default currency and whether tax lines and multiple
currencies are allowed in the cost panel. Owners and Managers set the
maintenance forecast and reminder policy. Each user chooses in **Reminders and
calendar** whether to get reminder notifications and which of their calendars
receives due dates.

### occ commands

| Command | Purpose |
|---------|---------|
| `maintenance_tracker:workspace:list-orphaned [--output=plain\|json]` | List workspaces that need an administrator to assign an owner |
| `maintenance_tracker:workspace:assign-owner <workspace-uuid> <user> [--remove-previous]` | Assign a workspace to a new owner; the previous owner becomes a Manager unless `--remove-previous` is given |
| `maintenance_tracker:integrations:run` | Run the Activity, notification, calendar, and offer-expiry jobs now instead of waiting for cron |

## Privacy

- All data is stored in your Nextcloud database. Uploaded evidence is stored in a
  `Maintenance Tracker` folder in the workspace owner's Files, shared with members
  according to their role, and counts against the owner's quota.
- The app makes no outgoing network requests and sends no telemetry. Store and
  vendor links are never fetched by the server.
- Members only see the evidence files that Nextcloud lets them open.
- Calendar entries contain only a neutral title and the due date.
- Exports can contain private notes, costs, and serial numbers. Store them
  carefully.

See the [privacy policy](docs/privacy-policy.md) for details, and
[docs/security.md](docs/security.md) for the security design.

## Export and restore

Open **Export or restore** in the workspace panel.

- **Export data** downloads everything your role can read in the current workspace,
  as one JSON file or as a ZIP with JSON, one CSV file per table (with formula
  escaping, so spreadsheet apps do not run cell contents), and optionally the
  evidence files you can open. Only owners get the member list and audit trail.
- **Restore an export** creates a new workspace that you own from a complete export
  made by a workspace owner. Evidence files in a ZIP are copied into
  `Maintenance Tracker imports/` in your Files. Members and the audit trail are not
  restored. Uploads are limited to 640 MiB.

## Translations

All interface text uses Nextcloud's translation system, but no translations
exist yet. See [docs/localization.md](docs/localization.md) if you want to help.

## Documentation

- [Documentation index](docs/README.md)
- [OCS API](docs/api.md) and the generated [OpenAPI contract](openapi.json)
- [Privacy policy](docs/privacy-policy.md)
- [Security and privacy](docs/security.md)
- [Roadmap](docs/roadmap.md)
- [Changelog](CHANGELOG.md)

## Support, bugs, and security

- Bugs, questions, and feature requests:
  [GitHub issues](https://github.com/thystra/maintenance_tracker/issues).
- Security vulnerabilities: please **do not** open a public issue. Follow
  [SECURITY.md](SECURITY.md).

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md) and
[docs/development.md](docs/development.md).

## Repositories

- Primary repository, CI, and releases: <https://forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud>
- GitHub mirror (issues and security reports): <https://github.com/thystra/maintenance_tracker>

## License

The app is licensed under [AGPL-3.0-or-later](LICENSE). Maintenance profiles and
fitment packs may carry their own compatible data licenses; see
[docs/licensing.md](docs/licensing.md).
