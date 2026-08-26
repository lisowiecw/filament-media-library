# Filament Media Library Plugin

Labels: wayfinder:map

## Destination

A written technical specification for a reusable Laravel Filament plugin that provides a Media Library and configurable file picker for Laravel 13 projects, targeting Filament v5 with best-effort Filament v4 compatibility. The plugin supports upload-or-select workflows, reusable Media Asset relationships, Laravel filesystem storage including Cloudflare R2 buckets, public/private visibility, readable names, and arbitrary file types.

## Notes

Domain: reusable media assets and Filament resource-form integration.
Skills: grilling, domain-modeling, research where external framework/provider facts are required.
Standing preferences: preserve existing hashed uploads; separate human-readable names from storage object keys; use Laravel filesystem abstractions; make picker settings override global defaults; do not delete reusable assets when detached.

## Decisions so far

<!-- Resolved tickets are indexed here; their full decisions remain in the child issues. -->

- [Define Platform and Package Contract](issues/01-platform-and-package-contract.md): Guarantee Laravel 13/PHP 8.3+ and Filament 5; keep Filament 4 best effort behind shared public APIs and a compatibility matrix.
- [Define Media Asset and Relationship Model](issues/02-media-asset-and-relationship-model.md): Plugin-owned reusable assets attach polymorphically to host models through ordered, field-scoped relationships without duplicate selections.

## Not yet specified

- Upload validation limits and security treatment for arbitrary file types, including executable or browser-active formats.
- Exact readable-name algorithm, collision behavior, Unicode normalization, and whether the name ever influences the object key.
- Private URL lifetime, response headers, inline/download behavior, and route versus direct provider URL generation.
- Deletion modes, cleanup triggers, and shared-reference handling.
- Authorization baseline and the exact permissions for library operations and private URL issuance.
- Whether uploader identity is stored always or only when authentication is required.
- How legacy hashed uploads are discovered, mapped to assets, and handled when ownership or disk is unknown.
- Picker UX details such as search, filters, previews, pagination, bulk actions, rename, and delete controls.
- Multi-disk or tenant-aware asset behavior and whether assets can move between buckets.
- Whether Filament 4 compatibility ships in the same Composer line or a separately tested release, plus the exact package namespace and release tags.

## Out of scope

- Resource-specific blog-post implementation; the blog post is only an example consumer.
- Automatic migration or renaming of every existing hashed object without explicit user action.
- Provider-specific storage APIs outside the Laravel filesystem contract.
