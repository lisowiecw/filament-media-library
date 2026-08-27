# Define Thumbnail and Preview Derivatives

Status: resolved
Type: grilling
Blocked by: 07, 09

## Question

Ticket 09 fixed that the library grid renders a real preview for image and video assets, a poster frame plus duration for video, and a glyph tile for everything else. Nothing yet says where those previews come from. Decide whether the plugin generates stored derivatives (and at which sizes, on upload or lazily on first request, queued or inline), or serves the original scaled by the browser; what happens for video, which needs a poster frame extracted by a tool the application may not have installed; what a derivative's `object_key` and disk are, and whether derivatives are Media Assets themselves or a separate record; how a *private* asset's thumbnail is delivered, given ticket 07 requires private content to flow through the plugin-owned Delivery route with a signed URL rather than a raw presigned one, and a grid of 48 cards would mint 48 signed URLs per scroll batch; what the grid renders while a derivative is missing, still queued, or failed; and whether legacy imported objects (ticket 08) get derivatives generated on import, lazily, or never.

## Answer

The plugin generates and stores its own derivative objects. The grid never points a card at an original.

### What is generated

Two package-fixed variants, both WEBP, with configurable dimensions and a quality knob but not a configurable variant *set*: `thumb` (400px longest edge, the grid card) and `preview` (1600px longest edge, the lightbox and the management page's view panel). An open-ended variant registry was rejected: the lazy fallback below has to reason about the key space, and an unbounded one makes every application's matrix different. Neither variant ever upscales past the original.

Video additionally needs a poster frame, which requires a binary the host application may not have. That sits behind a swappable poster-frame driver interface. The default driver shells out to `ffmpeg`, is probed once and the probe result cached. When it is absent, the asset gets no poster derivative, the card falls back to the glyph tile plus the play badge, and the duration chip is omitted (duration comes from the same driver). The absence surfaces as a health note on the management page, never as an exception. No part of the plugin hard-depends on a binary.

### When it is generated

Eagerly on upload, queued, so a card is ready before anyone opens the library. Never inline in a web request.

A missing derivative at render time dispatches the job and renders the pending state. That single self-healing fallback also covers imports, failed jobs, a newly configured dimension, and any backfill, so no separate backfill mechanism exists.

Legacy imported assets (ticket 08) are generated lazily only, never on import: registration is a metadata-only operation that may touch a very large number of objects, and eager generation would turn it into a job stampede over objects nobody may ever open. A deliberate backfill is `media:regenerate-derivatives --missing`, run when the operator chooses.

### What a derivative is

A child row (`media_derivatives`: asset, variant, disk, object_key, width, height, bytes, status), not a Media Asset. A derivative is not reusable, attachable or nameable, and must never appear in a picker grid or the management table, so making it a Media Asset would mean excluding it from every query that touches assets. A keyless convention with a `Storage::exists()` check was also rejected: queued generation needs somewhere to record *pending* and *failed*, without which every miss re-dispatches forever.

Placement follows the parent (same disk). The key is `<derivatives-prefix>/<asset-ulid>/<variant>.webp`, so an asset's derivatives are removable by prefix and immutable by construction. Deleting or force-deleting an asset queues its derivatives for removal alongside the backing object; restoring an asset regenerates them lazily rather than resurrecting them.

### Delivering a private asset's thumbnail

A derivative inherits its parent's visibility. Private derivatives flow through the same plugin-owned Delivery route as the parent, which gains a variant parameter, re-checks View once per request, and redirects to the disk's temporary URL. Derivative responses carry `Cache-Control: private, immutable` with a signed TTL long enough to outlive a scroll session, rather than ticket 07's 5-minute default for originals.

The ticket's stated worry, that a 48-card batch mints 48 signed URLs, does not survive scrutiny: signing is a local HMAC inside the query that already renders the batch, not a round trip. The real cost is 48 policy evaluations, addressed inside the same mechanism by resolving View against the asset row the route already loaded, plus a per-request policy cache.

Three alternatives were weighed and rejected:

- **Public derivatives.** A 400px render of a private contract, ID scan or medical image *is* the content. ADR 0001 draws its line at checked versus unchecked, not at small bytes versus big bytes.
- **Inlining thumbnail bytes as `data:` URIs in the grid payload.** The only option that removes the per-card cost outright (zero requests, one authorization pass), but it would need a third deliberately tiny variant to be affordable, put binary blobs in a Livewire-rendered component, and destroy cross-batch browser caching, which is precisely what makes the second scroll free.
- **Handing out the storage disk's presigned URL for derivatives only.** A direct violation of ADR 0001: presigned URLs are unrevocable for their lifetime and skip View entirely.
- **A single batch endpoint returning all 48 thumbnails.** Cacheable only as a whole, and any filter change (the common case in ticket 09) invalidates the entire sprite, so its hit rate would be poor.

Per-card `<img loading="lazy">` requests are what an image grid costs anywhere, most of a batch is never fetched, and immutable keys make a repeat scroll a browser-cache hit.

### Card states

Pending and missing render identically: the type's glyph tile, dimmed, with no spinner and no polling. The derivative appears on the next natural render; showing progress on 48 cards at once is worse than a quiet tile. Failed is distinct: once the queue's retries are exhausted the row keeps `status: failed` and the reason, the card falls back permanently to the glyph tile, and it stops re-dispatching. Failures surface on the management page as a health count with a regenerate action, backed by `media:regenerate-derivatives` taking `--failed`, `--missing`, or a variant.


## Comments

- Amended by [Define Library Grid Performance Budget](20-grid-performance-budget.md) on 2026-08-27, under a
  new standing constraint that the plugin must not force heavy usage of the operator's object storage. The
  sizing in that ticket found derivative *storage* to be noise (about five cents a month on a 12,000 asset
  library) and the real cost to be reads of full originals, so these amendments target reads, not bytes.

  1. **Video poster frames are dropped, and the `ffmpeg` driver with them.** A poster frame requires pulling
     the video to the app server, so one 500 MB upload costs 500 MB of read and egress for a single frame.
     The swappable driver, the cached probe and the management-page health note all go. A video card is the
     glyph tile plus the play badge, always and everywhere: what this ticket specified as the degraded path
     when the binary was absent is now the only path. No optional binary remains anywhere in the plugin.
     The duration chip goes with the driver, which amends ticket 09.

  2. **`preview` (1600px) is generated on demand only.** `thumb` stays eager and queued on upload, because
     the grid is the common path and a fresh upload is usually attached straight away. `preview` is generated
     on its first actual request, since most assets never reach the lightbox or the management page's view
     panel. Noted for the record: this ticket's original eager-both ruling emitted both variants from a
     single decode of a single read, so on-demand `preview` trades that for a second read of the original for
     each asset someone does open full size. It was chosen deliberately as the more minimal default.

  3. **An original that is already thumbnail-sized gets no derivatives at all.** A browser-renderable raster
     (jpeg, png, webp, gif) under a configurable byte ceiling (default 32 KB) and under 800px on its longest
     edge registers zero derivative rows, and the card points at the original. This saves two writes and the
     whole generation job per asset, not merely bytes, and real libraries carry a long tail of logos, icons
     and badges. It generalizes a rule already made: ticket 13 rules that a sanitized SVG is its own
     thumbnail.

  4. **Lazy backfill dispatch is rate-capped.** This ticket correctly refuses to generate on import, but the
     lazy path still stampedes: the first person to browse a freshly imported 50,000 asset library triggers a
     job per card, each a full read of an original, which is roughly 125 GB of reads set off by one scroll.
     Concurrent generations and per-minute dispatch are both bounded by config, so a backfill trickles.
     `media:regenerate-derivatives` obeys the same cap.

  5. **The `thumb` job also computes the `blurhash` column** from the decode it already holds, per ticket 20.

  6. **Derivative URLs must be byte-stable within their TTL.** See the amendment on ticket 07: this ticket's
     `Cache-Control: private, immutable` was being defeated by ticket 07's per-render signature.
