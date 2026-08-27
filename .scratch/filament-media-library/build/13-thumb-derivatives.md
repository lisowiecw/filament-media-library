# 13: Thumb Derivatives

**What to build:** a grid card shows a real preview instead of a glyph, generated off the request cycle, without setting off a stampede of reads on a freshly imported library.

**Blocked by:** 03, 08

**Status:** ready-for-agent

- [ ] `media_derivatives` migration: asset, variant, disk, object key, width, height, bytes, status, failure reason, nullable `config_digest`, timestamps
- [ ] A derivative is a child row, never a Media Asset, and never appears in a picker grid or the management table
- [ ] Placement follows the parent, including visibility; key layout `<derivatives-prefix>/<asset-ulid>/<variant>.webp`
- [ ] `thumb` at 400px longest edge, WEBP, dimensions and quality configurable but the variant set fixed, never upscaling
- [ ] Generated eagerly on upload but always queued, never inline in a web request
- [ ] The thumb job also computes the `blurhash` column from the decode it already holds
- [ ] A browser-renderable raster (jpeg, png, webp, gif) under a configurable byte ceiling (default 32 KB) and under 800px on its longest edge registers no derivative rows at all, and the card points at the original
- [ ] A missing derivative at render time dispatches the job and renders the pending state, which is the single self-healing path covering imports, failures and re-configuration
- [ ] Lazy backfill dispatch is rate-capped by config on both concurrency and per-minute dispatch
- [ ] Pending and missing render identically: a dimmed glyph tile, no spinner, no polling
- [ ] Once retries are exhausted the row sticks at `status: failed` with a reason and stops re-dispatching
- [ ] A video card is a glyph tile plus a play badge, always; no optional binary is required anywhere
- [ ] Cards paint the BlurHash from the grid payload while the thumbnail is in flight, falling back to the dimmed tile when it is null
