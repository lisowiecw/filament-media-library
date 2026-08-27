# Define Derivative Reclamation

Status: resolved
Type: grilling
Blocked by:

## Question

Ticket 12 fixed that a derivative is a child row, is removable by key prefix, and dies with its asset. It did not say what happens when the *settings* change rather than the asset.

Decide: when an application changes the configured `thumb` or `preview` dimensions, what retires the objects generated under the old settings, given the existing rows still point at real, still-servable files; whether a derivative records the settings it was generated under so staleness is detectable at all, or whether a dimension change is simply a manual regeneration a human triggers; whether regeneration is a command, a queued sweep, or lazy on the next render miss (which ticket 12 already built for the missing case); and what the grid serves during the window when an asset's derivative is stale but present, since serving the old one is correct-looking and serving nothing is not.

## Comments

- Context from [Define Library Grid Performance Budget](20-grid-performance-budget.md), 2026-08-27. This
  ticket's surface has changed while it sat open, and the changes should be assumed when it is worked:
  video poster frames no longer exist, so the reclamation population is images plus sanitized SVGs only;
  `preview` is now generated on demand, so a `preview` object may simply be absent for an asset that has
  one recorded elsewhere, and "stale but present" is no longer the only window this ticket must cover;
  small originals register no derivative rows at all, so a settings change must not sweep them into
  regeneration; lazy backfill dispatch is rate-capped, which is the cap any queued sweep decided here has
  to obey rather than reinvent; and the `blurhash` column rides on the `thumb` job, so regenerating a
  `thumb` recomputes it. Under the new standing constraint on storage cost, "lazy on the next render miss"
  is now the cheap option and a queued sweep the expensive one.

## Answer

**There is nothing to reclaim.** Ticket 12's key is `<derivatives-prefix>/<asset-ulid>/<variant>.webp`, with no
dimensions in it, so regenerating a derivative overwrites the object in place. A settings change strands no
objects and leaks no storage. The real question this ticket answers is narrower: how a derivative generated
under old settings becomes *detectable*, what refreshes it, and what is served meanwhile.

### Staleness is recorded, not inferred

Each `media_derivatives` row gains a short `config_digest` string: a hash of the target longest edge and the
quality knob, nothing else.

Inferring staleness from the stored width and height was rejected because it is wrong in the common case: a
300px original under a 400px `thumb` yields a 300px derivative that is perfectly current, and a quality-only
change moves no dimension at all. A digest makes stale a query instead of a guess.

The digest deliberately excludes:

- **Output format**, which is package-fixed at WEBP and cannot vary.
- **The small-original threshold**, which decides whether a row exists rather than what a row contains.
- **The encoder library version**, which would mark every derivative in the library stale on a routine
  `composer update`, turning a patch release into a storage bill.

A `null` digest means *unknown*, not stale, and is never selected. Rows predating the column, legacy imports
included, stay as they are: they are correct derivatives, and marking a whole existing library stale on
upgrade would be a breaking release under ticket 19's own definition for no behavioural gain. A `null` fills
in the first time that row is regenerated for any other reason, and `--missing` remains available to anyone
who wants a deliberate refresh.

### Refresh is an operator's command, never automatic

`--stale` joins `--failed` and `--missing` as a selector on ticket 12's `media:regenerate-derivatives`,
combinable with a variant filter, obeying ticket 20's rate cap and ticket 17's tenant requirement on commands.
It gains `--dry-run`, reporting the count it would regenerate broken down by variant, so the operator can see
the size of a regeneration before paying for it. There is no default selector: a bare invocation stays an
error rather than meaning everything.

Two alternatives were rejected:

- **Lazy on next render, treating stale like missing.** Under the storage-cost constraint this *sounds* like
  the cheap option and is not. It converts one config edit into a read of every original plus a write, spread
  invisibly across whatever traffic happens to arrive, with no way to see the cost coming and no way to stop
  it mid-flight. Ticket 12's lazy fallback stays scoped to *absence*.
- **An automatic sweep when the plugin notices config changed.** Config is deployed, so this fires on every
  deploy of every node.

### The stale window serves the stale object

An asset whose `thumb` is stale-but-present serves that object, silently, with no marker on the card. It is a
correct downscale of the right asset at the wrong size, and the grid renders a 400px card either way; marking
it would put an operator's concern on a content editor's screen. Staleness surfaces in exactly one place:
ticket 12's management-page health count gains a `stale` line beside `failed`, with the same regenerate
action.

Rowless small originals are never swept in, and rows that a *lowered* threshold makes redundant are left
alone rather than deleted. Both directions cost money to correct and neither is visibly wrong.

### The digest rides on the Delivery URL

Overwrite-in-place collides with the `immutable` long-TTL caching ticket 12 set and ticket 20's quantized
expiry preserved: a stable key means every browser and intermediary holds the old bytes until the TTL lapses,
so a deliberate command would take effect at an unpredictable time. The `config_digest` therefore appears as
a segment on the Delivery route's derivative URL, so a regenerated derivative is a different URL and the cache
key follows the bytes, which is what `immutable` actually promises. This costs one short segment on a URL the
plugin already mints from the row it already loads. Dropping `immutable` for derivatives was rejected as
throwing away the win ticket 20 just recovered, across every asset, to fix a window that opens only on rare
config edits.

### The digest is the commit point

Write the object, then update the row. The digest moves only after a successful write, and the old object is
never deleted first, so there is no window in which the grid has nothing to serve. A crash before the write
leaves old object and old digest, so the row is still stale and the next run picks it up; a crash between the
write and the row update leaves new bytes under an old digest, which the next run simply rewrites. Repeated
encode failure marks the row `failed` exactly as ticket 12 defines, and a `failed` row is no longer counted
as stale. Regenerating a `thumb` recomputes the `blurhash` column from the decode already in hand, per
ticket 20.

### Amendments to closed tickets

- **Ticket 12**: `media_derivatives` gains a `config_digest` column; the management page's health count gains
  a `stale` line; `media:regenerate-derivatives` gains `--stale` and `--dry-run`. The lazy render-miss
  fallback is confirmed as covering absence only, never staleness.
- **Ticket 07**: the derivative Delivery URL gains a digest segment alongside the variant parameter, so
  quantized expiry and `immutable` survive an in-place overwrite.
