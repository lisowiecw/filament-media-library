# Define Library Grid Performance Budget

Status: resolved
Type: prototype
Blocked by:

## Question

Two perceived-performance questions the grid tickets left open, both answerable against the same prototype.

Ticket 09 accepted one aggregate query per facet dimension per query change, with live cross-computed counts, but did not say what that budget does on a very large library or a slow connection. Decide the debounce on search input, whether facet counts are computed on the same round trip as the results or lag behind them, and what degrades first when the budget is exceeded: are counts dropped to approximations, deferred, or is the facet sidebar itself the thing that becomes optional above a library size.

Ticket 12 fixed derivative delivery and left open whether a tiny blurred placeholder is inlined in the grid payload so cards paint before their derivative arrives. Decide whether that ships, and if so what it costs the payload for a batch of 48, given ticket 12 already rules that pending and missing cards render a dimmed glyph tile with no polling.


## Answer

Settled against the prototype on branch `prototype/20-grid-performance`, file
`.scratch/filament-media-library/prototypes/20-grid-performance.PROTOTYPE.html`, which simulates the server
so the budget can be felt rather than argued: result cost scales with an index-assisted scan of the scoped
set, each facet dimension costs its own aggregate on top, and the request log marks every answer discarded
because the query moved on.

A cost constraint arrived while this ticket was open and reshaped it: **the plugin must not force heavy usage
of the operator's object storage.** That constraint is now a standing preference on the map. It does not bear
on the first two questions, which are pure host-database load with no object-storage involvement at all, but
it decides the third and it reopened ticket 12.

### Search debounce

**400ms, package-global config, not per field.**

At zero debounce every keystroke fans out into a result query plus three aggregates, and the prototype shows
the discard ratio climbing past 70% for a fast typist on a slow link: almost all of that traffic answers a
query the user has already abandoned. 400ms sits clear of the inter-keystroke gap of a touch typist, so a
whole word is typed as one query rather than nine, and it is still short enough that a deliberate pause reads
as immediate.

It is global rather than per field because the debounce describes the deployment, its database and its users,
not the field. A per-field knob would invite tuning the wrong variable: a slow grid is a database problem, and
letting one field paper over it hides the signal.

### Facet count timing and degradation

**Counts always ride the same round trip as the results. Above a configurable threshold on the field-scoped
set (default 50,000 rows) the counts are dropped entirely, and the facets stay listed and clickable without
numbers.** Never trailing, never approximate.

Ticket 09's justification for live cross-computed counts is that they make the shape of the library legible
and stop a user landing on an empty grid. A count that describes a superseded query does not do that; it
misleads. The prototype's *Counts that lie* walkthrough shows the failure directly: on a slow link the user
reads a number, clicks it, and lands somewhere else, because the number described the previous query. So
trailing is rejected. Approximate is rejected for the same reason one rung down: a rounded number that is
also late keeps something on screen at the cost of it being both vague and stale.

Dropping the number is the honest degradation. The user sees no promise rather than a false one, which is the
same fail-closed instinct that decides tickets 07, 13 and 17. There is no banner explaining the absence: it
is noise for the content editor the picker serves, and the operator who set the threshold already knows.

The threshold measures the field-scoped set before search and facets narrow it, so it is a cheap and
cacheable number rather than another aggregate.

### Card placeholder before the thumbnail lands

**A BlurHash ships, as a nullable `blurhash` string column on the asset.**

It is roughly 30 bytes, computed inside the `thumb` generation job from a decode that has already happened,
so it costs zero extra reads, zero storage objects and zero operations for the life of the asset. The grid
payload carries it, the card paints the blur immediately from JSON, and the real thumbnail replaces it on
arrival. On the constrained resource it is free, which is why it survives the cost constraint that reshaped
everything around it.

Rejected: the **inline 20px WEBP data URI** at roughly 450 bytes a card, which is fifteen times the bytes for
a difference invisible at a 104px card, and close enough to the `data:` URI approach ticket 12 already
rejected to fall to the same reasoning. Rejected: **a flat dominant colour** at 4 bytes, cheaper still, but a
flat tile does not read as "an image is coming", so ticket 12's viewport-lazy grid looks broken while
scrolling and the placeholder fails at its only job.

A null blurhash (legacy imports before backfill, non-raster types, a failed generation) falls back to ticket
12's dimmed glyph tile, unchanged.

### The cost model this produced

Sized at 12,000 assets averaging 2.5 MB, at current R2 list prices:

| Item | Volume | Cost |
| --- | --- | --- |
| Originals | 30 GB | ~$0.45/mo |
| `thumb` 400px WEBP | 240 MB | ~$0.004/mo |
| `preview` 1600px WEBP | 3 GB | ~$0.045/mo |
| Upload writes, 3 PUT per asset | 36k Class A | ~$0.16 once |
| Grid reads, caching working | ~90k Class B/mo | ~$0.03/mo |

**Derivative storage is noise.** It is about 10% on top of originals and roughly five cents a month, so
shrinking thumbnails, dropping quality or trimming the variant set buys nothing worth having. The cost is
reads of full originals, and reads of derivatives when caching fails. Those spike in four places, which is
what reopened ticket 12 and amended ticket 07.

### What this reopened

The cost constraint is not confined to this ticket. Recorded as amendments rather than as new tickets,
because they were decided here rather than left open:

- **Ticket 12**: video poster frames and the `ffmpeg` driver dropped entirely; `preview` becomes on-demand;
  small originals skip derivatives; lazy backfill dispatch is rate-capped; the `blurhash` column is generated
  in the thumb job.
- **Ticket 07**: the "regenerated fresh on every render" signature contradicted ticket 12's `immutable`
  caching. Derivative URL expiry is now quantized so the cache key stops churning.
- **Ticket 09**: the video duration chip is dropped, following the driver.

## Comments

- Resolved with the requester on 2026-08-27 against the prototype. The storage cost constraint was introduced
  mid-ticket and is recorded as a standing preference on the map.
