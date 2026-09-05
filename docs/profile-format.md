# Maintenance profile format

Profiles provide data templates for a class or exact model of equipment. They
are not live maintenance records and they never contain executable code.

The initial schema is [profile-v1.schema.json](../schemas/profile-v1.schema.json).
A conservative example is
[generic-car.json](../profiles/generic-car.json).

## Identity and versioning

Each profile declares:

- `schemaVersion`: currently `1`;
- a globally stable reverse-DNS-style `id`;
- semantic `version`;
- `name`, `category`, and description;
- data license, author, source URL, and optional source revision;
- applicability metadata;
- meter, component, part, and provisional maintenance-plan templates.

Changing a published profile creates a new semantic version and content hash.
Already installed assets retain the exact revision used.

### Scheduling-schema transition

The checked-in profile-v1 schema predates the common work-definition decision and
still represents scheduling as `maintenancePlans` plus `triggers`. That shape is
provisional foundation data, not the final installer contract. Before profile
installation is enabled, a later schema revision will use common work definitions
with a `schedule` property: `schedule: none` means unscheduled/ad-hoc work and any
non-`none` policy means scheduled maintenance. Existing profile-v1 data will need
an explicit versioned migration/import mapping rather than silent reinterpretation.

## Installation behavior

Applying a profile:

1. validates schema, size, depth, counts, URLs, and cross-references;
2. records profile provenance and content hash;
3. creates one component row per declared quantity;
4. creates meters, plans, and triggers;
5. links compatible part alternatives;
6. marks every created row with the source profile/key;
7. lets the user review and suppress unwanted components or plans.

The installer must not assume all assets have the same components. A generic
vehicle profile therefore avoids a fuel filter by default; a diesel-specific
profile can add two filter instances, while an EV profile can omit them.

Profile upgrades show:

- new items available to add;
- source items changed since installation;
- source items removed or deprecated;
- user-modified and suppressed items that will be preserved.

No upgrade silently overwrites user choices.

## Provisional profile-v1 trigger representation

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

A part has a manufacturer and part number. Components/plans reference part keys,
allowing equivalent products from multiple manufacturers. Offers contain only a
label, SKU, and HTTPS URL. The server does not request that URL in v1.

Part compatibility is informational. Users must verify fitment and maintenance
intervals against the manufacturer, qualified technician, or clinician.

## Trust and licensing

Imported profiles are untrusted user data:

- no HTML, scripts, expressions, credentials, or embedded binary data;
- HTTPS source/offer URLs only;
- bounded strings, arrays, nesting, components, and plans;
- rendered as escaped text;
- explicit SPDX data license and provenance;
- first-party and local profiles clearly distinguished from third-party files.

Generic factual profiles should use `CC0-1.0` when possible. Do not copy manual
prose, illustrations, or substantial tables without permission.
