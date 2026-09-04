# Product architecture and interaction model

This document records the intended product model beyond the currently implemented vertical slice. `docs/domain-model.md` remains the table-level target and `docs/roadmap.md` controls delivery order.

## Product surfaces

Nextcloud is authoritative for identity, validation, history, due state, forecasting, reporting, and sync. The desktop/browser UI is the management surface: inventory, profiles, specifications, components, maintenance definitions, relationships, assignments, imports, and detailed reports. Mobile is primarily a field work-ticket surface: pick an asset, pick an activity, enter the task-specific facts, optionally add notes/photos/receipts, and submit to the log.

The mobile client should be a purpose-built Vue application that can run as a PWA and later be packaged with Capacitor for Android/iOS. Offline-first behavior is mandatory. Local durable records are created before transfer, have client-generated UUIDs, expose clear pending/synced/conflict state, and are retained until the server acknowledges canonical ingestion.

A lightweight sync indicator belongs in the primary UI; detailed pending-record, attachment, storage, retry, and export status belongs on a dedicated status page. Exact placement is a later UX decision.

## Portable work bundles

API sync is one transport, not the data model. Pending work can also be exported as a versioned portable bundle containing a manifest, records, and attachments. Desktop/Nextcloud imports those bundles through the same validation/idempotency pipeline as OCS ingestion. Importing the same UUID twice must not create duplicate service history. Credentials are never exported.

## Assets, classes, categories, and profiles

`Asset` is generic: vehicle, trailer, building, equipment, appliance, tool, medical device, system, location, or other. A coarse asset class guides capabilities and relationship compatibility; user-facing categories remain configurable. Profiles provide declarative defaults for specifications, component topology, maintenance definitions, meters, condition measurements, relationship capabilities, and part information. Imported profile materialization remains editable by the user.

Profiles must not impose fixed cardinality. Two batteries, two OEM fuel filters, and three aftermarket filters are ordinary component instances, not numbered schema fields.

## Components and specifications

Components are individually identified maintainable instances and may be nested. Specifications are structured facts attached to an asset or component, with semantic key, typed JSON value, optional unit/regime, and provenance. Examples include fluid types/capacities, tire sizes/pressures, weights/ratings, filter requirements, and manufacturer cross-reference information.

Part requirements and compatible products are relational. Compatible, preferred, and actually installed/used parts are distinct facts.

## Usage, measurements, and maintenance rules

Meters/measurements may represent distance, Hobbs/runtime hours, uses/cycles, starts, condition values, or custom dimensions. Inputs may be absolute readings or increments such as “I used this today” (+1 use). One activity may update several measurements.

Time bases include calendar days and configurable working days. Working days default to Monday-Friday but may be overridden by profile, asset, or task; the UI uses a human day picker rather than cron numbering.

Maintenance definitions describe what should happen. Multiple due conditions support earliest-condition/ANY semantics, for example 7,500 miles OR 365 days. Generic condition measurements such as oil-life percentage may be preferred triggers while hard mileage/hour/calendar limits remain safety caps.

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

## Guiding invariant

Configure the thing and its maintenance model on desktop; record actual work and usage in the field; keep Nextcloud authoritative; and ensure every field record can be created offline and later arrive through either OCS sync or a portable bundle without changing its semantic identity.
