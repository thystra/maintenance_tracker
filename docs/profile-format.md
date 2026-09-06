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

## Installation behavior

Applying a profile:

1. validates schema, size, depth, counts, URLs, and cross-references;
2. records profile provenance and content hash;
3. creates one component row per declared quantity;
4. creates meter definitions and common work definitions from the selected profile revision;
5. links compatible part alternatives;
6. marks every created row with the source profile/key;
7. lets the user review and suppress unwanted components or work definitions.

The installer must not assume all assets have the same components. A generic
vehicle profile therefore avoids a fuel filter by default; a diesel-specific
profile can add two filter instances, while an EV profile can omit them.

Profile upgrades show:

- new items available to add;
- source items changed since installation;
- source items removed or deprecated;
- user-modified and suppressed items that will be preserved.

No upgrade silently overwrites user choices.

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
