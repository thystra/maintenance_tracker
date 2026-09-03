# Domain model

This document is the target model. The foundation migration currently creates
workspaces, memberships, assets, and the change journal. Remaining tables arrive
in additive migrations with their vertical slices.

All table names stay under Nextcloud's recommended 23-character limit. API IDs
are UUIDs; database primary keys are auto-incrementing `BIGINT`s.

## Workspaces and assets

`maint_spaces`
: A private or shared maintenance workspace.

`maint_members`
: Nextcloud user membership and `owner`/`editor`/`viewer` role.

`maint_categories`
: Built-in or custom broad categories, optionally hierarchical.

`maint_assets`
: A specific maintained object: vehicle, HVAC unit, tool, CPAP device, trailer,
  and so on. `asset_class` is a coarse behavior/relationship class independent
  of the user-facing category.

`maint_relations`
: Typed relationships such as vehicle `tows` trailer or generator `powers`
  building.

`maint_components`
: One row per actual component instance. Two fuel filters or two HVAC systems
  are two rows, not a quantity. Components may be nested and retain independent
  identity/history.

`maint_specs`
: Structured semantic specifications attached to an asset or component. Values
  are JSON-typed with optional unit, operating regime, and source provenance.
  This is descriptive/configuration data; calculation-critical facts should gain
  explicit validated semantics as the relevant subsystem is implemented.

## Versioned profiles

`maint_profiles`
: Stable profile identity, origin, and trust state.

`maint_prof_revs`
: Immutable version, schema version, SPDX data license, source, content hash,
  applicability, and import time.

`maint_prof_parts`
: Part definitions supplied by a profile revision.

`maint_prof_plans`
: Component and maintenance-plan templates.

`maint_asset_prof`
: Which profile revision was materialized into an asset.

Profile installation creates ordinary asset components and plans while retaining
source keys. Profile upgrades are user-approved diffs. Suppressed or customized
records are never overwritten without an explicit choice.

## Meters

`maint_meters`
: A distance, runtime, engine-hour, usage-count, or custom meter attached to an
  asset or component. It declares dimension, canonical unit, display unit, and
  whether readings should be monotonic.

`maint_readings`
: Immutable observations with time, canonical integer value, original value and
  unit, source, notes, and an optional superseded-reading reference.

Suggested canonical values:

- distance: millimetres;
- duration: seconds;
- liquid volume: microlitres;
- count: integer uses.

Corrections supersede old readings; they do not erase the audit trail.

## Plans, triggers, and work

`maint_plans`
: Maintenance, inspection, administration, or reorder plan attached to an asset
  or component. Includes title, instructions, notes, enabled state, and trigger
  combination (`ANY` initially).

`maint_triggers`
: Calendar or meter threshold. Multiple rows support “six months or 5,000
  miles, whichever comes first.”

`maint_occurrences`
: Materialized due occurrence used by reminders, calendar synchronization, and
  offline clients. At most one open occurrence per plan in the MVP.

`maint_service`
: Work performed, with local time/timezone, provider/location, notes, and
  status.

`maint_service_tasks`
: One service visit can complete several due occurrences.

`maint_service_parts`
: Parts and quantities actually used.

Admin/reorder work is a plan kind, not a separate scheduling engine. Every plan
and every service record supports free-form notes.

## Parts and vendors

`maint_parts`
: Manufacturer, part number, description, and optional profile provenance.

`maint_part_compat`
: Compatibility with a profile, asset, or component. This allows equivalent
  filters from several manufacturers.

`maint_vendors`
: A user-defined supplier.

`maint_offers`
: Vendor SKU, HTTPS product URL, notes, and last user-entered price.

The MVP never fetches arbitrary product URLs server-side. That avoids SSRF and
prevents accidental tracking or remote-content leakage.

## Files and costs

`maint_documents`
: Verified Nextcloud file ID, MIME type, size, hash, document kind, owner, and
  last-known path.

Explicit join tables associate a document with an asset, service record, cost,
fuel entry, or trip.

`maint_costs`
: The single cost ledger. It stores date, integer minor amount, ISO currency,
  category, vendor, notes, and optional links to domain records.

Service and fuel records reference their cost entry; they do not duplicate its
amount. Categories cover acquisition, fuel/energy, maintenance, repair,
insurance, registration, tax, financing, depreciation, disposal, and other.

## Vehicle extension

`maint_vehicle`
: VIN, plate metadata, propulsion/fuel type, default units, and annual odometer
  reconciliation settings.

`maint_contexts`
: Named operating configuration: empty, hauling, towing a specific trailer, or
  a custom load.

`maint_fuel`
: Fill time, odometer reading, exact volume, full/partial flag, missed-fill flag,
  fuel/energy type, station, context, notes, and linked cost.

Fuel economy is calculated only across valid full-fill boundaries and visibly
marks incomplete sequences. A fill's context alone cannot accurately describe
mixed driving; advanced mode therefore allows trip or odometer segments to carry
the operating configuration.

`maint_trips`
: Vehicle, driver, start/end local time and timezone, odometer values or exact
  distance, destination/area, purpose, classification, client/project,
  contemporaneous creation time, and attestation state.

`maint_trip_revs`
: Append-only prior values, editor, edit time, and correction reason.

Classifications separate business, commuting, personal, medical, charitable,
and other mileage.

`maint_rates`
: Jurisdiction, purpose, effective date range, integer rate, precision, source
  URL, and revision. Rates are not hardcoded into report code.

`maint_reports`
: Immutable report snapshot, selected method, rate revisions, included record
  IDs, totals, generated time, and content hash.

## Calendar and synchronization

`maint_cal_links`
: User, calendar URI, task/occurrence UUID, event UID, ICS filename, last
  projection hash, and sync status.

`maint_changes`
: Monotonic per-workspace entity journal. `id` becomes the internal cursor;
  public cursors will be opaque encodings with expiry/version metadata.

Mutable records include `revision`, `created_at`, `updated_at`, and
`deleted_at`. Historical readings, costs, service records, trip revisions, and
report snapshots are append-only where practical.

## Key invariants

- Every object lookup is constrained by an authorized workspace.
- UUID knowledge is not authorization.
- A component row represents one real component instance.
- Money always has an amount and currency together.
- A profile ID and profile version are set or cleared together.
- User-modified profile materialization is never silently replaced.
- Calendar data is a projection, not the source of truth.
- TCO never combines currencies without an explicit conversion policy.
- A tax report preserves the exact rate/version and source records it used.
