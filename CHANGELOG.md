# Release Notes

## [Unreleased](https://github.com/lisowiecw/filament-media-library/compare/v0.1.0...1.x)

- Preview on demand and variant delivery: a private derivative is addressed through the Delivery route's `variant` parameter, which re-checks `view` against the asset it already loaded and streams the rendering with `Cache-Control: private, immutable`; no presigned derivative URL is ever handed out. A derivative URL's expiry is quantized to a configurable bucket (six hours by default), so it is byte-stable inside its window and the cache actually hits, while an original keeps its five-minute per-render signature; the settings digest rides in the URL, so a regeneration under changed settings is never masked by a cached response. `$asset->previewUrl()` resolves the 1600px `preview`, generated on its first actual request and never at upload.
- BlurHash placeholders: a card standing in for a thumbnail paints the asset's own BlurHash, decoded in PHP and painted as CSS gradients, so the package still ships no JavaScript and no stylesheet. The hash stays on the tile as `data-blurhash` for an application that wants to decode it properly over the top.
- Thumb derivatives: a grid card paints a `thumb` derivative, 400px on the longest edge and WEBP, generated off the request cycle and stored beside the original under the parent's placement and visibility. A missing derivative at render time queues the job and paints the pending tile, rate-capped per request and per minute; a small browser-renderable original is its own thumbnail and registers no rows at all. **This adds `ext-gd` to the package's requirements**, so check for it before upgrading.
- Library grid: the picker's modal opens on a Library tab that offers what the field accepts, searched by one input across name, filename, alt text, uploader and object key, loaded 48 at a time to an end marker, with the live ordered selection in the footer.
- Public placement: `$asset->url()` is the supported way to address an asset, resolving a public one to the disk's own URL so CDN and browser caching survive, and a private one to the signed Delivery route. `$asset->downloadUrl()` always resolves to the route, since a link cannot tell the browser to save a foreign origin's response.
- Authorization and delivery: a fail-closed `MediaAssetPolicy`, the `uploadMedia` and `attachMedia` gates, and one signed Delivery route per panel that re-checks `view` on every request, earns its disposition, and carries a restrictive content policy on every response.
- Package skeleton: service provider, `MediaLibraryPlugin`, `config/media-library.php`, and a Testbench harness hosting a real Filament panel.


## [v0.1.0](https://github.com/lisowiecw/filament-media-library/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
