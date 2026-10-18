<!--
  SPDX-FileCopyrightText: 2026 Alan Johnson
  SPDX-License-Identifier: AGPL-3.0-or-later
-->

# User interface

The web UI is a Nextcloud app page built with `@nextcloud/vue` 9 and
`vue-router` (hash history, so deep links such as
`/apps/maintenance_tracker/#/items/<uuid>/history` need no extra server
routes). This note describes how the UI is organized and why. The code lives
in `src/`: `App.vue` (shell and navigation), `router.ts`, `store.ts` (active
workspace, role, shared caches), `views/` (one per page) and `components/`.

## Principles

- **Nextcloud-native.** `NcContent`, `NcAppNavigation`, `NcAppContent`,
  `NcListItem`, `NcDialog`, `NcTextField`, `NcSelect`, `NcEmptyContent`,
  `NcActions`, `NcNoteCard`. No custom form controls except native date and
  date-time inputs.
- **Common tasks are quick; setup is out of the way.** Logging maintenance is
  one dialog from the dashboard, the item list, or an item. Optional details
  (meter reading, part used, cost) sit behind a switch in that dialog.
  Structural setup (profiles, components, specifications, links, task groups)
  lives on an item's *Setup* tab; workspace-wide options live in *Settings*.
- **People never type machine keys.** Keys that the API needs (task, meter,
  category, group keys) are derived from the title.
- **The UI follows the role.** Buttons for actions a role cannot perform are
  not shown (`store.ts` mirrors `AuthorizationCatalog`); the server still
  enforces every check.
- **Accessible and translated.** Every string goes through `t()`; each page
  sets the document title and a hidden `h1` through `NcAppContent`'s
  `page-heading`, and focus moves to the page heading after navigation. Tabs
  follow the WAI-ARIA tabs pattern; status changes are announced in live
  regions; dialogs are labelled; layouts reflow down to phone width.

## Information architecture

Left navigation (with the workspace switcher at the top):

| Page | Route | Purpose |
|------|-------|---------|
| Dashboard | `#/` | Landing page: counts (items by kind, overdue, due), *Needs attention* (overdue/due/due soon/needs a first log), *Coming up* in the next 60 days, recent activity (Owner/Manager, from the audit trail), quick links. Empty workspace: one call to action, *Add your first item*. |
| Items | `#/items` | Search and category filter; each row opens the item and has *Log maintenance*, *Edit*, *Delete*. *Add item* in the header. |
| Item | `#/items/<uuid>/<tab>` | Header: *Log maintenance*, and *Edit*/*Delete* in the actions menu. Tabs below. |
| Parts | `#/parts` | Part catalog: name, manufacturer and part number, usual vendor, typical price, what it fits. A row expands to fitment (add/remove) and store links. |
| Vendors | `#/vendors` | Shops and service providers with website, address, phone, e-mail, contact person and notes; a row expands to the details and extra links. |
| Settings | `#/settings/<section>` | Sections listed below. |

Item tabs:

| Tab | Contents |
|-----|----------|
| Overview | Maintenance status per scheduled task (with *Log*), details (category, make, model, year, serial, profile), notes, latest work. |
| Tasks | Recurring work: add/edit/delete; repeat by time, by meter, or whichever comes first; optional component, group and instructions. |
| History | Maintenance records (newest first) with readings, parts used and notes; files per record; delete. |
| Readings | Meters with their latest reading, *Add reading*, reading history, add/delete meter. |
| Costs | Totals per currency, entries with receipts, add/delete. |
| Files | Evidence linked to the item (upload into the workspace folder or attach from Files). |
| Setup | Maintenance profile (choose → preview → apply; paste JSON as an advanced option), components and specifications, linked items (lasting relationships or time-bound assignments), task groups, parts that fit, work queue. |

Settings sections:

| Section | Who can change it |
|---------|-------------------|
| Workspaces: switch, create a shared workspace, rename | Anyone creates; Owner renames |
| Members and ownership: members and roles, add/remove, transfer ownership | Owner (Manager can read) |
| Costs and currency: currency, tax lines, multiple currencies | Owner |
| Due soon window: lead days and meter lead percent (forecast policy) | Owner, Manager |
| Reminders and calendar: personal notifications and calendar | Each user, for themselves |
| Categories | Owner, Manager |
| Export and restore | Export: every role (filtered by role); restore creates a new workspace |

Server-wide options (outside links on or off) stay in Nextcloud's
administration settings.

## Main flows

1. **First run.** The dashboard shows an empty state with *Add your first item*.
   Saving the item opens it; its Overview suggests adding a task, and Setup
   offers a profile that adds a recommended set at once.
2. **Log maintenance.** From the dashboard (*Log maintenance* or *Log* next to
   a due task), an item row, the item header, or a task: choose the item and
   the task (or “Something else”), confirm the date, save. *Add a reading, part, cost or notes*
   reveals those optional fields. The forecast updates at once.
3. **Plan recurring work.** Item → Tasks → *Add task*: what, how often (time,
   meter, or both). Meter-based tasks need a meter (Readings tab).
4. **Record a reading.** Item → Readings → *Add reading*.
5. **Add a part or vendor.** Parts/Vendors → *Add*. A part can name its usual
   vendor, a typical price and an item it fits; more fits and store links are
   in the expanded row.
6. **Delete an item.** Items row menu or item header → *Delete item* →
   confirm. The item is soft-deleted (kept as an audited tombstone); files stay
   in Files.
7. **Share a workspace.** Settings → Workspaces → *Create a shared workspace*,
   then Members → *Add member* with a role. Ownership can be offered to an
   existing member, who accepts from the banner on their dashboard.
8. **Back up or move.** Settings → Export and restore.

## Known limits

- The dashboard and item pages read the live forecast. The materialized work
  queue (maintenance occurrences) is on the item's Setup tab, and Owners and
  Managers update it by hand there; nothing reconciles it in the background.
- Calendar entries for a deleted item or a completed task are not removed
  (public Calendar API limitation).
- Fitment-pack import, mapping and community export are API-only for now.
- Relationship type labels come from the server in English.
