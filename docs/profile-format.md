# Maintenance profile format

Profiles provide data templates for a class or exact model of equipment. They
are not live maintenance records and they never contain executable code.

The current common-work-definition schema is [profile-v2.schema.json](../schemas/profile-v2.schema.json). Profile v1 remains a compatibility format and is still validated; it is not silently reinterpreted as v2.
A conservative example is
[generic-car.json](../profiles/generic-car.json).

## Identity and versioning

Each profile declares:

- `schemaVersion`: `2` for the current common-work-definition format (`1` remains accepted as a compatibility format);
- a globally stable reverse-DNS-style `id`;
- semantic `version`;
- `name`, `category`, and description;
- data license, author, source URL, and optional source revision;
- applicability metadata;
- meter, component, part, work-group, and common work-definition templates. Meter templates map to `distance`, `runtime`, or `usage_count`.

Changing a published profile creates a new semantic version and content hash.
Already installed assets retain the exact revision used.

### Work-definition scheduling in profile v2

Profile v2 is the current common-work-definition format. Every `workDefinitions`
item is invalid unless it explicitly contains `schedule`. `schedule: none` means
intentional unscheduled/ad-hoc work; omission never means `none`. A non-`none`
policy currently uses `combination: "any"` with bounded calendar, configurable
business-day, or meter rules.

Profile v1 predates this model and still represents scheduling as
`maintenancePlans` plus `triggers`. It remains a separately validated compatibility
format. Importing v1 into the common model requires an explicit versioned mapping;
v1 data is never silently reinterpreted as profile v2.

## Installation behavior — v0.1.9

Runtime installation accepts **profile v2 only**. Profile v1 remains a separately
validated compatibility format; converting v1 to v2 requires an explicit future
mapping and is never implicit. Bundled files and pasted/local JSON pass through
the same server-side `ProfileValidator`. Source URLs are provenance metadata; the
server does not fetch a profile from an arbitrary URL.

Before a write, the desktop/API workflow validates and previews the profile against
the selected asset. Preview reports applicability, key conflicts, the exact
materialization counts, and whether the current implementation can install every
profile fact losslessly. Installation then:

1. validates the bounded data-only document again;
2. canonicalizes the normalized profile and records its SHA-256 content identity; meter-interval decimal values are normalized to a minimal decimal string, so semantically equivalent encodings such as `7500`, `7500.0`, and `"7500.000"` have the same profile identity;
3. snapshots the immutable profile revision and provenance in the workspace;
4. creates one ordinary component row per declared quantity;
5. creates ordinary meter, work-group, and common work-definition rows through
   the same canonical services used by manual entry;
6. resolves profile `meterKey` references to the UUIDs of the meters actually
   materialized on that asset;
7. records source type/key/ordinal -> materialized UUID bindings;
8. sets the asset's profile key/version only after materialization succeeds; and
9. records one bounded `profile.installed` audit event.

The whole write is covered by the existing account/workspace transaction and write
serialization boundary. A client-generated installation UUID makes a retry of the
same profile/asset operation idempotent. A reused installation UUID with different
data is a conflict. v0.1.9 allows at most one materialized profile on an asset;
profile upgrades remain the explicit-diff workflow described below.

Multi-instance component templates are materialized as independent component
instances. A profile may target a component from a work definition, or use it as
a component parent, only when that source component has quantity 1; otherwise the
reference would silently choose one of several instances and is rejected. Parent
cycles are also invalid. Asset-scoped work such as tire inspection/rotation is the
appropriate representation when a task applies to a multi-instance set.

Profile v2 already has a parts vocabulary, but the v0.1.9 installer intentionally
fails closed when `parts` is non-empty. Part facts are not discarded or flattened
into notes. v0.1.10 first defines a separate portable fitment-pack contract with
standardized service-position keys and explicit equipment matching/mapping. Once
canonical part/fitment persistence exists, profile-v2 `parts` and
`compatiblePartKeys` must materialize through that same catalog rather than a
profile-only parts table. See [fitment-pack-v1.md](fitment-pack-v1.md).

Materialized components, meters, groups, and work definitions are ordinary domain
records and remain editable/suppressible by the user. The immutable source snapshot
and bindings preserve what the profile originally supplied.

Profile upgrades show:

- new items available to add;
- source items changed since installation;
- source items removed or deprecated;
- user-modified and suppressed items that will be preserved.

No upgrade silently overwrites user choices. Upgrade diff/merge is not implemented
in v0.1.9.

## Profile-v1 compatibility trigger representation

Calendar trigger:

```json
{
  "type": "calendar",
  "interval": {
    "value": 6,
    "unit": "month"
  }
}
```

Meter trigger:

```json
{
  "type": "meter",
  "meterKey": "odometer",
  "interval": {
    "value": 5000,
    "unit": "mi"
  }
}
```

Multiple triggers use `combination: "any"` in schema v1. The task becomes due
when the first threshold is reached.

## Compatible parts

A part has a manufacturer and part number. Components/work definitions reference part keys,
allowing equivalent products from multiple manufacturers. Offers contain only a
label, SKU, and HTTPS URL. The server does not request that URL in v1.

Part compatibility is informational. Users must verify fitment and maintenance
intervals against the manufacturer, qualified technician, or clinician.

## Trust and licensing

Imported profiles are untrusted user data:

- no HTML, scripts, expressions, credentials, or embedded binary data;
- HTTPS source/offer URLs only;
- bounded strings, arrays, nesting, components, work definitions, and legacy v1 plans;
- rendered as escaped text;
- explicit SPDX data license and provenance;
- first-party and local profiles clearly distinguished from third-party files.

Generic factual profiles should use `CC0-1.0` when possible. Do not copy manual
prose, illustrations, or substantial tables without permission.
