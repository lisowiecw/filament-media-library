# Define Authorization and Private Delivery

Status: resolved
Type: grilling
Blocked by: 03, 05, 06

## Question

What authorization contract governs viewing, selecting, uploading, attaching, detaching, downloading, renaming, deleting, and issuing temporary URLs for private Media Assets? Decide how Laravel policies/gates integrate and what the public/private delivery contract must guarantee.

## Answer

### Policy surface

One standard Laravel `MediaAssetPolicy` on the `MediaAsset` model covers `view`, `update` (also governs rename), `delete`, `forceDelete`, and `detach` — exactly the action space ticket 05 named. Two package-defined `Gate::define()` closures, `uploadMedia` and `attachMedia`, cover the two actions that precede an asset's existence; each receives the user plus host-model context (instance when available, else class) and the field name, matching the field-scoped boundary ticket 06 already established (`HasMediaAttachments`, `$mediaFields`).

No dedicated `rename` or `download` ability exists: rename reuses `update`, download reuses `view`.

### Default posture: fail-closed, except public reads

Every mutating or private-content action (upload, attach, detach, update/rename, delete, forceDelete, private download, View/temporary-URL issuance) is denied by default until a host app registers its own policy/gate implementations. Viewing or selecting a **public** asset requires no policy check at all — Filament's own panel auth is the only gate, consistent with ticket 03's "public means publicly addressable."

### Grid listing is never row-gated

Per the [[View]] / [[Offer]] distinction now in `CONTEXT.md`: showing an asset's metadata/thumbnail in the library grid to any panel user requires no per-row policy check (no N+1 checks, no query-scope duplication of policy logic). The `view` ability is checked only at the moment real content is fetched — through the [[Delivery route]] or a forced download. Apps needing row-level grid restriction use ticket 06's `->scopeLibrary()` escape hatch, not a new mechanism.

### Private delivery contract

A single plugin-registered signed route (the [[Delivery route]], e.g. `media/{asset}/download`) is the sole path to a private asset's content. It re-checks `view` on every hit, then redirects to the disk's native `temporaryUrl()` when the disk supports it, or streams the file directly otherwise. A raw presigned URL is never handed to the browser — see ADR-0001 for why. Public assets bypass this route entirely and resolve straight to `Storage::disk($disk)->url($key)`, preserving CDN/browser caching.

Signature TTL is package config, reading an env var, defaulting to 5 minutes; regenerated fresh on every render rather than cached. Response disposition is `inline` by default (so thumbnails work as plain `<img src>`); a `?download=1` query flag on the same route forces `attachment`. One route, one policy check, two response modes.

### Usage list and uploader identity

Force-delete's usage list (ticket 05) is informational only — it never checks host-model authorization on the records it names; the requester has already cleared the more-privileged `forceDelete` gate, and the plugin stays host-model-agnostic.

A nullable `uploaded_by` column is always persisted on Media Asset, set to `Auth::id()` when authenticated at upload time, null otherwise (see [[Uploader]] in `CONTEXT.md`) — provenance only, no ownership or permission implied — so host apps can write "uploader or admin only" policies without inventing their own tracking.

Domain model updated: `CONTEXT.md` now defines View, Delivery route, and Uploader, and clarifies that Offer never implies View. `docs/adr/0001-private-media-always-served-through-plugin-route.md` records the delivery-routing decision.

## Comments

- Resolved with the requester on 2026-08-26 via grilling; all ten questions accepted as recommended.
- Amended by [Define Library Grid Performance Budget](20-grid-performance-budget.md) on 2026-08-27.
  This ticket rules that the signature is "regenerated fresh on every render rather than cached", while
  ticket 12 gives derivative responses `Cache-Control: private, immutable` with a long TTL. Those cannot both
  hold: a fresh signature carries a fresh expiry, so the URL string changes on every render, `immutable`
  matches nothing, and every picker open refetches every visible card. That is the largest recurring
  object-storage read cost in the plugin.

  **Derivative URLs quantize their expiry**, rounding down to a bucket boundary (6 hours by default), so the
  same asset plus variant yields a byte-identical URL for the life of the bucket and the browser cache
  actually hits. This weakens no authorization: the Delivery route still re-checks `view` on every hit, per
  this ticket and ADR-0001, and the TTL still bounds how long a leaked URL survives. It changes only how
  often the cache key churns.

  Originals keep the 5-minute default and the per-render signature. The quantized window applies to
  derivative variants only, which are the population fetched 48 at a time.
