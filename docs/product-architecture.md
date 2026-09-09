# Product architecture and interaction model

This document records the intended product model beyond the currently implemented vertical slice. `docs/domain-model.md` remains the table-level target and `docs/roadmap.md` controls delivery order.

## Product surfaces

Nextcloud is authoritative for identity, validation, history, due state, forecasting, reporting, and sync. The desktop/browser UI is the management surface: inventory, profiles, specifications, components, maintenance definitions, relationships, assignments, imports, and detailed reports. Mobile is primarily a field work-ticket surface: pick an asset, pick an activity, enter the task-specific facts, optionally add notes/photos/receipts, and submit to the log.

The mobile client should be a purpose-built Vue application that can run as a PWA and later be packaged with Capacitor for Android/iOS. Offline-first behavior is mandatory. Local durable records are created before transfer, have client-generated UUIDs, expose clear pending/synced/conflict state, and are retained until the server acknowledges canonical ingestion.

A lightweight sync indicator belongs in the primary UI; detailed pending-record, attachment, storage, retry, and export status belongs on a dedicated status page. Exact placement is a later UX decision.

## Portable work bundles

API sync is one transport, not the data model. Pending work can also be exported as a versioned portable bundle containing a manifest, records, and attachments. Desktop/Nextcloud imports those bundles through the same validation/idempotency pipeline as OCS ingestion. Importing the same UUID twice must not create duplicate service history. Credentials are never exported.

## Assets, classes, categories, and profiles

`Asset` is generic: vehicle, trailer, building, equipment, appliance, tool, medical device, system, location, or other. A coarse asset class guides capabilities and relationship compatibility; user-facing categories remain configurable. Profiles provide declarative defaults for specifications, component topology, maintenance definitions, meters, condition measurements, relationship capabilities, and part information. An asset's user-facing display name/nickname remains separate from template-defined structured identity. Profile-defined information fields can declare type, group, order, validation, units, sensitivity, and summary-display metadata. Imported profile materialization remains editable by the user.

Profiles must not impose fixed cardinality. Two batteries, two OEM fuel filters, and three aftermarket filters are ordinary component instances, not numbered schema fields.

The v0.1.9 desktop profile workflow exposes bundled profiles and pasted local JSON,
performs server validation, previews applicability/conflicts/materialization counts,
and requires an explicit install action. Installed provenance/hash remains visible.
Materialization is a starting configuration, not a locked template: the resulting
components, meters, groups, and definitions remain ordinary editable records.
Profile upgrade diff/merge and remote marketplace discovery remain later work.

## Components and specifications

Components are individually identified maintainable instances and may be nested. Specifications are structured facts attached to an asset or component, with semantic key, typed JSON value, optional unit/regime, and provenance. Examples include fluid types/capacities, tire sizes/pressures, weights/ratings, filter requirements, and manufacturer cross-reference information.

Part requirements and compatible products are relational. Compatible, preferred, and actually installed/used parts are distinct facts. v0.1.10 also treats fitment interoperability as a separate portable dataset: standardized service-position keys identify what a product fits, generalized equipment descriptors identify model/configuration targets, and explicit local mappings bridge imported naming to owned assets without renaming either side. Canonical JSON plus bounded CSV/ZIP import/export is intended to let households and communities build, review, share, and improve compatibility matrices independently of maintenance schedules.

## Usage, measurements, and maintenance rules

> Implementation status: v0.1.4 provides configurable `distance`, `runtime`, and
> `usage_count` meters plus immutable timestamped readings. Canonical values are
> integer `mm`, `s`, and `count`; corrections supersede rather than rewrite prior
> observations. These measurements are inputs for later work-definition due
> calculation, not maintenance activities by themselves.

Maintenance definitions use one common work-definition model. The scheduling
property is named `schedule` and the property is required: `schedule: none` means unscheduled/ad-hoc work;
anything else is scheduled maintenance. This supports a direct scheduled versus
unscheduled filter without separate record types.

> Implementation status: v0.1.5 candidate materializes work groups/definitions and supports `combination: any` with calendar, configurable business-day, and meter interval rules. Due-state calculation remains later work.

The v0.1.5 rule set covers time, configurable business days, distance,
runtime/hour, and usage/event counts, and may combine those reviewed limits.
Condition monitoring (for example oil-life percentage) remains a future optional
asset capability; when implemented it may be combined with hard maximum
distance/runtime/calendar thresholds.

Profiles define their own work-definition groups and catalogs rather than relying
on hard-coded application categories. A quick "I used this today" action is a
usage activity that can feed relevant schedules.

## Forecasting

Due rules are authoritative; forecasts predict when a rule is likely to become due and never silently alter the rule. Server-side forecasting uses timestamped measurements and activity history, including odometer readings captured during fuel entry, to estimate threshold dates. Calendar reminders are projections of forecast/due state and may move when the estimate changes materially.

## Relationships, assignments, and activities

> Implementation status: v0.1.2 materializes the built-in relationship catalog, class compatibility checks, contextual relationship defaults, and effective-dated assignments. Actual activity configuration remains a later activity/trip concern.

Containment and cross-asset relationships are separate. A component is part of an asset; independent assets participate in typed many-to-many relationships such as `tows`, `carries`, `stored_at`, `powers`, with room for later extension. The v0.1.2 built-in catalog constrains compatible source/target asset classes and provides inverse labels; arbitrary user-defined relationship types are deferred until their validation and migration contract is designed.

Relationships are composable and may form multi-asset operating configurations, e.g. boat -> trailer -> tow vehicle. A relationship may have contextual defaults (such as the default trailer for fuel/trip entry).

Assignments are effective-dated operational relationships: Trailer A can be assigned to Truck 3 from a date until another date or indefinitely. Compatibility, assignment, and actual activity association are separate facts. A ticket records what was actually used even when that differs from the current assignment.

## Activities

The canonical activity timeline includes maintenance, repairs, inspections, fuel/energy entries, trips, meter readings, usage events, and extensible other records. A completed maintenance ticket is a durable service/log record, not the maintenance definition itself. Activities can reference a primary asset and zero or more related assets and can update meters on multiple assets.

## Fuel, operating context, weight, and trips

Fuel/trip reporting supports operating configurations rather than one blended MPG figure. A tow vehicle may report efficiency unloaded, with different trailers, and later by load/weight regime.

Assets can expose structured weight specifications appropriate to their class, including actual/general weight, curb/dry/empty weight, GVWR, GCWR, and other rated limits. A multi-asset configuration can derive an estimated combined weight from its members and load relationships.

Scale/weight tickets are first-class observations attached to a trip or operating configuration. Recorded axle/gross scale values override or augment estimates for that event while retaining both values and provenance (scale/source, time, ticket image). Reports may group fuel burn/MPG by configuration and user-defined observed/derived weight ranges.

## Optional geodata

Location is optional evidence, never required for normal operation. A record may carry no geodata, device location captured at entry time, location derived from photo EXIF, or another explicit source. Preserve provenance and capture time. Privacy configuration can disable location globally or contextually, including a “never capture here” private/office location policy. Attachments must not silently convert EXIF location into canonical record location without the configured policy and visible provenance.

## Evidence and retention

Evidence kinds are `photo`, `video`, `receipt`, `invoice`, `document`, and
`other`. Evidence and activities have a many-to-many relationship: one invoice
can support an oil change plus filters, and one activity can carry several media
and document items.

Retention policy applies independently to bytes and durable evidence metadata.
Where policy permits, pruning a blob can retain checksum, original name/type and
size, uploader/provenance, links, retention action/date, and audit provenance.
A per-item **Protect / Keep** override prevents automated pruning. Management
reporting should expose total, protected, and prunable storage by media type,
asset, and age with a policy simulation before destructive action.

## Public maintenance reports

Future public reports use scoped, revocable share tokens rather than public
workspace membership. A share may expose selected asset identity/specifications,
maintenance history/current state, and selected evidence. It must not implicitly
expose other assets, workspace membership, internal audit data, private notes,
geodata, or unrelated evidence. Both live and immutable snapshot report modes
remain design options.

## External mechanic submissions

A future scoped mechanic link may permit reading selected maintenance information
and preparing a work transaction with work performed, parts, notes, evidence,
receipt, and invoice. The external party never writes canonical history directly.
The result is an external submission reviewed by an Owner or authorized Manager;
acceptance passes through the same validation/idempotency ingest boundary used by
mobile sync and portable work bundles.

## Guiding invariant

Configure the thing and its maintenance model on desktop; record actual work and usage in the field; keep Nextcloud authoritative; and ensure every field record can be created offline and later arrive through either OCS sync or a portable bundle without changing its semantic identity.

## Executed maintenance activities

The task-first mobile workflow ultimately submits an activity transaction, not a mutation of a work definition. One activity can contain multiple performed work items and multiple meter snapshots. The initial desktop UI may expose a simpler one-item ticket, but the OCS/domain contract is multi-item from the start so offline mobile bundles do not require a later incompatible schema change.

Evidence, parts, costs, public report shares, and external mechanic submissions attach to this stable activity ledger in later tranches; they are not part of v0.1.6.

### Maintenance status projection

The task-oriented UI consumes a derived maintenance-status endpoint rather than maintaining a second mutable due-state model. `baseline_required` is actionable setup work, while `unknown` signals incomplete meter context. Exact remaining days/canonical meter values are returned; notification-specific "due soon" horizons remain a later policy layer.


### Work queue and forecast policy

The desktop and future mobile “what needs attention?” surface consumes the materialized occurrence queue, but each row is rendered with the current forecast projection. The queue can therefore be durable and synchronizable without becoming a duplicate due-state database. A policy-layer `due_soon` state uses workspace-configured lead horizons; `baseline_required`/`setup_required` and `unknown`/`blocked` remain setup/data-quality work rather than maintenance occurrences.

The first policy supports calendar lead days and a meter lead percentage so it works across distance, runtime, and usage-count schedules without embedding display-unit-specific thresholds. Later notification channels may add delivery preferences while continuing to consume this same forecast contract.

### Fitment compatibility workflow

Desktop management can validate/import a portable canonical JSON compatibility matrix or its bounded normalized CSV/ZIP spreadsheet projection, inspect reasoned target-to-asset match candidates, explicitly confirm a mapping, query standardized service-position parts, and produce privacy-minimized JSON or CSV/ZIP community exports. Local naming does not have to equal a community dataset's naming. Profile-to-part materialization, parts-used activity entry, vendor management, and costs remain subsequent v0.1.10 slices.
