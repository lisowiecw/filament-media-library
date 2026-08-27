# Define the Media Picker Field's Serialized Value

Status: resolved
Type: grilling
Blocked by:

## Question

Ticket 01 deferred the `MediaPicker` field's serialized value alongside the packaging questions; ticket 19 answered the packaging ones and promised the field itself as stable public API, which makes its value shape a promise too. Ticket 06 settled what the picker *does* (one field, one modal, ordered field-scoped attachments, no duplicates) and never what it puts in the host's form state.

Decide what `$data['<field>']` holds when a form using a `MediaPicker` is filled and when it is saved: a bare asset id, an ordered array of ids regardless of cardinality, a shape that differs between single and multiple, or hydrated models. Decide whether ordering is carried in the value or only in the Attachment rows, given ticket 18 made array index order attachment order verbatim for imports. Decide what a host app's own validation rules and casts see, since they run against this value and not against the Attachment table. Decide whether the value is what gets persisted at all, or whether the field writes Attachments as a side effect and the host column stays absent, which is the question a host app migrating off a legacy path column will ask first.

## Answer

The `MediaPicker` field is **virtual**: `$data['<field>']` exists only in form state and the host table gains no column. Attachment rows stay the single copy of the fact, so a host migrating off a legacy path column drops that column rather than repurposing it. A mirrored column would have to be maintained by every path that touches attachments (import, force delete, tenant guard) and would disagree with the rows the first time one of them did not.

The value is **always an ordered array of bare asset ids**, whatever the cardinality: single is `multiple(false)` enforcing length, not a different type, because ticket 19 made the field a promised surface and a shape that forks on a config call forks every host rule, cast and `afterStateUpdated()` with it. Hosts write `$data['cover'][0] ?? null`. Ids rather than hydrated models, because form state rides Livewire's payload on every request and ids keep it flat and comparable for dirty-checking.

**Array index is the order**, authoritative on save: the field rewrites an attachment's `order` to the value's index, which is the same rule ticket 18 gave the importer, now true by construction rather than by coincidence. The accepted cost is that a host reordering attachment rows directly, then saving any field on a form containing the picker, has that order overwritten.

Host validation sees **the array of ids and nothing more**. The plugin ships cardinality rules (`required`, `minItems`, `maxItems`) over it; a host rule wanting asset facts queries the assets itself. Type, size and blocked-type enforcement stay at ingest where ticket 13 put them, next to the bytes, rather than becoming a second weaker copy that also runs against assets this field never uploaded.

Attachment writes are **deferred to after the host record is persisted**, so create and edit share one path and form state is the only state that exists mid-form. Writing rows eagerly would manufacture host-less Attachments, which ticket 10 defined as External references: an abandoned create form would leave debris that blocks deletion and cannot be told from a real one. An abandoned create form leaves only the uploaded asset, which is correct, since the upload really happened.

An incoming id the viewer cannot have (soft-deleted, out of tenant per ticket 17, or never offered by this field) **rejects the whole save** with a validation error naming the field and never the asset id, so a cross-tenant probe learns nothing about existence. Silently dropping ids would show a successful save with quietly fewer images and no reason given.

`$form->fill()` hydrates from the Attachment rows for this host and field context, **ordered by `order`, ids only, unscoped**, including assets the viewer cannot be delivered. The guard fires on save instead, which is why that save now rejects rather than drops.

Removing an id **detaches and never deletes**, restated here because the picker is the surface where a human most expects removal to mean deletion. Saving **diffs against the current rows**: attach the new, detach the missing, update `order` only where it differs, touch nothing when the value matches. Delete-and-reinsert would churn attachment ids that ticket 10's usage list entries reference and make the row's `created_at` a lie about when the asset was attached; the diff is also what bounds the order-overwrite above to genuine disagreements.

Because there is no column, the plugin ships a **`HasMedia` trait** as a promised surface (amending ticket 19's list): `media(string $field)` returning an ordered `MediaAsset` collection and `firstMedia(string $field)`. Without it, ticket 19's decision that the tables are internal would leave hosts with no supported read path, since a hand-written `morphToMany` hardcodes the pivot table and its columns. The trait excludes soft-deleted assets (their objects are queued for removal, so returning them hands the caller a guaranteed-broken URL) and applies **no tenant scope and no policy check**, matching ticket 17's unscoped jobs and commands; confidentiality still holds because ticket 07 put the boundary on delivery, so `$asset->url()` is where a private asset is gated, not here.

Replace is **not a distinct operation in the value**: a single-cardinality field whose id changes is just Q10's diff, and the previous asset is detached and survives either way, which is what ticket 05's entry actually promises. That is a wording amendment to ticket 05, not a behavioural one.

## Comments

- Resolved with the requester on 2026-08-27.
