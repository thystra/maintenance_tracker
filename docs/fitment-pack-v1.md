# Fitment pack v1 design contract

Fitment packs are portable, data-only compatibility matrices. They answer a
separate question from maintenance profiles:

- a **maintenance profile** describes what an equipment class contains and how it
  is maintained;
- a **fitment pack** describes which purchasable parts fit standardized service
  positions on specific equipment configurations;
- a **local asset mapping** says which imported equipment target corresponds to a
  user's actual asset without renaming either side.

The interchange schema is
[`fitment-pack-v1.schema.json`](../schemas/fitment-pack-v1.schema.json). The
built-in standardized slot/qualifier vocabulary is
[`core-v1.json`](../fitment/core-v1.json). A fictional example that makes no
real-world fitment claim is
[`example-service-parts.json`](../fitment/examples/example-service-parts.json).

This document defines the v0.1.10 contract before database materialization is
implemented. Schema-valid data is not automatically trusted or installed.

## Runtime implementation status

The current v0.1.10 checkpoint implements the bounded JSON validator/canonicalizer, immutable pack revision/import persistence, canonical standardized slots and parts, source offers/fitment assertions, explicit equipment-target-to-local-asset mapping, source JSON export, and privacy-minimized community JSON export. Import and mapping remain separate operations.

The CSV/ZIP interchange defined below remains **pending runtime implementation**. It is retained as the reviewed spreadsheet/community interchange contract, including archive path-traversal and formula-injection protections. Profile-v2 parts also remain fail-closed until a later v0.1.10 checkpoint explicitly materializes them through the canonical part/fitment services.


## Stable slot identity

Human labels are presentation; the interoperable identity is a stable `slotKey`.
The initial core vocabulary deliberately starts small and may only grow by adding
keys. Existing core keys must not be silently renamed or repurposed.

Examples include:

- `engine.oil_filter`;
- `engine.air_filter`;
- `hvac.cabin_air_filter`;
- `fuel.filter.primary` and `fuel.filter.secondary`;
- `transmission.filter`;
- `wiper.front.left`, `wiper.front.right`, and `wiper.rear`.

A pack may provide labels and aliases such as `Lube filter` while retaining the
canonical core key. Equipment-specific positions not covered by the core
vocabulary use a reverse-DNS-style extension key such as
`org.example.excavator.hydraulic.return_filter`. Extension data may not squat on
a core root such as `engine.*` or `wiper.*`.

A profile component key remains profile-local. Future profile/fitment integration
may map a profile component or work definition to a standardized slot, but a
profile-local key is never assumed to be a global fitment identity merely because
the text happens to match.

## Equipment target descriptors

An equipment target is a generalized model/configuration descriptor, not an
individual owned asset. It carries:

- pack-local `key`;
- portable `assetClass` (`vehicle`, `trailer`, `building`, `equipment`, `appliance`, `system`, `tool`, `medical_device`, `location`, or `other`);
- manufacturer and model;
- an optional bounded model-year range, which must set `yearFrom` and `yearTo`
  together;
- explicit manufacturer/model aliases used only for candidate discovery;
- optional model/configuration identifiers from external namespaces;
- zero or more structured qualifiers.

Core qualifier keys initially include `engine`, `transmission`, `drivetrain`,
`body_style`, `trim`, `market`, `wheelbase`, and `axle`. Unknown qualifiers must
use a reverse-DNS-style extension key.

Each qualifier explicitly says whether it is `required` or `optional` for a
strong match. For example:

```json
{
  "key": "engine",
  "value": "6.7L Example Diesel",
  "aliases": ["6.7 diesel"],
  "match": "required"
}
```

External identifiers, when present, identify a reusable model/configuration in a declared namespace; they must never be a VIN, serial number, registration, or other owned-unit identifier. Fitment packs must not contain local asset UUIDs, nicknames, VINs, serial numbers, receipts, costs, service history, or other unit-specific/private workspace data. The target describes a reusable model/configuration only.

## Conservative matching and explicit mapping

Matching is advisory. Import never attaches a target to a local asset solely
because strings look similar.

Text comparison uses conservative Unicode NFKC normalization, trimming,
whitespace collapsing, and case-insensitive comparison. Punctuation is not
removed globally: `F-350` and `F350`, for example, are not automatically declared
equivalent unless an alias or explicit mapping says so.

A preview should report reasoned match states rather than an opaque score:

- **exact**: asset class/manufacturer/model/year and every required qualifier that
  the target declares are known locally and match;
- **candidate**: the base identity matches and no known field conflicts, but a
  required qualifier is unknown locally or an explicit alias was needed;
- **conflict**: a known structured field contradicts the target;
- **insufficient**: there is not enough local structured identity to make a useful
  suggestion.

The preview also reports field-by-field reasons. Owner/Manager must explicitly
confirm the target -> asset mapping before materialization. A manual mapping may
intentionally override a naming/structured conflict, but the UI must make the
conflict visible and the mapping should retain that it was manually confirmed.
Neither the imported target descriptor nor the local asset is rewritten as a
side effect.

Persisted mapping is source-revision aware so a later pack revision can be
previewed against the same asset without pretending that the new revision was
already approved.

## Parts and fitment facts

A pack part is a product identity, not a physical installed component. It has a
pack-local key, manufacturer, part number, and description. Within one pack,
manufacturer + part number must be unique after only conservative
case/whitespace normalization. Punctuation is preserved and is never stripped to
force two part numbers to compare equal.

A fitment references exactly one equipment target, slot, and part. `relation` is
`oem` or `compatible`. Local user preference is intentionally separate from the
portable fitment fact and is not encoded as `preferred` community truth.

`verification` is `asserted` or `verified`. A `verified` record requires at least
one bounded evidence item. Evidence kinds are initially `manufacturer`,
`retailer_fitment`, `community_tested`, and `other`. This is provenance for a
claim, not a guarantee of physical fitment; the UI must continue to tell users to
verify fitment for their exact equipment configuration.

Store/product links are optional offers attached to parts. URLs must be HTTPS and
remain metadata only. The server never fetches arbitrary profile, fitment, part,
or vendor URLs.

## Bounded canonical source identity

Canonical JSON input is limited to **8 MiB** per fitment pack in v1. The schema
also caps equipment, slot, part, offer, qualifier, evidence, and fitment counts.
The runtime validator must enforce both byte and JSON-depth limits before
materialization. CSV/ZIP expanded content is separately bounded as described
below.

Content hashing is representation-stable for matrix ordering. After validation:

- object keys are recursively sorted;
- equipment, slots, parts, and offers are sorted by their stable pack-local/global
  keys;
- fitments are sorted by `equipmentKey`, `slotKey`, then `partKey`;
- equipment qualifiers are sorted by qualifier key;
- aliases and evidence rows are sorted deterministically because their order has
  no semantic meaning; and
- validated string content and part-number punctuation are otherwise preserved.

Reordering rows in a spreadsheet therefore does not manufacture a new content
hash, while changing an actual descriptor, identifier, part number, fitment, or
evidence fact does.

## Import workflow

The v0.1.10 implementation should use an explicit preview/install boundary:

1. accept a bounded local JSON document or supported CSV bundle;
2. validate the schema and semantic references before any write;
3. canonicalize the normalized source revision and compute its SHA-256 identity;
4. show equipment-target match suggestions and field-level reasons;
5. show unresolved/non-core slot mappings, part identity collisions, duplicate
   fitments, and other conflicts;
6. require Owner/Manager confirmation of target/slot mapping decisions;
7. materialize parts, offers, standardized compatibility, and source bindings
   through canonical domain services inside the existing serialized workspace
   transaction;
8. retain the immutable imported revision and source-key bindings for provenance;
9. never overwrite user-modified canonical records merely because a later pack
   version differs.

An import retry uses a client-generated operation UUID and must be idempotent.
Pack upgrades are explicit reviewed diffs, not silent replacement.

The application must not maintain a second profile-only or fitment-only mutable
parts catalog. Imported products become ordinary canonical part records; the pack
revision is provenance/reference data.

## Export contract

Export is first-class from the initial fitment implementation. There are two
semantically different exports.

### Source-revision export

An imported immutable revision can be exported as its normalized canonical JSON
with the same pack identity/version/content hash. This is source preservation,
not a claim that local edits were written back into the source pack.

### Community export

A user may build a new pack from selected local compatibility facts. The export
workflow asks for new pack identity/version/license/provenance and generalized
equipment descriptors. Before export, the user can review or edit the generalized
descriptor that will represent a local asset.

Community export excludes by default and must never silently include:

- local workspace/asset/component/activity UUIDs;
- asset nicknames or private notes;
- VINs or serial numbers;
- purchase prices, cost ledger records, receipts, invoices, or service history;
- local preference state;
- authentication/account identifiers.

The resulting document contains only generalized equipment descriptors,
standardized slots, part identities, optional store links, fitment facts, and
explicit public provenance selected for the export.

## Canonical JSON and CSV/ZIP interchange

Canonical JSON is the authoritative lossless interchange representation and the
basis for content hashing.

For spreadsheet/community work, the application should also import/export a
normalized UTF-8 ZIP bundle containing an allowlisted set of files:

- `manifest.json` — pack identity/version/license/provenance and CSV dialect;
- `equipment.csv` — equipment key/asset class/manufacturer/model/year range;
- `equipment_aliases.csv` — equipment key, field (`manufacturer` or `model`), alias;
- `equipment_qualifiers.csv` — equipment key, qualifier key/value/match;
- `qualifier_aliases.csv` — equipment key, qualifier key, alias;
- `slots.csv` — slot key/label/kind;
- `slot_aliases.csv` — slot key/alias;
- `parts.csv` — part key/manufacturer/part number/description;
- `offers.csv` — offer key/part key/label/SKU/HTTPS URL;
- `fitments.csv` — equipment key/slot key/part key/relation/verification/notes;
- `fitment_evidence.csv` — fitment tuple plus evidence kind/reference/note.

The normalized bundle is relational rather than a single giant matrix CSV so
aliases, arbitrary qualifiers, multiple evidence rows, and repeated fitments are
lossless. A spreadsheet user can usually work primarily in `equipment.csv`,
`parts.csv`, and `fitments.csv` and leave optional tables empty.

ZIP import is limited to **8 MiB compressed** and **32 MiB expanded** in v1 and rejects path traversal, symlinks, duplicate filenames, unknown files, and entries/expanded content beyond those bounds. CSV parsing is RFC 4180-style UTF-8 with fixed headers and the same schema-derived row-count limits.

Spreadsheet-safe CSV export must defend against formula injection. Text cells
whose first character is `=`, `+`, `-`, `@`, or `'` are escaped using a versioned
scheme recorded in `manifest.json`; the corresponding importer reverses only
that declared scheme. Canonical JSON never applies spreadsheet escaping.

## Mapping nonstandard source data

A valid fitment pack already has stable equipment/slot keys. A separate import
builder may accept less formal source data (for example a spreadsheet column named
`Lube Filter`) and ask an Owner/Manager to map that source label to
`engine.oil_filter` before producing/installing a valid pack revision.

Mappings retain the original source label as an alias/provenance hint. They do not
make arbitrary source text globally canonical. This lets a community dataset use
its own naming while still converging on standardized slots during import and
export.

## Relationship to profile v2

Profile v2 already carries `parts` plus `compatiblePartKeys` on components and work
definitions. v0.1.10 should stop rejecting those facts only after the canonical
parts/fitment services exist. Profile part materialization should reuse the same
part catalog and standardized fitment model rather than creating `maint_prof_parts`
or another parallel catalog.

A future profile-v2 revision may add an explicit standardized slot key to a
component/work definition. Until then, a profile-local component/work-definition
key must be explicitly mapped to a fitment slot when the relationship is not
unambiguous.

## Design invariants

- Slot keys, not labels, are interoperability identity.
- Core vocabulary keys are append-only and never silently repurposed.
- Extension slots/qualifiers use reverse-DNS-style namespaces.
- Equipment targets are generalized reusable descriptors, never individual owned
  units.
- Alias matching creates candidates; it does not silently authorize mapping.
- Explicit target/slot mappings do not rename either source or destination.
- Part-number punctuation is preserved during identity matching.
- Portable `preferred` fitment is not community truth; user preference is local.
- `verified` fitments require evidence and still are not a guarantee.
- URLs are stored metadata only; no arbitrary server-side fetch is introduced.
- Imported source revisions and content hashes are immutable provenance.
- Community export is privacy-minimized and contains no local UUID/history/cost
  data unless a future export format explicitly and separately defines those
  semantics.
- Canonical JSON is lossless authority; CSV/ZIP is a spreadsheet-friendly,
  bounded, versioned interchange projection.
