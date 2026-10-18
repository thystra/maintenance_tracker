# Privacy policy

**Effective date:** TBD at release

This policy describes how the **Maintenance Tracker app for Nextcloud** handles
personal data. It covers the app's own behavior only. The Nextcloud server the
app runs on, and everything else on it, is operated by whoever runs that server
(your organization, your hosting provider, or you), and their privacy policy
applies to the server as a whole.

## Summary

- The app stores its data only on the Nextcloud server where it is installed.
- The app makes no outgoing network requests and sends no telemetry, analytics,
  crash reports, or usage statistics.
- The developer of the app (Alan Johnson / ArgentWolf) does not receive, see, or
  process any data from any installation.
- The operator of the Nextcloud server is responsible for the data (the data
  controller, in GDPR terms).

## Who is responsible

Maintenance Tracker is self-hosted software. Whoever operates the Nextcloud
server decides to install the app, who may use it, how long backups are kept, and
who has administrator access. That operator is the controller for the personal
data processed with the app. Questions about your data on a particular server, or
requests to exercise your rights, should go to that server's administrator.

The app's developer provides the software only and has no access to any server
running it.

## What the app stores

All of the following is stored in the Nextcloud server's database, unless stated
otherwise.

| Data | Examples |
|------|----------|
| Workspaces | Name, type (personal or shared), owner |
| Members and roles | Nextcloud user IDs of members and their role (Owner, Manager, Contributor, Viewer) |
| Maintained items | Names, categories, manufacturer and model, components, specifications such as serial numbers or VINs, notes, relationships and assignments between items, and the item groups they are organised in (group names such as a room or a fleet) |
| Meters and readings | Distance, runtime hours, usage counts, with reading dates |
| Maintenance plans and history | Work groups, work definitions and schedules, completed maintenance records with notes, parts used, and meter values |
| Parts, vendors, and links | Part numbers, typical prices, vendor names, addresses, phone numbers, e-mail addresses, contact person names, and store or vendor web addresses entered by users. A vendor's contact person, phone number and e-mail address can identify a person and are personal data. |
| Costs | Amounts, currencies, dates, and descriptions |
| Evidence references | Which Nextcloud file is attached to which item, record, or cost, with its type (photo, receipt, invoice, ...) |
| Profiles and fitment packs | Maintenance profiles and parts-compatibility data that members install or import |
| Audit trail | An append-only log of changes: what changed, when, and the Nextcloud user ID of who changed it. It contains identifiers, not notes or file names. |
| Ownership transfers | Pending and completed workspace ownership offers between members |
| Reminder and calendar settings and delivery log | Each user's reminder choice and selected calendar, and which reminders and calendar entries were already delivered, so they are not sent twice |
| Account lifecycle record | A SHA-256 hash of a user ID and its state, used to make sure a re-created account with the same ID does not inherit a deleted account's data |

**Files.** Photos, receipts, and other documents uploaded through the app are
stored as normal files in a `Maintenance Tracker` folder in the **workspace
owner's** Nextcloud Files, shared with the workspace members according to their
role. Files restored from an export go to a `Maintenance Tracker imports` folder in
the Files of the user who restored them. Files you attach "from Files" stay where
they are.

**Nextcloud features the app uses.** When enabled on the server, the app creates:

- entries in the Nextcloud **Activity** stream (for example "a maintenance record
  was added"), which name items when they are displayed but store only identifiers;
- Nextcloud **notifications** for due tasks and ownership offers;
- all-day **calendar** events titled "Maintenance due", with no description, in a
  calendar the user selected themselves. They are not removed automatically after
  the work is done.

These are stored and kept by the respective Nextcloud components, under the
server's settings.

The app does not set cookies of its own and does not track how you use it. Like
any request to Nextcloud, using the app may appear in the server's web server and
Nextcloud logs, which the operator controls. The app does not write notes, file
names, or other content of your records to the logs.

## No outgoing connections

The app does not contact the developer or any third party. In particular:

- it has no telemetry, analytics, update checks, or crash reporting of its own;
- maintenance profiles and fitment packs are read from files you upload or that
  ship with the app, never downloaded from the internet;
- store and vendor links are only shown as links. The server never opens them.
  If you click one, your browser visits that website directly (without sending a
  referrer), and that website's own privacy policy applies. A Nextcloud
  administrator can switch store and vendor links off for the whole server.

Installing or updating the app through the Nextcloud App Store is handled by
Nextcloud itself, not by this app.

## Who can see what

Access is controlled per workspace:

- A **personal** workspace is visible only to its user.
- In a **shared** workspace, every member can read the workspace's items,
  plans, history, parts, costs, and evidence. Contributors can also add
  readings, maintenance records, costs, and evidence. Managers can also manage
  items, plans, parts, vendors, and corrections, and can see the member list and
  the audit trail. The Owner can also add and remove members, change workspace
  settings, and transfer ownership.
- Exports follow the same rules, except that only the Owner's export contains the
  member list and the audit trail.
- Members only see evidence files that Nextcloud lets them open.
- Nextcloud administrators and anyone with access to the server, its database,
  or its backups can technically access all data, as with any Nextcloud app.

## How long data is kept

The app keeps data until it is deleted; it has no automatic expiry.

- Archiving or removing an item in the app marks it as removed but keeps the
  record, so history, offline synchronization, and the audit trail stay
  consistent. Readings and completed maintenance records are never edited in
  place; a correction is stored as a new entry. The audit trail is append-only.
- A workspace and all of its records, including its audit trail, are deleted
  when it is removed as part of an account deletion (see below). The app
  currently has no separate function to delete a shared workspace or individual
  audit entries.
- Backups of the server are kept according to the operator's own policy.

## When an account is deleted

When a Nextcloud user account is deleted:

- the user's **personal workspace** and all of its records are deleted;
- shared workspaces **owned** by the user that have no other members are deleted;
- shared workspaces owned by the user that **do** have other members are not
  deleted. Ownership passes to the longest-standing Manager, or, if there is no
  Manager or the administrator set `owner_deletion_policy` to `orphan`, the
  workspace is kept without an owner until an administrator assigns one with
  `occ maintenance_tracker:workspace:assign-owner`;
- the user's memberships in other workspaces, pending ownership offers, and
  reminder and calendar delivery records are removed;
- files in the deleted user's own Nextcloud Files, including a workspace evidence
  folder they owned, are deleted by Nextcloud with the account. The app keeps the
  record that a file was attached, but the file itself is gone;
- in shared workspaces that continue to exist, audit entries keep the deleted
  user's ID, so the history of who changed what remains intact;
- a hash of the user ID is kept so that a new account with the same ID starts
  empty.

Disabling or removing the app does not delete its data from the database.

## Your rights

Under data protection laws such as the GDPR you have rights over your personal
data. They are exercised through the operator of your Nextcloud server. The app
supports them as follows:

| Right | How the app supports it |
|-------|-------------------------|
| Access | Every member can see their workspace data in the app, and can download everything their role can read with **Export data** (JSON, or ZIP with CSV files and optionally evidence files). |
| Rectification | Records can be edited. Readings and completed maintenance records are corrected by adding a correcting entry, which keeps the original for the record's history. |
| Erasure | Deleting the Nextcloud account deletes the personal workspace and other owned workspaces that nobody else uses (see above). Workspace owners can remove members. In a shared workspace that continues to exist, archived records and the append-only audit trail (which contains user IDs) remain; the app has no function to erase them individually, so such requests need the server operator. Vendor contact details (contact person, phone, e-mail, address) describe people outside Nextcloud: record only what you need, and clear the fields by editing the vendor, because deleting a vendor archives the record and keeps its fields. |
| Portability | **Export data** produces machine-readable JSON and CSV. An owner's export can be restored into a new workspace with **Restore an export**, on the same or another Nextcloud server. |
| Restriction and objection | Workspace owners control who is a member and with which role. Each user can turn off reminder notifications and calendar entries. Administrators can disable the app or turn off store and vendor links. |

Exports can contain notes, costs, serial numbers, and other personal details.
Store and share them carefully.

## Contact

- Questions about this policy or the app:
  [GitHub issues](https://github.com/thystra/maintenance_tracker/issues).
  Do not post personal data there.
- Security vulnerabilities: report privately as described in the
  [security policy](../SECURITY.md).
- Questions about your data on a specific server: contact that server's
  administrator.

## Changes to this policy

Changes are recorded in the repository history and noted in the
[changelog](../CHANGELOG.md). The effective date above shows when the current
version applies.
