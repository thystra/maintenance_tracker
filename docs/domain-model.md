# Domain model

This document describes the target model and identifies the portions already materialized. The current additive migrations create workspaces, memberships, assets, the change journal, append-only audit events, categories, component instances, structured specifications, relationships, assignments, meters, and immutable meter readings. Remaining tables arrive with their vertical slices.

All table names stay under Nextcloud's recommended 23-character limit. API IDs
are UUIDs; database primary keys are auto-incrementing `BIGINT`s.

## Workspaces and assets

`maint_spaces`
: A private or shared maintenance workspace.

`maint_members`
: Nextcloud user membership with `owner`/`manager`/`contributor`/`viewer` role. Authorization is capability-based; legacy `editor` persists only as a migration/runtime compatibility input and normalizes to `manager`.

`maint_categories`
: Built-in or custom broad categories, optionally hierarchical.

`maint_assets`
: A specific maintained object: vehicle, HVAC unit, tool, CPAP device, trailer,
  and so on. `asset_class` is a coarse behavior/relationship class independent
  of the user-facing category.

`maint_relationships`
: Typed relationships between independent assets. The row stores the canonical forward relationship key, source/target assets, optional context, and whether it is the contextual default. Inverse keys/labels are catalog metadata rather than separately stored relationship types.

`maint_assignments`
: Effective-dated operational associations between independent assets. A primary assignment is unique over an overlapping source/type/context time range. Assignments do not rewrite compatibility relationships or historical activity.

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

`maint_prof_defs`
: Future common work-definition templates. The provisional profile-v1 `maintenancePlans`/`triggers` representation is input compatibility only and is revised before profile installation becomes a product contract.

`maint_asset_prof`
: Which profile revision was materialized into an asset.

Profile installation will create ordinary asset components and work definitions while retaining source keys. Profile upgrades are user-approved diffs. Suppressed or customized records are never overwritten without an explicit choice.

## Meters and readings

> Implementation status: v0.1.4 materializes these tables and the corresponding OCS/service layer.

`maint_meters`
: An asset- or component-targeted meter definition. The initial implemented
  dimensions are `distance`, `runtime`, and `usage_count`. Each meter stores a
  canonical unit, a user-facing display/input unit, a monotonic flag, revision,
  and tombstone. Meter identity/target/dimension are stable; configuration edits
  use optimistic revision checks.

`maint_readings`
: An immutable timestamped observation. Each row stores its canonical integer
  value plus the normalized original decimal value/unit, source provenance,
  optional notes, and an optional `supersedes_id` correction link.

Implemented canonical values are deliberately integer and unit-stable. They are capped at JavaScript's safe-integer maximum (`9007199254740991`) so OCS JSON clients preserve them exactly:

- distance -> millimetres (`mm`);
- runtime/engine hours -> seconds (`s`);
- usage/event counts -> integer count (`count`).

Input/display units currently include miles/kilometres/metres/millimetres,
hours/minutes/seconds, and whole uses. Conversion is deterministic and does not
replace the retained original value/unit. For a monotonic meter, a new or
corrected observation must fit between the nearest effective observations on
both sides of its timestamp. Corrections insert a new reading that supersedes
the old row; existing readings are never updated or deleted.

## Plans, triggers, and work

The future maintenance model uses a common **work definition** for scheduled and
unscheduled work. A definition describes what may/should be done; an activity
records what actually happened.

A work definition contains a required scheduling property named `schedule`.
`schedule: none` means unscheduled/ad-hoc work. Any non-`none` policy means
scheduled maintenance and may reference time, distance, runtime hours, use
counts, condition measurements, or a reviewed combination policy. Oil changes,
inspections, turbocharger repairs, and transmission repairs therefore share one
underlying definition shape instead of separate scheduled/repair record types.

Profiles may provide definition groups such as Engine, Transmission, Cooling,
or HVAC, but those groups remain profile/user data. Asset display name/nickname
is also distinct from profile-defined structured identity fields. Profile fields
may carry type, group, order, validation, units, sensitivity, and summary-display
metadata.

An **activity** is the canonical event/transaction timeline entry. Maintenance,
repair, inspection, fuel/energy, trip, meter reading, usage event, and extensible
other activities can reference a primary asset and related assets. A maintenance
activity may satisfy one or more scheduled occurrences/definitions and record
parts, costs, notes, evidence, provider, and operating context. The exact table
split for definitions/activities remains intentionally deferred until its
vertical slice is implemented.

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

Evidence bytes live in Nextcloud Files; the database stores verified identity and
provenance. Planned first-class evidence kinds are `photo`, `video`, `receipt`,
`invoice`, `document`, and `other`.

Evidence linkage is many-to-many. One receipt or invoice can support several
work items, and one activity can have multiple photos, a video, receipt, invoice,
and other documents. Link rows are explicit rather than overloading a single
`document_id` field.

Blob retention is independent from activity/evidence-record retention. A policy
may prune a large media blob while retaining, when permitted, evidence identity,
original filename/type, original size, checksum, uploader/provenance, linked
activity, retention action/date, and audit provenance. Each evidence item can be
marked **Protect / Keep** so automated pruning cannot remove its blob. Storage
management should report total/protected/prunable bytes by media type, asset,
and age and simulate a policy before deletion.

`maint_costs` remains the central future cost ledger: date, integer minor amount,
ISO currency, category, vendor, notes, and links to domain records. Service/fuel
records reference costs rather than duplicating amounts.

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
: Monotonic per-workspace entity journal. `id` becomes the internal cursor; public cursors will be opaque encodings with expiry/version metadata.

`maint_audit`
: Implemented append-only audit stream with versioned event type, actor UID, subject, revision, level, bounded structured details, and timestamp. It is security/history provenance, not a substitute for the synchronization journal.

Mutable records include `revision`, `created_at`, `updated_at`, and
`deleted_at`. Historical readings, costs, service records, trip revisions, and
report snapshots are append-only where practical.

## Key invariants

- Every object lookup is constrained by an authorized workspace capability.
- Manager capabilities are explicit and do not include membership administration.
- Audit history is append-only and retains historical actor attribution for shared work.
- UUID knowledge is not authorization.
- A component row represents one real component instance.
- Money always has an amount and currency together.
- A profile ID and profile version are set or cleared together.
- User-modified profile materialization is never silently replaced.
- Calendar data is a projection, not the source of truth.
- TCO never combines currencies without an explicit conversion policy.
- A tax report preserves the exact rate/version and source records it used.
