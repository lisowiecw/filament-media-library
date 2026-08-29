# Release Notes

## [Unreleased](https://github.com/lisowiecw/filament-media-library/compare/v0.1.0...1.x)

- Library grid: the picker's modal opens on a Library tab that offers what the field accepts, searched by one input across name, filename, alt text, uploader and object key, loaded 48 at a time to an end marker, with the live ordered selection in the footer.
- Public placement: `$asset->url()` is the supported way to address an asset, resolving a public one to the disk's own URL so CDN and browser caching survive, and a private one to the signed Delivery route. `$asset->downloadUrl()` always resolves to the route, since a link cannot tell the browser to save a foreign origin's response.
- Authorization and delivery: a fail-closed `MediaAssetPolicy`, the `uploadMedia` and `attachMedia` gates, and one signed Delivery route per panel that re-checks `view` on every request, earns its disposition, and carries a restrictive content policy on every response.
- Package skeleton: service provider, `MediaLibraryPlugin`, `config/media-library.php`, and a Testbench harness hosting a real Filament panel.


## [v0.1.0](https://github.com/lisowiecw/filament-media-library/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
