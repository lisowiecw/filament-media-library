# Define Library Grid Search, Filtering and Pagination

Status: resolved
Type: prototype
Blocked by: 06

## Question

Within the library modal fixed by ticket 06, how does the grid behave at realistic library sizes? Decide the search fields and matching strategy, which filter controls are exposed (type, visibility, uploaded-by, date, attached/unused), whether paging is numbered pages or infinite scroll, the page size, and how bulk selection interacts with paging — specifically whether a multiple-selection set survives a page change or a filter change.

Ticket 06 fixed only that search exists and is scoped to the assets the field may offer.

## Answer

Settled against a three-variant UI prototype on branch `prototype/09-library-grid`, file `.scratch/filament-media-library/prototypes/09-library-grid.PROTOTYPE.html`, seeded with 260 assets of which 176 pass a representative field scope. Variant A was toolbar chips plus numbered pages, variant B a faceted sidebar plus infinite scroll, variant C a `key:value` query language plus a selection tray. **Variant B wins.**

### Search

One search input over the assets already in field scope (ticket 06 fixes that scope: mime matches `acceptedFileTypes`, and the asset is public or the field uploads private). Matching is case-insensitive substring across four fields, the readable name, the original filename, the alt text, and the uploader. Whitespace splits the query into terms and every term must match (AND), so `hero 2024` narrows rather than widens. Matches are highlighted in the card name. No query language: variant C's `type:` / `is:` / `by:` / `since:` tokens were rejected as a picker affordance because they are unteachable inside a modal that content editors open once a week, and every token has a facet equivalent.

### Filters

A left facet sidebar, not a toolbar, with these dimensions:

- **Type**: All, Images, Video, PDF, derived from the field's own accepted types rather than hardcoded.
- **Visibility**: Any, Public, Private. This is a read-only *view* filter over what the field may already offer, and does not contradict ticket 06's ruling that visibility is never a picker *control*: it changes nothing about the asset or the upload, it only narrows what is listed.
- **Usage**: Any, Not attached anywhere, In use.
- **Uploaded by**: Anyone, then one entry per uploader.
- **Uploaded**: Any time, Last 7 days, Last 30 days, Last year.

Sort is a separate select (Newest, Oldest, Name, Most used), defaulting to Newest.

Facets **carry live counts**, and each facet's counts are computed against every active filter *except its own dimension*, so the numbers describe what selecting that option would yield rather than what is currently shown. This costs one aggregate query per dimension per query change. That cost is accepted deliberately: the counts are what make the shape of the library legible before the user commits to a filter, and they are what stop a user landing on an empty grid. Implementations should compute them in a single grouped query per dimension against the scoped, searched set, and debounce the search input so the aggregates fire per pause, not per keystroke.

The **unused** filter is exposed in the picker as well as on the management page (ticket 10). It is a harmless view filter in both places, and finding never-attached assets is a real picker task, not only a housekeeping one. Ticket 10 remains free to add destructive actions around it that the picker never gets.

### Paging

**Infinite scroll, no numbered pages.** Batch size 48. The scroller loads the next 48 as the viewport nears the end, and also offers an explicit "Load more" button so the behaviour is reachable without a scroll gesture and remains keyboard-operable. An end-of-library marker states the total. There is no page-size control: it exists only to serve numbered pages, which are gone.

Numbered pages lost because the picker is a browsing surface, not a data table. The grid is visual, the user is scanning for a picture they half remember, and a page boundary interrupts that scan for no benefit. Nothing in the picker needs a stable, linkable page number.

### Selection across a changing result set

A multiple selection **resets** when the visible set changes, on a filter change and on a search change alike. It does not silently persist off-screen.

This is the conservative reading. A selection the user cannot see is a selection they cannot verify or undo, and attaching assets they can no longer point to is the worse failure. Resetting is visible and immediately correctable: they reselect. Persisting is invisible and produces a wrong attachment.

Two obligations follow. The reset must be **announced**, not silent, so a filter change that discards a selection says so. And because the reset makes cross-filter gathering impossible, the modal footer must always show the live selection count and the ordered selection, so the user knows exactly what a filter change is about to cost them.

Selection order is preserved as the field's ordered `int[]` per ticket 06, and clicking a card toggles it.

### Thumbnails

Image and video assets render an actual preview in the card, not a type glyph. Video additionally carries a play badge and a duration chip so it is distinguishable from a still at grid size. Types with nothing to preview (PDF, audio, documents) keep a tinted glyph tile. The prototype fakes previews with procedural SVG; the real plugin needs a derivative or poster-frame story, which is now fog.


## Comments

- Amended by [Define the Readable Name Algorithm](16-readable-name-algorithm.md) on 2026-08-27: see its object-key lookup obligations.
