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

## Workspace membership and audit endpoints

Authorization is capability-based. The stable role bundles are Owner, Manager,
Contributor, and Viewer; legacy `editor` input normalizes to Manager. Manager can
manage current inventory and read membership/audit data but cannot administer
membership. Contributor and Viewer are read-only on the current inventory API.

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

Planned resources include profiles/imports, meters/readings, common work definitions and occurrences, activities/service records, evidence, parts/costs, fuel entries, trips, calendar bindings, public report shares, external submissions, TCO reports, and mileage reports. Work definitions use `schedule: none` for unscheduled/ad-hoc work; any non-`none` schedule policy is scheduled maintenance.

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
