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
- [Define Storage Bucket and Visibility Contract](issues/03-storage-bucket-and-visibility-contract.md): Use configured Laravel disk names for bucket selection, default new objects to `media` and private visibility, and keep database asset selection and opaque storage identity separate from readable names.
- [Define Readable Name and Upload Identity](issues/04-readable-name-and-upload-identity.md): Preserve original and editable names as metadata, detect server-derived file facts, and make name collisions explicit choices between creating a new asset or cancelling without overwriting storage.
- [Define Asset Lifecycle and Deletion Policy](issues/05-asset-lifecycle-and-deletion-policy.md): Detach never deletes; replace creates a new asset and detaches the old; explicit delete soft-deletes and queues backing-object cleanup, blocked by default when shared (force delete overrides, showing the usage list); orphan cleanup is report-only; all package-global.
- [Define Picker API and Selection Workflow](issues/06-picker-api-and-selection-workflow.md): One `MediaPicker` field opening one library modal, accepting drops at every surface including the inline trigger; the grid offers an asset only when its mime matches `acceptedFileTypes` and it is public or the field uploads private; disk and directory are upload placement and never scope; visibility is field config, never a picker control.

## Not yet specified

- Upload validation limits and security treatment for arbitrary file types, including executable or browser-active formats.
- Exact readable-name algorithm, collision behavior, Unicode normalization, and whether the name ever influences the object key.
- Private URL lifetime, response headers, inline/download behavior, and route versus direct provider URL generation. Ticket 06 narrowed the exposure by keeping private assets out of public fields, but a private field may still attach a public asset, and private attachments still need a delivery contract.
- Authorization baseline and the exact permissions for library operations and private URL issuance, gating at minimum detach, delete-unshared, and force-delete-shared.
- Whether uploader identity is stored always or only when authentication is required.
- How legacy hashed uploads are discovered, mapped to assets, and handled when ownership or disk is unknown.
- Multi-disk or tenant-aware asset behavior and whether assets can move between buckets. Ticket 06 fixed that tenant scoping must be a global query scope rather than a per-field setting, and left `->scopeLibrary()` as the sanctioned per-picker escape hatch; how tenancy resolves that scope is still open.
- Whether Filament 4 compatibility ships in the same Composer line or a separately tested release, plus the exact package namespace and release tags.

## Out of scope

- Resource-specific blog-post implementation; the blog post is only an example consumer.
- Automatic migration or renaming of every existing hashed object without explicit user action.
- Provider-specific storage APIs outside the Laravel filesystem contract.
