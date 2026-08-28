# Release Notes

## [Unreleased](https://github.com/lisowiecw/filament-media-library/compare/v0.1.0...1.x)

- Authorization and delivery: a fail-closed `MediaAssetPolicy`, the `uploadMedia` and `attachMedia` gates, and one signed Delivery route per panel that re-checks `view` on every request, earns its disposition, and carries a restrictive content policy on every response.
- Package skeleton: service provider, `MediaLibraryPlugin`, `config/media-library.php`, and a Testbench harness hosting a real Filament panel.


## [v0.1.0](https://github.com/lisowiecw/filament-media-library/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
