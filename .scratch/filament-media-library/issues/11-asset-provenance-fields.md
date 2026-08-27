# Define Asset Provenance Fields

Status: resolved
Type: grilling
Blocked by: 08

## Question

Ticket 02 enumerated the canonical Media Asset field list and ticket 08's importer needs facts that list does not carry: whether an asset was imported rather than uploaded, which legacy source it came from, and how confidently its MIME type was determined (stored header, content sniff, extension, or unknown). Does the canonical record gain provenance columns for these, and if so which — or are they left off the record and recovered from logs and the importer's report? Amending a resolved decision is the point of this ticket, so decide the amendment explicitly.

## Answer

Ticket 02's field list is **amended**: the canonical Media Asset record gains three provenance columns. Logs and the importer's report are a migration-window artifact, but an imported asset's differences (null `uploaded_by`, inferred rather than asserted visibility, extension-derived MIME) outlive them, so the record carries the facts itself.

**`source`** is a non-nullable enum, `upload` or `import`, on every asset. Not a nullable `imported_at`, whose null would implicitly mean "uploaded"; an enum states the fact for every row and extends to a future origin without a schema rethink. It stays a single axis, origin only. Byte ownership is deliberately not encoded here: an in-place import's `object_key` is the legacy path under the legacy prefix, a `--copy` import's is server-generated under `media/`, so "does deleting this legacy prefix destroy live assets" is already answered by `object_key` and needs no column. An `import_copy` value would conflate two axes and demand a cross-product (`upload_copy`) the moment anything else produced a copy.

**`mime_source`** is an enum, `header` / `sniffed` / `extension` / `unknown`, recording which rung of ticket 08's ladder produced `mime_type`. It applies to uploads too, where a browser-supplied `Content-Type` is itself a claim rather than a fact. This matters past the migration because ticket 06 made MIME the gate on picker eligibility: a wrong extension-derived type silently removes an asset from a picker or offers it to a field that will choke. The rung makes "re-resolve every untrustworthy row" a targeted query instead of a full-library re-sniff.

**`import_source`** is a nullable string following a `host.column` convention (`posts.hero_image`), set by both import modes. It answers "where did this come from" for a human reading a row, and `where import_source like 'posts.%'` targets a rollback well enough for a once-ever event. A structured JSON blob or a dedicated `media_asset_imports` table would buy a permanent lifecycle, model and migration for temporary value.

**Nothing records the pre-copy legacy key.** Rollback targeting is served by `import_source`, which copy mode sets identically, and the leftover source objects are a prefix-level fact (copy consolidates a prefix, so cleanup drops that prefix) that no per-asset backlink helps with.

**Shape.** Three real columns on `media_assets`, not a JSON blob. Two are low-cardinality enums wanting plain b-tree indexes and the third is a string a human reads; ticket 09's per-query facet aggregates and ticket 10's sortable table both want real columns, and JSON path predicates are only indexable through generated columns or expression indexes, which is a real column with extra ceremony.

**Re-resolution.** A `media:resolve-mimes` command re-runs the ladder over rows matching a given rung, defaulting to `--from=extension` (the rung worth fixing) and requiring `--sniff` to actually fetch bytes. It writes `mime_type` and `mime_source` together, so a row never claims a rung it did not come from. Resolution is never lazy on the Delivery route: that would make a read path perform a write and a network fetch, and tie correctness to whether an asset happens to be viewed.

**Surfacing.** Management page only. `source` is a filterable column there and `import_source` plus `mime_source` are view-page details. Provenance is never a picker facet: the picker serves someone choosing a file, to whom origin is noise, and a sixth dimension would spend ticket 09's aggregate budget on it.

## Comments

- Resolved with the requester on 2026-08-27.
