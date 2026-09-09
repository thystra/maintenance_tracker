# Domain model

This document describes the target model and identifies the portions already materialized. The current additive migrations create workspaces, memberships, assets, the change journal, append-only audit events, categories, component instances, structured specifications, relationships, assignments, meters, immutable meter readings, work groups, common work definitions, normalized schedule rules, activity headers, immutable activity work items, and immutable activity meter snapshots. Remaining tables arrive with their vertical slices.

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

> Implementation status: v0.1.9 materializes the profile identity/revision/install/binding tables below.

`maint_profiles`
: Workspace-scoped stable profile identity plus reviewed origin/trust state. An
  exact bundled profile revision is `bundled`/`first_party`; otherwise validated
  user-provided JSON is `local`/`local`.

`maint_prof_revs`
: Immutable semantic version, canonical SHA-256 content hash, canonical JSON
  snapshot, SPDX-style data license, author/source URL, optional source revision,
  and import time. Reusing an existing profile ID/version with different canonical content is
  a conflict rather than an in-place rewrite. Representation-only decimal differences
  are normalized before hashing.

`maint_asset_prof`
: The profile revision materialized into an asset, with a client-generated
  installation UUID and installation timestamp. v0.1.9 permits one row per asset;
  upgrades/diff history are a later schema extension.

`maint_prof_bind`
: Source provenance for materialized records: `source_type`, source key, ordinal,
  and target UUID. Ordinals distinguish repeated component instances without
  turning `tire_1`, `tire_2`, etc. into profile schema fields.

Installation creates ordinary asset components, meters, work groups, and work
definitions while retaining source bindings. It does not maintain shadow copies of
those mutable domain records. Profile upgrades are user-approved diffs; suppressed
or customized materialized records are never overwritten silently.

Profile v2 part definitions remain validated input vocabulary, but v0.1.9 rejects
part-bearing installation because the v0.1.10 parts subsystem has not yet provided
canonical part records. Future parts tables should retain the same revision/binding
provenance rather than resurrecting a parallel profile-only maintenance model.

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

## Work definitions and schedules

> Implementation status: v0.1.5 candidate materializes `maint_work_groups`, `maint_work_defs`, and `maint_work_sched`.

`maint_work_groups`
: Asset-scoped, user/profile-defined display/catalog groups with stable UUID/key, sort order, revision, and tombstone.

`maint_work_defs`
: Common scheduled/unscheduled work definitions. `schedule_type = none` is directly filterable as unscheduled; non-`none` definitions carry normalized rules.

`maint_work_sched`
: Ordered normalized calendar, business-day, or meter rules. Meter rules retain original interval value/unit plus canonical value and a real meter reference.

The maintenance model uses a common **work definition** for scheduled and
unscheduled work. A definition describes what may/should be done; an activity
records what actually happened.

A work definition contains a required scheduling property named `schedule`. Missing `schedule` is invalid; the service and profile-v2 schema do not default it.
`schedule: none` means unscheduled/ad-hoc work. Any non-`none` policy means
scheduled maintenance. The v0.1.5 rules cover calendar time, configurable
business days, distance, runtime hours, use counts, and reviewed combinations;
condition measurements remain a future rule type. Oil changes,
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

## Parts, fitment, and vendors

> v0.1.10 design authority for portable compatibility data is
> [fitment-pack-v1.md](fitment-pack-v1.md). Table names below remain a planned
> materialization model until the tranche migration is implemented.

`maint_parts`
: Workspace-global product identities: manufacturer, part number, description,
  revision, and provenance. A part is a product/SKU definition, not a physical
  installed component instance. Manufacturer + part number matching is
  conservative; punctuation is preserved rather than stripped to force a match.

A standardized **fitment slot** identifies the service position a product fits,
for example `engine.oil_filter` or `wiper.front.left`. Human labels and imported
aliases are not interoperability identity. The built-in slot/qualifier vocabulary
is append-only; equipment-specific extensions use reverse-DNS-style keys.

Portable fitment packs describe generalized equipment targets, slots, parts,
optional store links, and fitment facts. Imported target descriptors are mapped to
local assets explicitly. Matching may suggest exact/candidate/conflict states but
must never rename either side or silently attach a pack based only on similar
strings. Imported immutable revisions retain canonical JSON/SHA-256 provenance
and source bindings just as profile installation does.

`maint_part_compat`
: Planned canonical fitment relationships between a product, standardized slot,
  and approved local equipment/component/work context. `oem`/`compatible` are
  source facts; local user preference is separate rather than exported as
  community truth.

`maint_vendors`
: A user-defined supplier.

`maint_offers`
: Vendor/store SKU and HTTPS product URL metadata. Current/reference shopping
  price may be stored locally, but incurred money belongs in the central cost
  ledger and community fitment export excludes local prices by default.

The MVP never fetches arbitrary product/profile/fitment URLs server-side. That
avoids SSRF and prevents accidental tracking or remote-content leakage.

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

## Activity execution ledger (v0.1.6)

Work definitions describe what may or should be done. Activities describe what actually happened.

- `maint_activities` stores the asset-scoped activity header, `performed_at`, optional summary/notes, optimistic revision, and archive tombstone.
- `maint_activity_items` stores immutable performed-work rows. Each row has its own client UUID and may snapshot a linked work-definition UUID/component UUID plus the component display name and work title/kind, or describe ad-hoc work without a definition.
- `maint_activity_meters` stores immutable meter context for the activity: client UUID, meter/reading UUIDs, meter-name snapshot, observation time, canonical value, and original value/unit.

`performedAt`, work-item membership, definition/component snapshots, and meter snapshots are execution facts. They are never edited in place. Owner/Manager correction is limited to activity-header `summary` and `notes`; changing execution facts requires archiving the activity and creating a replacement.

Activity creation is an atomic workspace mutation. When an activity creates a meter reading, the reading and its activity source provenance are committed or rolled back with the activity header, work items, meter snapshots, change journal, and audit event.

## Derived maintenance status (v0.1.7)

Maintenance status is a projection, not a stored record. The authoritative inputs are the current work definition and its explicit `schedule`, the latest non-archived activity that contains an item linked to that definition, and effective meter readings/snapshots.

Definition states are `inactive`, `unscheduled`, `baseline_required`, `upcoming`, `due`, `overdue`, and `unknown`. A scheduled definition with no linked completed activity is `baseline_required`; this avoids claiming an existing asset is overdue when no service baseline has been established. `unknown` means a schedule exists and a completion baseline exists, but required meter history is insufficient or inconsistent.

For `combination: any`, an overdue rule makes the definition overdue; otherwise a due rule makes it due. If no rule is due but any rule is unknown, the aggregate is unknown rather than falsely reporting upcoming. Calendar and business-day rules are evaluated by UTC calendar date. Meter rules use the activity's immutable meter snapshot when present, otherwise the effective reading at or before the completion time, then compare against the effective current reading at the requested `asOf` time.

No v0.1.7 migration stores due dates, thresholds, or state. Schedule edits, activity corrections/archives, and reading supersession therefore take effect immediately in the next projection.


## Forecast/reminder policy and maintenance occurrences — v0.1.8

`maint_reminder_policy` is workspace configuration, not maintenance truth. Its initial reviewed fields are `calendar_lead_days` and `meter_lead_percent`, with optimistic `revision`. When no row exists, the effective policy is the explicit application default (14 days / 10 percent) and is reported as revision zero.

`maint_occurrences` materializes the actionable work queue. An occurrence references one asset and one work definition and snapshots only the `baseline_activity_uuid` that caused its current maintenance cycle. `open_marker = open` identifies an open queue item; closed rows set the marker to NULL and retain `closed_at` plus a bounded `closed_reason`. The schema enforces one open occurrence per `(workspace, definition)`.

Occurrence rows deliberately contain no `due_on`, due-state field, remaining distance/runtime/count, or meter threshold. Those values are recomputed from schedules, activity history, effective readings, and the forecast policy whenever an occurrence is read. This lets schedule edits, reading supersession, and activity correction immediately change the current projection without rewriting historical queue rows.

### Fitment runtime records and interchange — v0.1.10 in progress

The current v0.1.10 runtime checkpoint materializes `maint_fit_packs`, `maint_fit_revs`, `maint_fit_imports`, `maint_fit_bind`, `maint_fit_targets`, `maint_fit_slots`, `maint_parts`, `maint_offers`, `maint_fitments`, and `maint_asset_fit`. Pack revisions are immutable canonical JSON snapshots. Targets remain generalized portable descriptors; `maint_asset_fit` is an explicit local mapping and does not modify target or asset identity. Parts are deduplicated conservatively by normalized manufacturer plus punctuation-preserving part number. Fitment assertions remain source-specific so several imported packs can independently support the same canonical part/slot fact. Bounded CSV/ZIP interchange is a reversible projection into those same canonical records and preserves reusable equipment identifiers rather than introducing a parallel identity model. Profile part materialization, activity parts-used records, vendor management, and `maint_costs` remain pending.
