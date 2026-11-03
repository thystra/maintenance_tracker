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

Left navigation (with the workspace switcher at the top). Under *Items* the
sidebar shows the item group tree: each group expands to its subgroups, shows
how many items it holds (including subgroups), and has *Add subgroup*,
*Rename*, *Move* and *Delete* in its menu (Owner, Manager). *Items* itself
has *New group*.

| Page | Route | Purpose |
|------|-------|---------|
| Dashboard | `#/` | Landing page: counts (items by kind, overdue, due), *By group* (each top-level group and *Ungrouped* with item, overdue and due-soon counts; shown once groups exist), *Needs attention* (overdue/due/due soon/needs a first log), *Coming up* in the next 60 days, *Fuel economy* (items with fill-ups: 90-day and latest economy, linking to the Fuel tab; *Log fuel* in the card heading and on each row), recent activity (Owner/Manager, from the audit trail), quick links. Header: *Log maintenance*, *Log fuel* (Contributor and up, when an item has the Fuel tab), *Add item*. Empty workspace: one call to action, *Add your first item*. |
| Items | `#/items` | Search and category filter; each row opens the item and has *Log maintenance*, *Edit*, *Delete*. *Add item* and *New group* in the header; top-level groups and *Ungrouped* as shortcuts above the list. |
| Items in a group | `#/items?group=<uuid>` | Breadcrumbs (Items › House › Basement), the group's items including its subgroups, shortcuts to subgroups, *Add subgroup*, *Add item* (lands in this group), and *Rename/Move/Delete group* in the actions menu. `?group=none` lists ungrouped items. |
| Item | `#/items/<uuid>/<tab>` | Breadcrumbs when the item is in a group. Header: *Log maintenance*, *Log fuel* (items with the Fuel tab), and *Edit*/*Delete* in the actions menu. Tabs below. |
| Parts | `#/parts` | Part catalog: name, manufacturer and part number, usual vendor, typical price, what it fits. A row expands to fitment (add/remove) and store links. |
| Vendors | `#/vendors` | Shops and service providers with website, address, phone, e-mail, contact person and notes; a row expands to the details and extra links. |
| Profiles | `#/profiles` | *Workspace profiles* (edit, duplicate, export JSON, delete) and *Bundled profiles* (duplicate, export JSON), each with a summary of what it sets up. *New profile* and *Import profile* (a local JSON file up to 1 MiB) in the header (Owner, Manager). |
| Profile editor | `#/profiles/<uuid>` (`new` for a new one) | Section buttons at the top jump to *Details* (name, version, category, ID, author, data license, description, *Applies to*), *Meters*, *Components* (parent, quantity, compatible parts, notes), *Specs*, *Parts* (store links from an imported profile are kept), *Task groups*, *Tasks* (type, group, component, parts used, instructions, *Repeats* rules: time, business days, meter) and *Fuel* (units, fuel type, driving regimes with a default). Keys are made from names until edited. *Create/Save profile* in a sticky bar; server validation errors are shown above it. *Export JSON* downloads the current draft. |
| Settings | `#/settings/<section>` | Sections listed below. |

Item tabs:

| Tab | Contents |
|-----|----------|
| Overview | Maintenance status per scheduled task (with *Log*), details (category, make, model, year, serial, profile), notes, latest work. |
| Tasks | Recurring work: add/edit/delete; repeat by time, by meter, or whichever comes first; optional component, group and instructions. |
| History | Maintenance records (newest first) with readings, parts used and notes; files per record; delete. |
| Readings | Meters with their latest reading, *Add reading*, reading history, add/delete meter. |
| Fuel | Shown for items whose category or profile is a vehicle or that have a distance (odometer) meter; *Show/Hide the Fuel tab* in the item's actions menu overrides that per item. Period (30/90/365 days, this year, all time, custom range) and economy unit (MPG, km/L, L/100 km) filters; *Fuel economy* (average, best, worst, measured distance) and *Fuel spend* (spend, volume, cost per distance) cards; *By operating regime* table (average, best, worst, stretches, distance and fuel cost per distance for that regime's own stretches); *Economy over time* chart (hollow dots mark mixed stretches; a data table follows for screen readers); fill-ups with economy, price and receipts, edit/delete. *Add fill-up* (Contributor and up) and *Fuel options* → *Operating regimes* / *Fuel settings* / *Record odometer readings* (Owner, Manager). A note offers *Record odometer readings* when fill-ups have an odometer that is not a meter reading yet. |
| Costs | Totals per currency, entries with receipts, add/delete. Also the place for costs outside any task (fuses, wiper fluid, other consumables); amounts of 0 are accepted. |
| Files | Evidence linked to the item (upload into the workspace folder or attach from Files). |
| Setup | Maintenance profile (choose a bundled or workspace profile → preview → apply; paste JSON as an advanced option; *Save as profile* stores this item's setup in the workspace library), components and specifications, linked items (lasting relationships or time-bound assignments), task groups, parts that fit, work queue. |

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
9. **Organise items into groups.** Items → *New group* (or a group's menu →
   *Add subgroup*). Put an item in a group from its *Edit* dialog, or add it
   from the group's page. *Move* re-parents a group with everything in it;
   *Delete* asks for confirmation and moves the group's subgroups and items up
   one level, so no item is ever deleted with a group.

10. **Log a fill-up.** Item → Fuel → *Add fill-up*: date and time, odometer,
   amount of fuel, full or partial fill, *A previous fill-up was not recorded*
   when one was missed, regime, price (per unit or total), station and notes.
   The odometer is also saved as a reading, the price as a fuel cost entry;
   attach the receipt in the fill-up's files right after saving. The dialog
   says where the odometer goes: *Also records an odometer reading on
   “Odometer”*, or, for an item without a distance meter, that saving creates
   an *Odometer* meter in the item's distance unit (Owner, Manager). After
   saving, the item's meters, Readings tab and task forecasts update without a
   reload. Quicker: *Log fuel* on the Dashboard (header or *Fuel economy*
   card) opens the same dialog with a *Vehicle* picker of the items that show
   the Fuel tab, preselecting the vehicle used last on this device (else the
   one filled up most recently); with a single vehicle there is no picker. The
   item header has *Log fuel* too. Fill-ups logged before the item had a
   meter can be added to it with *Fuel options* → *Record odometer readings*
   (oldest first; values that would go backwards are skipped). *Fuel
   options* → *Operating regimes* adds, renames, deletes and picks the default
   regime (for example Empty, Loaded, Towing); *Fuel settings* sets units, the
   default fuel type and whether the tab is shown.

11. **Reuse a setup.** Item → Setup → *Save as profile* (name, version) stores
   the item's components, meters, specs, compatible parts, task groups, tasks
   and fuel regimes as a workspace profile; *Open in the profile editor* to
   refine it, then apply it to other items from their Setup tab. Or *Add item*
   → *Copy setup from* another item: the new item gets the same setup straight
   away. Neither copies history, readings, fill-ups, costs or files.
12. **Build or share a profile.** Profiles → *New profile*, *Duplicate* a
   bundled one, or *Import profile* from a JSON file; *Export JSON* gives a file
   to share. Nothing is fetched from the internet.

Dialogs close only after a successful save or on *Cancel*; if saving fails the
dialog stays open with the error.

## Known limits

- The dashboard and item pages read the live forecast. The materialized work
  queue (maintenance occurrences) is on the item's Setup tab, and Owners and
  Managers update it by hand there; nothing reconciles it in the background.
- Calendar entries for a deleted item or a completed task are not removed
  (public Calendar API limitation).
- Fitment-pack import, mapping and community export are API-only for now.
- Relationship type labels come from the server in English.
- Item groups are re-arranged with *Move* dialogs; there is no drag and drop
  in the sidebar yet.
- The profile editor does not warn about unsaved changes when you leave
  the page; profile upgrades (re-applying a newer version to an item that
  already has one) are not available yet.
- Deleting a fill-up between two others merges their stretches; mark the next
  fill-up *A previous fill-up was not recorded* instead when a fill-up is
  genuinely missing.
