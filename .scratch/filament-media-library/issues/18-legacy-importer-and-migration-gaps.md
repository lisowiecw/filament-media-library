# Close the Legacy Importer and Migration Gaps

Status: resolved
Type: grilling
Blocked by:

## Question

Three migration-window gaps left open by tickets 08 and 13, all small enough to settle together and all sharing the importer's context.

First: **multi-value columns.** Ticket 08 assumed a single-value legacy column. Decide how the importer discovers, orders and attaches paths held in a JSON array column, and what ordering means when the source array has no explicit order, given ticket 02 made attachments ordered and field-scoped.

Second: **objects on an unconfigured bucket.** Ticket 08 made unknown disk a hard failure, on the reasoning that a disk name is always suppliable. Decide what happens when it is not: legacy objects live in a bucket the application no longer configures, so no disk name exists to record. Is the row skipped and reported, is a disk configured solely for the import, or does the record gain a way to name a bucket the application cannot read?

Third: **the sniff step.** Ticket 13's earned disposition means a freshly imported library renders as downloads until `media:resolve-mimes --sniff` has run. Decide whether the importer chains that pass itself, offers it as a flag, or whether the runbook documents it as a step a human runs, and what the management page shows in the window between.

## Answer

All three gaps close on one principle already established by ticket 08: **the importer registers what the application declares and what actually exists, it never guesses at a shape and never overwrites what a person may have touched.** Each decision below is that rule applied to a different gap.

### Multi-value columns

Cardinality is **declared, never inferred**. The column mapping states that a column holds many paths; the importer does not read the Eloquent cast and does not sniff the value. A cast to `array` may hold a single path in a wrapper and a plain string column may hold a JSON-looking value, so both inference routes make behaviour depend on something the operator never stated. A shape mismatch between the declaration and the data is a hard failure in both directions: a declared-single column whose value decodes to an array, and a declared-list column holding a bare string.

**Order is the source array's index order, verbatim.** Position 0 becomes attachment order 0. That index is the only ordering the source actually contains, and it is the order the legacy application rendered, so it is what the editor already sees on the live site. Sorting by path or basename would invent an order the source never had and visibly reshuffle galleries; assigning order by import sequence is the same answer wearing a disclaimer, since import sequence is index order.

**Within one array**, a repeated path is deduplicated keeping the first occurrence (ticket 02 forbids duplicate selections in one field scope) and the drop is reported. A missing object skips that element and the remaining elements still attach. Refusing an entire gallery because one file is gone leaves the operator worse off than the legacy state; the importer's job is to register what exists, and the report is how it tells the truth about what did not.

**Elements that are not paths split by kind.** Nulls and empty strings are ordinary legacy noise: skipped and reported, alongside the missing objects. Anything structural (a nested object, an absolute URL) is a hard failure for the run, because it means the declared mapping does not describe the data, and quietly skipping every element of a misread column produces an import that looks successful and is empty. That is the failure mode worth being loud about.

Reaching into a nested element (a key or dotted path pulling the value out of `{"path": ..., "caption": ...}`) is **out of scope**. Once the mapping can reach inside an element, the shapes are unbounded: a caption sibling an editor would expect to survive, a per-element visibility, an order key that would contradict the verbatim-index rule above. Each is a real feature with its own decisions and none belongs to a migration-window tool. A one-line script reshapes such a column into an array of strings, and the hard failure names exactly that.

**Re-runs never touch order.** Attachment creation is idempotent on `(host, field, asset)`, so a second run over the same array creates nothing and leaves the existing order alone. It does not rewrite order to match the source array again. Between runs a human may have reordered a gallery in the UI, and a registration command must never silently undo human editing. This is ticket 08's `firstOrCreate` over `updateOrCreate` reasoning applied one level out, so the importer has one rule rather than two: it creates what is missing and never overwrites what a person may have touched.

### Objects on an unconfigured bucket

Ticket 08's hard failure on unknown disk **stands unamended**. Where the bucket is no longer configured, the operator configures a read-only disk for the migration window; where no credentials exist to configure one, the row is skipped and reported. The record never gains a way to name a bucket the application cannot read.

An asset whose bytes the application cannot reach is broken at every surface the plugin owns: no byte size, no MIME ladder, no derivatives (ticket 12), and a Delivery route (ticket 07) that can only fail. Recording it would make the library lie about what it holds, and every one of those surfaces would need a new "unreadable" branch. If credentials exist, a disk can be configured. If they do not, the bytes are effectively gone and a skipped, reported row is the honest outcome. This costs the plugin no code at all, which is the strongest argument for it.

### The sniff step

The importer **offers** `--sniff` and never chains the standalone pass. Ticket 08's opt-in flag is unchanged, and `media:resolve-mimes --sniff` (ticket 11) remains a documented runbook step.

Sniffing fetches every object's bytes. On a large legacy library across an S3 or R2 disk that is a long, network-bound, chargeable pass, and welding it to the import denies the operator a fast registration run now and byte-fetching later. Keeping it both a flag on the importer and a standalone command lets the same work happen in one pass or two, which is what a migration window actually needs.

**In the window between**, ticket 13's earned disposition serves those assets as downloads and ticket 12 generates no image derivatives, so the grid is glyph tiles. The management page answers this with a `mime_source` **facet or filter**, so "show me everything still on the extension rung" is one click. Ticket 11 already put the column there and already ruled `mime_source` a management-page concern and never a picker facet, so this lands exactly where it belongs and stays useful after the migration, since a stuck rung is a real diagnostic. No banner or dashboard widget: that is a new surface alive only during a migration window, and it duplicates what the filter answers.

### The import report

Skips are recorded in an **import report**: a console summary plus a machine-readable file listing every skipped item with its reason (dropped duplicates, missing objects, null and empty elements, unreadable buckets). The file path is an optional flag defaulting to a run-timestamped file under `storage/logs/`, so a report always exists and consecutive runs do not clobber each other.

A migration over a large legacy library produces more skips than terminal scrollback usefully holds, and the operator's next action is to act on the list: chase missing files, fix a mapping, re-run. Since ticket 08 made re-runs idempotent, run-to-run diffing is the natural workflow and it needs a file. The default matters because the operator who most needs the report is the one who did not anticipate needing it. Writing skips to a database table was rejected: it builds persistent state for a one-time tool and drags the importer onto the management page that ticket 10 deliberately kept it off.

## Comments

- Resolved by grilling session on 2026-08-27. No ADR recorded: every decision here extends a rule ticket 08 already set rather than choosing between genuine alternatives at a hard-to-reverse boundary.
