# OCS API

The API is experimental until the offline/mobile synchronization contract is complete.

The machine-readable contract is [`openapi.json`](../openapi.json), generated from the controller docblocks by Nextcloud's [openapi-extractor](https://github.com/nextcloud/openapi-extractor). After changing a route, its parameters, or its status codes, run:

```sh
composer openapi:install   # once
composer openapi
```

and commit the result; CI fails when `openapi.json` is stale. Request bodies and parameters are fully described; response payloads are currently typed as generic objects (see the sections below for their fields).

Base path:

```text
/ocs/v2.php/apps/maintenance_tracker/api/v1
```

Clients send:

```http
OCS-APIRequest: true
Accept: application/json
```

Requests use the user's Nextcloud session or an app password obtained through
[Login Flow v2](https://docs.nextcloud.com/server/stable/developer_manual/client_apis/LoginFlow/index.html).
Mobile clients must never request or store the user's primary password.

OCS wraps response data:

```json
{
  "ocs": {
    "meta": {
      "status": "ok",
      "statuscode": 200,
      "message": "OK"
    },
    "data": {}
  }
}
```

## Implemented endpoints

### `GET /capabilities`

Returns the app/API version, stability, and feature flags. Clients must check this before enabling server-dependent features.

### `GET /workspaces`

Returns every workspace accessible to the current user, creating the personal workspace on first use. Each item includes the caller's normalized role and the workspace `revision`.

### `POST /workspaces`

Creates a shared workspace owned by the caller from `{"name"}` (1–120 printable characters) and returns it with HTTP 201. Any user may create one. Audit event `workspace.created`.

### `PATCH /workspaces/{uuid}`

Renames a workspace from `{"expectedRevision", "name"}`. Owner only (`workspace.settings.manage`); a stale revision is a 409 conflict. Audit event `workspace.renamed`.

### `GET /assets?workspace=<uuid>&limit=100&cursor=<opaque>`

Lists non-deleted assets in an authorized workspace. Omitting `workspace`
selects the personal workspace. `limit` may be 1–100. When `nextCursor` is not
`null`, pass it unchanged as `cursor` to retrieve the next page.

```json
{
  "workspace": "81fed19a-f95f-4c82-b769-40c4f07d475c",
  "items": [],
  "nextCursor": null
}
```

### `GET /assets/{uuid}?workspace=<uuid>`

Returns one non-deleted asset.

### `POST /assets?workspace=<uuid>`

Creates an asset. Body:

```json
{
  "asset": {
    "uuid": "b913571d-5405-4a88-bb59-2d670a5f93dc",
    "category": "vehicle",
    "name": "2020 Ford F-350",
    "manufacturer": "Ford",
    "model": "F-350",
    "modelYear": 2020,
    "serialNumber": null,
    "notes": null,
    "acquiredOn": "2024-06-01",
    "purchasePriceMinor": 6250000,
    "currency": "USD"
  }
}
```

The UUID is optional. When supplied, retrying the exact same create is
idempotent; reusing it with different data fails.

Money uses integer minor units: `6250000 USD` means `$62,500.00`.

### `PATCH /assets/{uuid}`

Body:

```json
{
  "expectedRevision": 3,
  "asset": {
    "notes": "Primary tow vehicle",
    "status": "active"
  }
}
```

The update succeeds only when the current revision is `3`. A stale write
returns `412`.

### `DELETE /assets/{uuid}`

Accepts `expectedRevision` and performs a soft delete. The retained tombstone is
needed for offline synchronization.

## Inventory expansion endpoints

`GET /categories` returns built-in and workspace-defined categories. `POST /categories` creates a custom category with a default broad asset class. Assets now return `assetClass`; when omitted on create, the category default is used.

`GET /assets/{uuid}/components` lists active component instances for an asset. `POST /assets/{uuid}/components` creates an individually identified component and may set `parentUuid` to form a nested component tree.

`GET /assets/{uuid}/specifications` lists structured specifications for the asset and its components. `POST /assets/{uuid}/specifications` creates a semantic key/label/value record with optional `unit`, `regime`, `componentUuid`, and provenance `source`.

These resources remain experimental. Update/archive contracts for categories, components, and specifications will be completed before the OCS API is declared stable.

## Item groups (0.1.15)

Item groups are workspace-scoped organisational containers that nest to any
depth ("Vehicles > Fleet 1", "House > Basement"). Every role with
`inventory.read` reads them; changes need `inventory.manage` (Owner, Manager).
Writes are journaled and audited (`item_group.created`, `item_group.updated`,
`item_group.moved` with `parentUuid`, `item_group.archived`, and
`asset.group_changed` with `groupUuid`).

### `GET /item-groups?workspace=<uuid>`

Returns `items` (active groups with `uuid`, `parentUuid`, `name`, `sort`,
`revision`, `createdAt`, `updatedAt`) and `assignments`, a list of
`{assetUuid, groupUuid}` for active items that are in a group. Clients build
the tree from `parentUuid` and count items per group, including subgroups.

### `POST /item-groups?workspace=<uuid>`

Body `{"group": {"name": "...", "parentUuid": "...|null", "sort": 0, "uuid": "..."}}`.
`name` is 1–120 characters; `uuid` makes retries idempotent. A workspace can
have at most 1000 groups.

### `PATCH /item-groups/{uuid}?workspace=<uuid>`

Body `{"expectedRevision": n, "group": {"name": "...", "sort": 0}}` renames or
re-sorts a group.

### `POST /item-groups/{uuid}/move?workspace=<uuid>`

Body `{"expectedRevision": n, "parentUuid": "...|null", "sort": 0}`. Moves the
group (with its subgroups and items) under another group or to the top level.
A move into the group itself or one of its subgroups is refused with 400.

### `DELETE /item-groups/{uuid}?workspace=<uuid>&expectedRevision=n`

Deletes (tombstones) the group. Nothing else is deleted: its subgroups and
items move up to its parent; items of a deleted top-level group become
ungrouped. Returns `movedGroups`, `movedItems` and `ungroupedItems`.

### `PUT /assets/{assetUuid}/group?workspace=<uuid>`

Body `{"groupUuid": "...|null"}`. Puts the item into a group or takes it out.
An item is in at most one group.

## Fuel log (0.1.16)

The fuel log records fill-ups ("fuel tickets") per item, groups them into
operating regimes and computes fuel economy. Reads need `activity.read`;
adding a fill-up needs `activity.create` (Owner, Manager, Contributor);
editing and deleting fill-ups need `activity.manage`; fuel settings and
regimes need `inventory.manage`. Writes are journaled and audited
(`fuel.created`, `fuel.updated`, `fuel.archived`, `fuel_regime.created`,
`fuel_regime.updated`, `fuel_regime.archived`, `fuel_settings.updated`).

Canonical storage is integer millimetres (odometer) and microlitres (volume);
the original value and unit are kept. Units: distance `mi`/`km`, volume
`gal` (US gallon)/`l`, economy `mpg`/`km_l`/`l_100km`; fuel types `gasoline`,
`diesel`, `e85`, `lpg`, `cng`, `other`. Economy is computed only between two
valid full fills: a fill-up marked `missedPrevious` or a non-increasing
odometer leaves that stretch unmeasured (`invalidReason` `missed_fill` or
`odometer`), and partial fills between full fills add their volume to the
stretch. A fill-up's regime describes the driving since the previous fill-up;
a stretch whose fill-ups name different regimes is `mixed` and counts in the
overall figures only.

### `GET /assets/{assetUuid}/fuel-settings?workspace=<uuid>`

Returns `settings`: `fuelTab` (`auto`, `on`, `off`), `shown` (whether the
Fuel tab is shown: `on`, or `auto` with a vehicle category/profile or a
distance meter), `distanceUnit`, `volumeUnit`, `economyUnit`, `fuelType`,
`odometerMeter` (the meter fill-ups record readings on, or `null`) and
`revision` (0 before the first change). Defaults are US units, or metric when
the item's odometer meter displays kilometres.

### `PUT /assets/{assetUuid}/fuel-settings?workspace=<uuid>`

Body `{"expectedRevision": n, "settings": {"fuelTab": "on", "distanceUnit":
"km", "volumeUnit": "l", "economyUnit": "l_100km", "fuelType": "diesel"}}`;
any subset of the fields. Units only change display and defaults; stored
fill-ups keep their own units.

### `GET /assets/{assetUuid}/fuel-regimes?workspace=<uuid>`

Returns `items`: `uuid`, `name`, `isDefault`, `sort`, `revision`. Exactly one
active regime is the default when any exist.

### `POST /assets/{assetUuid}/fuel-regimes?workspace=<uuid>`

Body `{"regime": {"uuid": "...", "name": "Towing", "isDefault": false, "sort": 0}}`.
The first regime of an item becomes the default; at most 50 per item.

### `PATCH /fuel-regimes/{uuid}?workspace=<uuid>`

Body `{"expectedRevision": n, "regime": {"name": "...", "sort": 0, "isDefault": true}}`.
Setting `isDefault: true` moves the default; it cannot be unset directly.

### `DELETE /fuel-regimes/{uuid}?workspace=<uuid>&expectedRevision=n`

Tombstones the regime. Fill-ups keep their reference (history shows the
name); the default moves to the first remaining regime.

### `GET /assets/{assetUuid}/fuel?workspace=<uuid>`

Returns `items` (fill-ups, newest first, each with a `segment` annotation:
`economy`, `distance`, `mixed`, `valid`, `invalidReason` for the stretch that
ends there), `openFills`, `settings` and `regimes`.

### `POST /assets/{assetUuid}/fuel?workspace=<uuid>`

Body `{"ticket": {...}}` with `uuid` (required, makes retries idempotent),
`filledAt` (ISO 8601, required), `volume` (decimal string, up to 3 decimals,
required), `volumeUnit`, `odometer` + optional `odometerUnit` and `meterUuid`
**or** `readingUuid`, `fullFill` (default true), `missedPrevious` (default
false), `fuelType`, `station` (≤255), `regimeUuid` (default: the default
regime), `totalCost` (decimal) + optional `currency`, and `notes` (≤2000).

The odometer creates a reading of source type `fuel` on `meterUuid` or on the
item's odometer meter, so schedules see the distance; monotonic meters refuse
a decreasing value with 400. Without a distance meter the value is kept on the
fill-up only. `readingUuid` links an existing reading instead. `totalCost`
creates a cost entry of category `fuel` linked from the fill-up (`cost`); the
price is never stored on the fill-up itself. Receipts are evidence with
`targetType` `fuel` (see Evidence endpoints).

### `GET|PATCH|DELETE /fuel/{uuid}?workspace=<uuid>`

`PATCH` takes `{"expectedRevision": n, "ticket": {...}}` with any create field
except `uuid` and `readingUuid`. Changing the odometer or time corrects the
reading the fill-up created (through the reading correction chain) or
detaches a linked reading; changing `totalCost` updates, creates or (with
`null`) archives the linked cost entry. `DELETE ...&expectedRevision=n`
tombstones the fill-up and archives its cost entry; the odometer reading
stays, because it is a valid observation. A stale revision returns 412.

### `GET /assets/{assetUuid}/fuel-stats?workspace=<uuid>&period=90d&unit=mpg`

`period` is `30d`, `90d`, `365d`, `year`, `all` or `custom` (with `from` and
`to` dates). Returns `period` (`from`, `to`), `overall` (`economy`, `best`,
`worst`, `segments`, `distance`), `regimes` (per regime `economy`, `best`,
`worst`, `segments`, `distance`), `mixedSegments`, `invalidSegments`,
`openFills`, `fills`, `volume`, `spend` and `costPerDistance` (per currency),
and `chart` (up to the last 60 measured stretches: `date`, `economy`,
`mixed`). Stretches belong to the period in which they end.

### `GET /fuel-summary?workspace=<uuid>`

For the dashboard: per item with fill-ups, `assetUuid`, `name`,
`lastFilledAt`, `economyUnit`, `lastEconomy` and `economy90d`.

## Profile builder (0.1.17)

Workspaces keep their own maintenance profiles next to the bundled ones.
Workspace profiles are ordinary profile-v2 documents: every create, update,
import, "save as profile" and setup copy runs the same `ProfileValidator` as
bundled profiles (1 MiB encoded bound, nesting depth 16, bounded lists,
unknown fields rejected, HTTPS-only `sourceUrl`/offer URLs, which are never
fetched). They are stored canonically in `maint_ws_profiles` and listed by
`GET /profiles` with `origin: workspace` and `trustState: local`; installing
one snapshots an immutable revision exactly like a pasted local profile.
The id of a bundled profile is reserved, so a workspace profile can never
pass as first-party. Reads need `profile.read`; writes need `profile.manage`
(Owner, Manager). Writes are journaled and audited (`workspace_profile.created`,
`workspace_profile.updated`, `workspace_profile.archived`); a setup copy is
audited as `asset.setup_copied`. At most 200 active profiles per workspace.

Profile v2 gained two optional sections (documents without them keep their
content hash): `specifications` (up to 512 `{key, label, value, unit?,
componentKey?, regime?}` entries, scalar values) and `fuel` (`{settings?:
{distanceUnit, volumeUnit, economyUnit, fuelType}, regimes: [{key, name,
isDefault}]}`, at most 50 regimes, unique names, exactly one default). On
installation specs are created per component instance; fuel settings are applied
only to an item still on its default fuel settings (otherwise preview lists a
notice), and regimes are added by name, a regime name the item already uses being
a preview conflict. `sourceUrl` is now optional.

### `GET /profile-library/{uuid}?workspace=<uuid>`

One workspace profile: the catalog fields of `GET /profiles` plus `uuid`,
`revision`, `updatedAt` and the normalized `profile` document.

### `POST /profile-library?workspace=<uuid>`

Body `{"uuid": "...", "profile": { ...profile-v2... }}` → 201. A retry with
the same UUID and the same canonical content returns the stored profile; the
same UUID with different content is 412. The `id`/`version` pair must be
unused in the workspace and `id` must not belong to a bundled profile (400).
This is also how the Profiles page imports a JSON file (read locally in the
browser and size-checked first; nothing is fetched).

### `PUT /profile-library/{uuid}?workspace=<uuid>`

Body `{"expectedRevision": n, "profile": { ... }}` replaces the document;
a stale revision is 412. Items already set up from it keep their setup.

### `DELETE /profile-library/{uuid}?workspace=<uuid>&expectedRevision=n`

Archives (tombstones) the profile.

### `POST /assets/{assetUuid}/save-as-profile?workspace=<uuid>`

Body `{"uuid": "...", "name": "Pickup setup", "id": "workspace.pickup", "version":
"1.0.0", "description": "..."}`; only `name` is required (the id defaults to
`workspace.<name as key>`, the author to the workspace name) → 201 with the new
workspace profile. The snapshot holds the item's active and suppressed
components (suppressed ones become `enabledByDefault: false`), meters,
specifications, compatible parts that have a manufacturer and part number,
work groups, work definitions with their schedules, and fuel settings and
regimes. It never holds readings, activities, fill-ups, costs, files,
identifiers such as serial numbers, or store links.

### Copy setup from an existing item

`POST /assets` accepts an optional top-level `copySetupFrom` (an item UUID in
the same workspace) next to `asset`. The new item gets the same snapshot as
"save as profile" materialized in the same transaction, without an installation
record; the response adds `setupCopied` counts. A retry with an existing asset
UUID does not copy again. History (readings, fill-ups, costs, activities,
files) is never copied.

## Meter and reading endpoints

Meter configuration and observations are separate resources. Initial dimensions
are `distance`, `runtime`, and `usage_count`. Canonical values are integer `mm`,
`s`, and `count`, capped at `9007199254740991` so JavaScript clients preserve them exactly, while each reading retains the normalized original value/unit.

Role bundles use separate capabilities: Owner/Manager have `meter.manage`,
`reading.create`, and `reading.correct`; Contributor has `meter.read` and
`reading.create`; Viewer has `meter.read` only.

### `GET /assets/{assetUuid}/meters?workspace=<uuid>`

Lists active meters for an authorized asset. A meter may target the asset itself
or one active/historical component through `componentUuid`.

### `POST /assets/{assetUuid}/meters?workspace=<uuid>`

Creates a meter. An optional client UUID makes exact retries idempotent.

```json
{
  "meter": {
    "uuid": "9c7f24c0-0d3a-4c6f-9c11-0b6f3e1e5e10",
    "componentUuid": null,
    "key": "odometer",
    "name": "Odometer",
    "dimension": "distance",
    "displayUnit": "mi",
    "monotonic": true
  }
}
```

Supported display/input units are `mi`, `km`, `m`, `mm` for distance;
`hour`, `min`, `s` for runtime (`h` is accepted as an input alias); and `use`
for usage count (`count` is accepted as an input alias). Usage-count values must
be whole numbers.

### `GET /meters/{uuid}?workspace=<uuid>`

Returns one active meter.

### `PATCH /meters/{uuid}?workspace=<uuid>`

Updates `key`, `name`, `displayUnit`, and/or `monotonic` using
`expectedRevision`. Meter target and dimension are immutable.

### `DELETE /meters/{uuid}?workspace=<uuid>`

Archives the meter using `expectedRevision`. Its immutable readings remain
available for historical reads.

### `GET /meters/{meterUuid}/readings?workspace=<uuid>`

Returns readings in observation order, including superseded rows. Each item
contains `canonicalValue`, `originalValue`, `originalUnit`, `supersedesUuid`,
`supersededByUuid`, and `effective`.

### `POST /meters/{meterUuid}/readings?workspace=<uuid>`

Creates an immutable observation. `observedAt` requires an ISO-8601 timestamp
with seconds and a timezone. Example:

```json
{
  "reading": {
    "uuid": "a1e81ef4-e63a-4ed7-9053-fcefe78275ab",
    "observedAt": "2026-09-01T12:00:00Z",
    "value": "100000.0",
    "unit": "mi",
    "source": {"type": "manual", "reference": null},
    "notes": null
  }
}
```

Exact retries with the same UUID/data are idempotent. On a monotonic meter, the
candidate must not decrease relative to the nearest effective predecessor or
exceed the nearest effective successor, so historical insertion is also checked.

### `POST /readings/{readingUuid}/corrections?workspace=<uuid>`

Creates a new immutable reading that supersedes the named reading. The original
row is retained. `observedAt` defaults to the corrected reading's timestamp when
omitted; supplying it permits an explicit corrected observation time. A reading
may be directly superseded only once, though correction chains are possible by
correcting the latest effective row. This endpoint requires `reading.correct`.

## Workspace membership and audit endpoints

Authorization is capability-based. The stable role bundles are Owner, Manager,
Contributor, and Viewer; legacy `editor` input normalizes to Manager. Manager can
manage current inventory and read membership/audit data but cannot administer
membership. Contributor and Viewer are read-only on inventory configuration. Contributor may record meter readings through `reading.create`; Viewer remains read-only.

### `GET /workspaces/{workspace}/members`

Returns workspace memberships for callers with `workspace.members.read`.

### `POST /workspaces/{workspace}/members`

Owner-only in the current bundles (`workspace.members.manage`). Body:

```json
{"member":{"userUid":"mechanic-helper","role":"contributor"}}
```

The target must be an existing Nextcloud user. Assignable roles are `manager`,
`contributor`, and `viewer`; owner transfer is not implemented.

### `PATCH /workspaces/{workspace}/members/{userUid}`

Changes a non-owner member role. Actor and target user lifecycle locks are held
in deterministic order with the workspace write lock.

### `DELETE /workspaces/{workspace}/members/{userUid}`

Removes a non-owner membership. It does not erase shared domain records or prior
audit actor attribution created by that user.

### `GET /audit?workspace=<uuid>&limit=100`

Returns newest-first append-only audit events for callers with `audit.read`.
Each event contains event type/version, actor UID, subject type/ID/revision,
level, bounded structured details, and timestamp. Free-form maintenance notes
and evidence/document contents are not copied into audit details.

## Ownership transfer endpoints

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/ownership-transfers` | Open transfers involving the caller |
| POST | `/api/v1/workspaces/{workspace}/ownership-transfers` | `{transfer:{toUid, expiresInDays?, leaveAfter?}}`, owner, 201 |
| GET | `/api/v1/ownership-transfers/{uuid}` | Participants. Pending offers include `quota` |
| POST | `/api/v1/ownership-transfers/{uuid}/accept` | `{acknowledgeQuota:true}`, recipient |
| POST | `/api/v1/ownership-transfers/{uuid}/decline` | Recipient |
| DELETE | `/api/v1/ownership-transfers/{uuid}` | Cancel, owner |
| POST | `/api/v1/ownership-transfers/{uuid}/resume` | Continue an interrupted transfer |
| POST | `/api/v1/ownership-transfers/{uuid}/abort` | Before the folder has moved |

occ commands:
- `maintenance_tracker:workspace:list-orphaned`
- `maintenance_tracker:workspace:assign-owner <workspace> <user> [--remove-previous]`

Workspace objects now include `ownerUid` and `orphaned`.

## Errors

- `400`: invalid or unknown fields.
- `403`: workspace does not exist for this user or the required capability is not granted.
- `404`: asset does not exist in the authorized workspace.
- `412`: stale revision or conflicting client-generated UUID.
- `429`: future upload/report rate limits.

Error messages must not reveal whether a workspace exists for another user.

## Planned synchronization contract

Before the API is marked stable:

- `GET /sync/changes?cursor=...`;
- idempotency/client-mutation IDs for every write;
- tombstone retention and full-resync behavior;
- consistent conflict payloads containing the current server revision;
- upload association and verified file ownership;
- generated OpenAPI checked in CI.


## Work groups and work definitions

The v0.1.5 candidate uses `maintenance_definition.read` for Owner/Manager/Contributor/Viewer reads and `maintenance_definition.manage` for Owner/Manager configuration. Every create request MUST contain `definition.schedule`; omission returns 400. The server does not default a missing field to `none`.

### `GET /assets/{assetUuid}/work-groups?workspace=<uuid>`

Lists active asset-scoped work groups.

### `POST /assets/{assetUuid}/work-groups?workspace=<uuid>`

Creates a work group. Client UUID retries are idempotent when the payload matches.

### `PATCH /work-groups/{uuid}?workspace=<uuid>` / `DELETE /work-groups/{uuid}?workspace=<uuid>`

Update/archive using `expectedRevision`. A group referenced by an active work definition cannot be archived.

### `GET /assets/{assetUuid}/work-definitions?scheduled=true|false&workspace=<uuid>`

Lists active common work definitions. Omit `scheduled` for all; `true` selects non-`none` schedules and `false` selects `schedule: none`.

### `POST /assets/{assetUuid}/work-definitions?workspace=<uuid>`

Creates a definition. Minimum unscheduled example:

```json
{
  "definition": {
    "key": "turbo_repair",
    "title": "Turbo repair",
    "kind": "repair",
    "schedule": "none"
  }
}
```

Scheduled example uses OR semantics:

```json
{
  "definition": {
    "key": "oil_change",
    "title": "Engine oil change",
    "kind": "maintenance",
    "schedule": {
      "combination": "any",
      "rules": [
        {"type": "meter", "meterUuid": "<uuid>", "interval": {"value": 7500, "unit": "mi"}},
        {"type": "calendar", "interval": {"value": 12, "unit": "month"}}
      ]
    }
  }
}
```

Business-day rules use `type: business_days`, interval unit `business_day`, and an explicit unique weekday list such as `["mon","tue","wed","thu","fri"]`. Meter rules must reference a meter on the same asset and use a compatible unit.

### `GET /work-definitions/{uuid}` / `PATCH /work-definitions/{uuid}` / `DELETE /work-definitions/{uuid}`

Read, update, or archive a definition. Mutations use `expectedRevision`. Component target is immutable; schedule may be replaced explicitly on update.

## Profile installation — v0.1.9

Profile installation accepts the current profile-v2 document shape only. Profile v1
continues to validate as compatibility input but is never silently mapped into the
runtime installer. All profile endpoints accept the normal optional `workspace` query
parameter. Profile/source URLs are provenance metadata only; the server does not fetch
arbitrary URLs while validating or installing a profile.

`profile.read` is available to Owner, Manager, Contributor, and Viewer.
`profile.install` is a serialized write capability available only to Owner and
Manager.

### `GET /profiles`

Lists bundled profile-v2 documents, followed by the workspace's own profiles
(`origin: workspace`, see *Profile builder (0.1.17)*), after the same server-side validation used for
local input. Each item includes profile ID/version/name/category, data license,
provenance/applicability, canonical SHA-256 `contentHash`, materialization summary,
`origin: bundled`, `trustState: first_party`, and the normalized `profile` document.
A profile is first-party only when ID, version, and canonical content hash exactly
match a bundled revision.

### `POST /profiles/validate`

Validates a client-supplied local profile without changing domain data.

```json
{
  "profile": {
    "schemaVersion": 2,
    "id": "org.example.vehicle",
    "version": "1.0.0",
    "...": "remaining profile-v2 fields"
  }
}
```

A successful response returns `valid: true` and normalized profile metadata including
its canonical SHA-256 content hash, materialization counts, origin, and trust state.
Unknown fields, invalid references, component-parent cycles, ambiguous references to
multi-instance component templates, incompatible meter units, and other bounded
profile-contract violations are rejected.

### `POST /assets/{assetUuid}/profiles/preview`

Body: `{ "profile": { ... } }`. Preview revalidates the document and reports
`applicable`, `installable`, `conflicts`, `warnings`, `notices` (since 0.1.17, for
example fuel settings that will be left alone) and `materializes` counts without
writing anything. Existing profile installation/profile metadata, meter/work-group/
work-definition key collisions, and applicability mismatches are surfaced here.
Since v0.1.10, profile-v2 `parts` materialize into the canonical workspace part catalog
by manufacturer/part-number identity (an existing or archived identity is reused), each
part key is recorded as a `part` source binding, and `compatiblePartKeys` on components
and work definitions become informational compatibility facts (see
`GET /assets/{assetUuid}/compatible-parts`). Preview reports a conflict, and installation
fails closed, when two profile part keys resolve to the same canonical identity. Part
`offers` become `profile`-sourced store links on the canonical part (an existing active
link with the same URL is reused) with `offer` source bindings; while the administrator
has disabled outside links, a profile carrying offers is a preview conflict instead.

### `POST /assets/{assetUuid}/profiles/install`

Owner/Manager-only explicit materialization request:

```json
{
  "installationUuid": "124a9d17-3f55-4550-92de-65392ad39d87",
  "profile": {
    "schemaVersion": 2,
    "id": "org.example.vehicle",
    "version": "1.0.0",
    "...": "remaining profile-v2 fields"
  }
}
```

`installationUuid` is a client-generated RFC 4122 version-4 UUID. Retrying the same
UUID for the same asset/profile ID/version/content hash is idempotent and returns the
same installation. Reusing it for different data is a precondition conflict. v0.1.9
permits one materialized profile per asset; profile upgrades are a later explicit
diff/merge workflow rather than an implicit reinstall.

Installation stores an immutable canonical profile-revision snapshot and source
bindings, then creates ordinary components, meters, work groups, and work definitions
through their existing domain services. Profile meter `meterKey` references are
resolved to the actual materialized meter UUID before schedule creation. The asset's
`profileKey`/`profileVersion` pair is updated only after successful materialization,
and the serialized transaction records a bounded `profile.installed` audit event.
Materialized domain records remain user-editable after installation.

### `GET /assets/{assetUuid}/profile-installation`

Returns `{ "installation": null }` when no profile has been materialized. Otherwise
it returns the installation UUID/timestamp, profile ID/version/content hash,
origin/trust state, license/provenance, and source bindings (`sourceType`, `sourceKey`,
`ordinal`, `targetUuid`) to the resulting canonical records.


## Fitment-pack runtime — v0.1.10 foundation

The current v0.1.10 runtime implements canonical **JSON** plus a bounded normalized
**CSV/ZIP** projection. `fitment.read` is available to Owner, Manager, Contributor,
and Viewer. `fitment.import` and `fitment.map` are serialized write capabilities
available only to Owner and Manager. Import never silently attaches an equipment
target to a local asset.

Canonical JSON endpoints:

- `POST /fitment-packs/validate` validates and canonicalizes a fitment-pack-v1 JSON document and returns its SHA-256 content identity.
- `POST /fitment-packs/preview` validates without writing and reports standardized-slot conflicts plus reasoned local-asset match suggestions.
- `POST /fitment-packs/import` accepts a client-generated `importUuid` and a JSON `pack`. Retrying the same UUID/data is idempotent. An already-imported immutable revision cannot be aliased under a second import UUID.
- `GET /fitment-packs/{importUuid}/export` returns the immutable canonical source revision, including source offers. While outside links are disabled the offers array is emptied and `offersHidden: true` is returned (the `contentHash` still names the stored revision); importing a pack that carries offers is a preview conflict.

CSV/ZIP projection endpoints use a multipart upload field named `bundle` and feed
the reconstructed pack through the same canonical validator/hash path:

- `POST /fitment-packs/bundle/validate` validates a normalized fitment CSV/ZIP bundle without writing.
- `POST /fitment-packs/bundle/preview` validates the bundle and returns the same importability/match preview as JSON.
- `POST /fitment-packs/bundle/import` accepts multipart `bundle` plus client-generated `importUuid`; JSON/ZIP representations of the same canonical pack revision share the same immutable content identity/idempotency rules.
- `GET /fitment-packs/{importUuid}/bundle` downloads the immutable source revision as `application/zip` using the normalized CSV projection; authenticated bundle downloads are returned with no-store/no-cache semantics.

The v1 bundle is limited to 8 MiB compressed and 32 MiB expanded, requires the
exact root-level file/header contract in `docs/fitment-pack-v1.md`, preserves
model/configuration identifiers in `equipment_identifiers.csv`, rejects
traversal/symlink/duplicate/comment/encrypted/non-STORE-or-DEFLATE archive entries,
and limits aggregate CSV data rows to 250,000. Spreadsheet-sensitive text is
reversibly escaped according to the manifest-declared
`leading-apostrophe-v1` scheme before RFC 4180 encoding. Canonical JSON remains
the lossless source/hash authority.

Mapping/query/export endpoints:

- `GET /fitment-packs/{importUuid}/targets` lists imported generalized equipment targets and current match suggestions.
- `GET /fitment-targets/{targetUuid}/matches` returns `exact`, `candidate`, `conflict`, or `insufficient` match states with reasons.
- `POST /fitment-targets/{targetUuid}/map` accepts `mappingUuid`, `assetUuid`, and optional `acceptConflict`. A `conflict` or `insufficient` mapping requires `acceptConflict: true`. Mapping is explicit and does not rename either source or local records.
- `GET /assets/{assetUuid}/fitments` returns standardized fitment-slot/part facts and their imported pack provenance.
- `POST /assets/{assetUuid}/fitment-export/community` rebuilds a reviewed generalized community JSON pack from mapped canonical facts and runs it through the same validator/canonicalizer. Local asset UUID/name, serial/VIN-like identity, notes, costs, receipts, and maintenance history are excluded. Offers are currently emitted as an empty array until an explicit local/source offer-selection policy is reviewed.
- `POST /assets/{assetUuid}/fitment-export/community/bundle` performs the same privacy-minimized community rebuild and downloads the result as normalized CSV/ZIP with the same no-store/no-cache response semantics.

Profile-v2 parts, parts used per activity, vendors, store links, and the cost ledger
build on these canonical part records; see the v0.1.10 sections below.

Future resources include evidence, parts/costs, fuel entries, trips, calendar
bindings, public report shares, external submissions, TCO reports, and mileage
reports. Work definitions use `schedule: none` for unscheduled/ad-hoc work; any
non-`none` schedule policy is scheduled maintenance.

User/owner IDs are never accepted when they can be derived from authentication.

## Relationship and assignment endpoints

### `GET /relationship-types`

Returns the built-in relationship catalog. Each definition contains the canonical
forward `key`, display/inverse labels, symmetry flag, and allowed source/target
asset classes. Clients create records with the forward `key`; `inverseKey` is a
traversal/display aid, not an independently accepted create-time type.

### `GET /relationships?workspace=<uuid>`

Lists active typed relationships. Endpoint asset references remain readable when
one of the assets has later been archived and expose `archived: true` so history
does not become unserializable.

### `POST /relationships?workspace=<uuid>`

Creates a relationship:

```json
{
  "relationship": {
    "uuid": "e2468a40-8738-4dbb-8e8c-509a3d81c60f",
    "sourceAssetUuid": "b913571d-5405-4a88-bb59-2d670a5f93dc",
    "targetAssetUuid": "c024682e-6516-4b99-8c6a-3e781b6fa4ed",
    "type": "tows",
    "context": "trip",
    "isDefault": true,
    "notes": null
  }
}
```

Both endpoint assets must currently be active and compatible with the selected
relationship type. A workspace can have at most one active default for the same
source/type/context tuple.

### `PATCH /relationships/{uuid}` and `DELETE /relationships/{uuid}`

Relationship identity (`sourceAssetUuid`, `targetAssetUuid`, and `type`) is
immutable. PATCH may change `context`, `isDefault`, and `notes`, guarded by
`expectedRevision`. DELETE creates a revisioned tombstone.

### `GET /assignments?workspace=<uuid>`

Lists active effective-dated assignments. As with relationships, archived asset
endpoints remain represented for history.

### `POST /assignments?workspace=<uuid>`

Creates an operational assignment:

```json
{
  "assignment": {
    "sourceAssetUuid": "b913571d-5405-4a88-bb59-2d670a5f93dc",
    "targetAssetUuid": "c024682e-6516-4b99-8c6a-3e781b6fa4ed",
    "type": "tows",
    "context": "trip",
    "isPrimary": true,
    "effectiveFrom": "2026-09-01",
    "effectiveUntil": null
  }
}
```

Dates use `YYYY-MM-DD` and an omitted/null `effectiveUntil` means indefinite.
Primary assignments may not overlap another active primary assignment for the
same source/type/context. This is an assignment/default rule only: a later
activity record may still truthfully record a different configuration.

### `PATCH /assignments/{uuid}` and `DELETE /assignments/{uuid}`

Assignment source/target/type identity is immutable. PATCH may change context,
primary status, effective dates, and notes with optimistic revision checking.
DELETE creates a revisioned tombstone.

Relationship/default and primary-assignment checks execute under a workspace
write serialization point. This matters for shared workspaces because two different member accounts otherwise have independent account-lifecycle locks.

## Activity ledger — v0.1.6

Implemented OCS endpoints:

- `GET /assets/{assetUuid}/activities` — list active activity history for an asset (`activity.read`).
- `POST /assets/{assetUuid}/activities` — atomically create an activity and immutable children (`activity.create`).
- `GET /activities/{uuid}` — read one activity (`activity.read`).
- `PATCH /activities/{uuid}` — Owner/Manager-only descriptive correction; only `summary` and `notes` are accepted (`activity.manage`).
- `DELETE /activities/{uuid}` — Owner/Manager archive/void operation with optimistic revision (`activity.manage`).

Creation requires a client-generated activity UUID and client UUIDs for every work item and meter snapshot. At least one work item is required. A work item may reference a work definition or provide explicit ad-hoc `title` and `kind` data. Meter snapshots either reference an existing reading observed exactly at `performedAt`, or provide a nested reading containing only `uuid`, `value`, `unit`, and optional `notes`. UUID, value, and unit are required. Nested readings are created with `source.type = activity` and the activity UUID as their source reference; callers cannot override observation time or source provenance. A delayed activity may create its reading against a meter that was archived after the field record was captured, while the ordinary standalone reading endpoint still requires an active meter.

Retries of the same activity UUID are idempotent when the semantic references and immutable child payload match. Snapshot comparison does not depend on the current mutable title of a linked work definition, so a delayed offline retry remains valid after configuration is renamed. Reusing a UUID with changed execution facts is a precondition conflict.

## Maintenance status — v0.1.7

`GET /assets/{assetUuid}/maintenance-status` returns a read-only derived projection for every non-deleted work definition on the asset. It uses the same `maintenance.definition.read` capability as work-definition reads.

Optional query parameter `asOf` accepts an ISO-8601 timestamp including seconds and timezone and is primarily useful for deterministic clients, reporting, and tests. If omitted, server time is used.

Response fields include `assetUuid`, `asOf`, and `items`. Each item embeds the current work definition plus `state`, `reason`, `lastPerformedAt`, `lastActivityUuid`, `rules`, and `triggerRulePosition`. Date rules expose `dueOn` and `remainingDays`; meter rules expose meter identity, current reading context, canonical baseline/due/remaining values, and the original schedule interval.

States are `inactive`, `unscheduled`, `baseline_required`, `upcoming`, `due`, `overdue`, and `unknown`. Due state is derived on every request and is never written to a database table. v0.1.7 intentionally does not invent a fixed "due soon" warning horizon; clients receive exact remaining values so a future explicit notification policy can be configured without changing the maintenance truth model.


## Maintenance forecast and reminder policy — v0.1.8

`GET /assets/{assetUuid}/maintenance-forecast` returns the v0.1.7 maintenance-status projection plus the effective workspace reminder policy and a `forecast` object on each definition. The maintenance `state` remains one of the v0.1.7 truth states; the nested policy state is separately `not_due`, `due_soon`, `due`, `overdue`, `setup_required`, `blocked`, `inactive`, or `unscheduled`.

The default policy is explicit and server-owned: 14 calendar days and 10 percent of the configured meter interval. `GET /reminder-policy` returns the effective values with `revision: 0` and `source: default` until a workspace policy is stored. `PATCH /reminder-policy` requires `expectedRevision`; Owner and Manager may set `calendarLeadDays` from 0 through 3650 and `meterLeadPercent` from 0 through 100. Contributor and Viewer may read but not change policy.

`GET /maintenance-forecast` summarizes the whole workspace for the dashboard in one call: every active, scheduled task of up to 500 items (`scannedAssets`, `truncated`), ordered `overdue`, `due`, `due_soon`, `blocked`, `setup_required`, then `not_due`, each with `asset {uuid, name, category}`, `definition {uuid, title, kind}`, `state`, `reason`, `lastPerformedAt`, `dueOn`, `remainingDays`, and `meter` when a meter rule triggers. It needs `maintenance_forecast.read` (every role) and also returns the effective `policy`.

`GET /assets/{assetUuid}/maintenance-occurrences` returns the materialized work queue. By default only open occurrences are returned; `includeClosed=true` includes history. Each occurrence exposes its UUID, work-definition UUID, completion-baseline activity UUID, lifecycle timestamps/reason, revision, and a `current` field containing the live forecast/status projection. Occurrence storage does not contain due dates, due state, remaining values, or meter thresholds.

`POST /assets/{assetUuid}/maintenance-occurrences/reconcile` is an explicit serialized projection write for Owner/Manager. Reconciliation creates an occurrence when the current forecast is `due_soon`, `due`, or `overdue`; preserves the existing row while the same completion baseline remains actionable; closes it as `completed` when a later linked activity becomes the baseline; and closes it as `not_actionable` or `definition_unavailable` when configuration no longer warrants an open work item. A database uniqueness constraint enforces one open occurrence per work definition.

## Canonical parts and vendors — v0.1.10

Parts and vendors are workspace records. Every member may read them (`part.read`, `vendor.read`); only Owner and Manager may create, correct, or archive them (`part.manage`, `vendor.manage`).

- `GET /parts?includeArchived=false` — list canonical parts ordered by manufacturer and part number.
- `GET /parts/{uuid}` — read one part, including an archived one.
- `POST /parts` with `{"part": {"uuid", "manufacturer", "partNumber", "description"}}` — create a canonical part. Identity is manufacturer + part number under the fitment-pack normalization (Unicode NFKC, whitespace collapse, case fold). When the identity already exists the existing record is returned with HTTP 200 instead of 201; an archived identity is restored. A client UUID already used by a different record is a precondition conflict.
- `PATCH /parts/{uuid}` with `expectedRevision` and `{"part": {...}}` — `description`, `notes`, `vendorUuid` (preferred vendor, must be an active vendor of the workspace, or `null`), `unitPrice` (typical price as a decimal string, zero allowed, or `null`) and `currency` (ISO 4217, defaults to the workspace currency) are mutable; identity fields are rejected as unknown. `POST /parts` accepts the same optional fields.
- Part responses include `notes`, `vendorUuid`, `vendorName`, `unitPrice`, `currency`, and `fits`: the items the part fits, each `{assetUuid, assetName, source}` where `source` is `manual` (recorded on the part) or `profile` (from a profile or fitment data).
- `POST /parts/{uuid}/fits` with `{"expectedRevision", "assetUuid"}` marks the part as fitting an item; `DELETE /parts/{uuid}/fits/{assetUuid}?expectedRevision=` removes a manual fit. Both bump the part revision and need `part.manage`.
- `DELETE /parts/{uuid}` with `expectedRevision` — archive. Archived parts stay referenced by fitments, recorded part usage, and costs.
- `GET /vendors`, `GET /vendors/{uuid}`, `POST /vendors`, `PATCH /vendors/{uuid}`, `DELETE /vendors/{uuid}` — same revision and archive semantics. Vendor names are not unique; vendors are identified by UUID. Fields: `name`, `notes`, `website` (https only, validated with the outside-link policy; refused while the administrator disables outside links and returned as `null` meanwhile), `address`, `phone` (digits, spaces and `+-()./` only), `email`, and `contactName`. The list response also carries `websitesEnabled`. Contact names, phone numbers and e-mail addresses can be personal data; see the privacy policy.

## Parts used per activity — v0.1.10

`POST /assets/{assetUuid}/activities` accepts an optional `parts` array (at most 100 entries) alongside `items` and `meters`. It is covered by the same `activity.create` capability, so Contributors may record parts they used but cannot create catalog parts.

Each entry requires a client `uuid`, a `partUuid` referencing an active canonical part, a `quantity`, and a `unit`; `vendorUuid` (active vendor) and `notes` are optional. `quantity` must be a positive decimal **string** (or integer) with at most three fractional digits, such as `"5.5"`. JSON floating-point numbers are rejected so binary rounding can never enter the ledger; the value is stored as an exact integer in thousandths. `unit` is one of `each`, `pair`, `set`, `kit`, `ml`, `l`, `fl_oz`, `qt`, `gal`, `g`, `kg`, `oz`, `lb`, `mm`, `cm`, `m`, `in`, `ft`.

Parts-used rows are immutable activity children. The part's manufacturer, part number, and description and the vendor name are snapshotted at capture so later catalog corrections or archival never rewrite history. Activity responses include `parts` with `uuid`, `partUuid`, `manufacturer`, `partNumber`, `description`, `quantity` (normalized decimal string), `unit`, `vendorUuid`, `vendorName`, and `notes`. Idempotent activity retries compare the parts payload too; reusing an activity UUID with different parts is a precondition conflict.

## Cost ledger — v0.1.10

Costs are workspace ledger entries attached to an asset and optionally linked to an activity on that asset, a vendor, and a canonical part.

Role visibility:

| Capability | Owner | Manager | Contributor | Viewer |
| --- | --- | --- | --- | --- |
| `cost.read` — list entries, totals, settings | yes | yes | yes | yes |
| `cost.create` — add an entry | yes | yes | yes | no |
| `cost.manage` — correct or archive an entry | yes | yes | no | no |
| `workspace.settings.manage` — change cost settings | yes | no | no | no |

Every member who can see an asset can see what was spent on it; this is deliberate for shared household/fleet workspaces. Contributors may record what they paid but cannot rewrite or remove history; corrections are Owner/Manager actions and are audited.

Amounts are exact. `amount` is a positive decimal **string** (or integer) with no more fractional digits than the currency's ISO 4217 minor unit (2 for USD/EUR, 0 for JPY, 3 for BHD/KWD, …); JSON floating-point numbers are rejected. Values are stored as signed 64-bit integer minor units (`amountMinor`) and rendered back as an exact decimal string (`amount`).

Workspace cost settings (defaults shown) are `defaultCurrency: "USD"`, `taxEnabled: false`, `multiCurrency: false`:

- With `multiCurrency: false` every entry uses `defaultCurrency`; any other `currency` is rejected. Multi-currency cannot be turned off, and the default currency cannot be changed, while active entries exist in another currency.
- With `multiCurrency: true` each entry stores its own active ISO 4217 code. There is **no exchange-rate conversion**: totals are always reported per currency and never summed across currencies.
- With `taxEnabled: false` entries are `lineType: "charge"` only. Enabling tax allows `lineType: "tax"` lines (category is always `tax`), reported separately as `tax` in totals. Disabling tax again keeps existing tax lines visible, correctable, and counted.

Endpoints:

- `GET /cost-settings` — current settings plus `source` (`default` until first saved) and `currenciesInUse`.
- `PATCH /cost-settings` with `expectedRevision` (0 before the first save) and `{"settings": {"defaultCurrency"?, "taxEnabled"?, "multiCurrency"?}}` — Owner only; audited as `cost_settings.updated` with the three resulting values.
- `GET /assets/{assetUuid}/costs` — active entries, per-currency `totals` (`chargesMinor`, `taxMinor`, `totalMinor`, their decimal strings, `count`), and settings.
- `POST /assets/{assetUuid}/costs` with `{"cost": {"uuid", "incurredOn" (YYYY-MM-DD), "description", "amount", "currency"?, "lineType"?, "category"?, "activityUuid"?, "vendorUuid"?, "partUuid"?, "notes"?}}` — categories are `parts`, `labor`, `fluids`, `fuel` (used by fuel tickets), `service`, `fees`, `other` (default). Retrying the same UUID with identical facts returns the existing entry; different facts are a precondition conflict.
- `GET /costs/{uuid}`, `PATCH /costs/{uuid}` with `expectedRevision` and any of `incurredOn`, `category`, `description`, `amount`, `currency`, `vendorUuid`, `partUuid`, `notes` (changing `currency` requires restating `amount`), `DELETE /costs/{uuid}` with `expectedRevision` — archive.
- `GET /costs/totals` — workspace-wide per-currency totals over active entries.

## Store and vendor links — v0.1.10

Links are https URLs attached to a canonical part or a vendor. Reading follows `part.read`/`vendor.read` (every role); adding and removing follow `part.manage`/`vendor.manage` (Owner/Manager). The server validates but never fetches them.

- `GET /parts/{uuid}/links`, `GET /vendors/{uuid}/links` — `{"enabled": bool, "items": [...]}`; items have `uuid`, `subjectType`, `subjectUuid`, `label`, `url`, `source` (`manual` or `profile`), `revision`, timestamps, and `archived`.
- `POST /parts/{uuid}/links`, `POST /vendors/{uuid}/links` with `{"link": {"uuid"?, "label", "url"}}` — at most 32 active links per subject; identical UUID retries return the existing link.
- `DELETE /parts/{uuid}/links/{linkUuid}`, `DELETE /vendors/{uuid}/links/{linkUuid}` with `expectedRevision` — archive.

URL rules: must begin with the literal lowercase `https://`; printable ASCII only (no whitespace or backslashes); ASCII domain or IPv4 host with optional port; no `user:password@`; at most 2048 characters. Anything else, including `javascript:`, `data:`, `http:`, and `//host` URLs, is a 400 validation error. Clients must render links with `target="_blank"` and `rel="noopener noreferrer"`.

Administrator switch: Administration settings → Maintenance Tracker → "Allow store and vendor links" (default on). When off, `GET` returns `enabled: false` with no items, `POST` fails with "Outside links are disabled by the Nextcloud administrator", profile offers and fitment-pack offers become import conflicts, and fitment source exports omit offers. Existing rows are kept and reappear when re-enabled.

## Evidence endpoints (v0.1.11)

All endpoints take `?workspace=<uuid>`. Capability `evidence-files` is advertised.

- `GET /evidence-folder`: the workspace folder as seen by you: `available`, `path`, `writable`, `ownerUid`, `freeBytes`, `quotaLimited`, `reason`. It never creates anything.
- `POST /evidence-folder/upload-target` with `{"target":{"targetType","targetUuid","fileName"}}` (Contributor+): ensures the workspace and asset folders and returns the fields above plus `fileName` (collision-resistant) and `uploadPath`. `PUT` the bytes to `remote.php/dav/files/<you><uploadPath>` with `If-None-Match: *`, then attach.
- `GET /evidence?targetType=asset|activity|cost&targetUuid=<uuid>`: active evidence for a target.
- `POST /evidence` with `{"evidence":{"uuid","kind","title?","path"|"fileId","notes?","links":[{"targetType","targetUuid"}]}}` (Contributor+): 201, idempotent by UUID. A file that is already active evidence in the workspace returns 412.
- `GET /evidence/{uuid}`, `PATCH /evidence/{uuid}` with `{"expectedRevision","evidence":{"kind?","title?","notes?"}}`, and `DELETE /evidence/{uuid}?expectedRevision=` (archive). Editing and archiving are allowed for the attaching Contributor, or for Owner/Manager.
- `POST /evidence/{uuid}/links` with `{"targetType","targetUuid"}`, and `DELETE /evidence/{uuid}/links/{targetType}/{targetUuid}`. The last link cannot be removed; archive instead.

Each item has `availability`: `available` (with `file.{fileId,name,path,currentSize,changed,filesUrl,downloadUrl,previewUrl}`), `not_shared`, or `missing` (`file: null`, `sha256: null`).

## Workspace export (v0.1.13)

### `GET /export?workspace=<uuid>&format=zip|json&includeFiles=0|1`

Capability `workspace.export` (every role; read-only). Feature `workspace-export` is advertised. Returns a download, not an OCS envelope: `application/json` (`format=json`) or `application/zip` (`format=zip`, default), with `Content-Disposition: attachment` and `Cache-Control: no-store`. `includeFiles=1` requires `format=zip`; an unknown format returns 400 and a non-member 403.

The JSON document (also `data.json` in the ZIP) has `format: "maintenance-tracker-export"`, `formatVersion: 1`, `appVersion`, `exportedAt`, `exportedBy`, `role`, `workspace{uuid,name,kind}`, a `sensitivity` note, `sections{included,omitted[{section,reason}]}`, `tables{<section>: rows}` and `evidence`.

- A section is included only when the caller's role holds that section's read capability. Members and audit need `workspace.members.read` / `audit.read` (Owner only today), so other roles get `reason: "not_permitted_for_role"`. Links are omitted with `outside_links_disabled` while the administrator switch is off, and fitment offer URLs are blanked.
- Rows are the stored columns minus `workspace_id` and `write_lock_token`. Local numeric `id`s are kept so foreign keys can be followed inside one export; UUIDs are the stable identities. Timestamps are Unix seconds.
- Not exported: the change journal, evidence folder/share bookkeeping, transfer state, notification/calendar delivery logs, and raw evidence rows.
- `evidence` uses the same viewer-scoped projection as `GET /evidence/{uuid}` (archived items included) without `filesUrl`/`downloadUrl`/`previewUrl`/`canChange`. Files you cannot open are `not_shared`/`missing` without names.

The ZIP contains `manifest.json` (format, CSV dialect `rfc4180`, UTF-8, `spreadsheetEscape: "leading-apostrophe-v1"`, sections, `files`, `skippedFiles`), `data.json`, `csv/<section>.csv` per included table, `csv/evidence.csv`, and with `includeFiles=1` the bytes of active evidence files you can open as `files/<evidenceUuid>-<sanitized name>`. Files over 100 MiB, or beyond 512 MiB in total, are listed in `skippedFiles` with `size_limit`. CSV cells starting with `= + - @ '`, tab, CR, LF or their full-width forms are prefixed with `'`.

Each export records audit event `workspace.exported` with `format`, `includeFiles` and the included section count.

## Workspace restore (1.0 prep)

### `POST /import` (multipart: `file`, optional `name`)

Restores a complete v0.1.13 export (`format=json` or `zip`) into a **new** shared workspace owned by the caller. Returns `201` with `{workspace, restored: {section: rows}, notRestored: ["members","audit"], evidence: {restored, skipped: [{evidenceUuid, reason}]}}`.

- Only exports made by an Owner (`manifest.role = owner`, `formatVersion = 1`) are accepted, because other roles' exports are filtered.
- Limits: upload 640 MiB, `data.json` 64 MiB, 250 000 rows, 20 000 ZIP entries; ZIP paths are checked and evidence must match its recorded size and SHA-256.
- Every column is checked against the live schema; unknown sections or columns, type mismatches and dangling references reject the whole file (`400`) and nothing is written.
- All workspace, asset, record and other identity UUIDs are regenerated and numeric IDs remapped; references in text columns are rewritten. Content hashes and source documents stay verbatim.
- Database rows are written in one transaction. Evidence files are written afterwards to `Maintenance Tracker imports/<name> <date>/` in the caller's Files and attached; failures there are reported in `evidence.skipped` and do not undo the restore.
- Members, the audit trail and the change journal are not restored. The event `workspace.imported` is audited in the new workspace.

## Integrations (v0.1.12)

These are the authenticated user's own preferences. They are not workspace-scoped and accept no user ID. Capabilities `activity-integration`, `notifications`, and `calendar-projection` are advertised.

- `GET /integrations/settings` returns `{"reminders": bool, "calendarUri": string|null, "calendarAvailable": bool}`. `reminders` defaults to `true`.
- `PUT /integrations/settings` with `{"settings":{"reminders"?: bool, "calendarUri"?: string|null}}`. `calendarUri` must name one of your own writable calendars from the list below, otherwise 400. `null` or `""` stops calendar projection. Events already written stay.
- `GET /integrations/calendars` returns `{"items":[{"uri","displayName","color"}]}`: your own enabled, writable, non-deleted calendars, excluding calendars shared with you.

Delivery happens in background jobs (`occ maintenance_tracker:integrations:run` runs them immediately):

- Nextcloud Activity (filter “Maintenance”, types `maintenance_tracker_maintenance`, `maintenance_tracker_evidence`, `maintenance_tracker_membership`, `maintenance_tracker_ownership`).
- Notifications: `transfer_offered` (object `ownership_transfer`), `transfer_declined` and `transfer_expired` (object `ownership_transfer_result`), and `maintenance_due_soon`, `maintenance_due`, `maintenance_overdue` (object `work_definition`, id = definition UUID).
- Calendar: all-day `Maintenance due` events with UID `maintenance-tracker-<sha256>@maintenance-tracker`.
