# OCS API

The API is experimental until the offline/mobile synchronization contract is complete.

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

Returns every workspace accessible to the current user, creating the personal workspace on first use. Each item includes the caller's normalized role.

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

Lists bundled profile-v2 documents after the same server-side validation used for
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
`applicable`, `installable`, `conflicts`, `warnings`, and `materializes` counts without
writing anything. Existing profile installation/profile metadata, meter/work-group/
work-definition key collisions, and applicability mismatches are surfaced here.
Profile-v2 part definitions remain valid profile vocabulary, but a non-empty `parts`
array makes the v0.1.9 preview non-installable because the v0.1.10 parts subsystem is
not yet available to preserve those facts losslessly.

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


## Fitment-pack JSON runtime — v0.1.10 foundation

The current v0.1.10 runtime implements the canonical **JSON** fitment-pack path. `fitment.read` is available to Owner, Manager, Contributor, and Viewer. `fitment.import` and `fitment.map` are serialized write capabilities available only to Owner and Manager. Import never silently attaches an equipment target to a local asset.

- `POST /fitment-packs/validate` validates and canonicalizes a fitment-pack-v1 JSON document and returns its SHA-256 content identity.
- `POST /fitment-packs/preview` validates without writing and reports standardized-slot conflicts plus reasoned local-asset match suggestions.
- `POST /fitment-packs/import` accepts a client-generated `importUuid` and a JSON `pack`. Retrying the same UUID/data is idempotent. An already-imported immutable revision cannot be aliased under a second import UUID.
- `GET /fitment-packs/{importUuid}/export` returns the immutable canonical source revision, including source offers.
- `GET /fitment-packs/{importUuid}/targets` lists imported generalized equipment targets and current match suggestions.
- `GET /fitment-targets/{targetUuid}/matches` returns `exact`, `candidate`, `conflict`, or `insufficient` match states with reasons.
- `POST /fitment-targets/{targetUuid}/map` accepts `mappingUuid`, `assetUuid`, and optional `acceptConflict`. A `conflict` or `insufficient` mapping requires `acceptConflict: true`. Mapping is explicit and does not rename either source or local records.
- `GET /assets/{assetUuid}/fitments` returns standardized fitment-slot/part facts and their imported pack provenance.
- `POST /assets/{assetUuid}/fitment-export/community` rebuilds a reviewed generalized community JSON pack from mapped canonical facts and runs it through the same validator/canonicalizer. Local asset UUID/name, serial/VIN-like identity, notes, costs, receipts, and maintenance history are excluded. Offers are currently emitted as an empty array until an explicit local/source offer-selection policy is reviewed.

CSV/ZIP import/export remains part of the fitment-pack-v1 interoperability contract but is **not yet implemented in the runtime**. Profile-v2 part materialization also remains fail-closed until the profile installer is explicitly bridged to these canonical part/fitment records. Activity parts-used rows, vendor management, and central costs remain later v0.1.10 checkpoints.

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

`GET /assets/{assetUuid}/maintenance-occurrences` returns the materialized work queue. By default only open occurrences are returned; `includeClosed=true` includes history. Each occurrence exposes its UUID, work-definition UUID, completion-baseline activity UUID, lifecycle timestamps/reason, revision, and a `current` field containing the live forecast/status projection. Occurrence storage does not contain due dates, due state, remaining values, or meter thresholds.

`POST /assets/{assetUuid}/maintenance-occurrences/reconcile` is an explicit serialized projection write for Owner/Manager. Reconciliation creates an occurrence when the current forecast is `due_soon`, `due`, or `overdue`; preserves the existing row while the same completion baseline remains actionable; closes it as `completed` when a later linked activity becomes the baseline; and closes it as `not_actionable` or `definition_unavailable` when configuration no longer warrants an open work item. A database uniqueness constraint enforces one open occurrence per work definition.
