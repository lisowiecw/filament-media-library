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
- [Define Authorization and Private Delivery](issues/07-authorization-and-private-delivery.md): One `MediaAssetPolicy` (view/update/delete/forceDelete/detach) plus `uploadMedia`/`attachMedia` gates, fail-closed by default except public reads; grid listing is never row-gated, only content delivery is; private content always flows through a single plugin-owned Delivery route (never a raw presigned URL), 5-minute default signed TTL, inline-by-default disposition; `uploaded_by` always recorded as provenance.
- [Define Legacy Hashed Upload Import](issues/08-legacy-hashed-upload-import.md): Import registers legacy objects in place and never writes to the source disk (`--copy` is an explicit opt-in, never a move); discovery is column-driven by default with lazy disk traversal as the degraded fallback; the legacy key becomes the `object_key` verbatim and its basename the original filename; unknown disk fails hard, unknown uploader stays null, and visibility is never read from an s3-driver disk; identity is a unique `(disk, object_key)` index with `firstOrCreate`, so re-runs are idempotent.
- [Define Library Grid Search, Filtering and Pagination](issues/09-library-grid-search-filtering-and-pagination.md): Substring AND search over name, filename, alt and uploader; a faceted sidebar (type, visibility, usage, uploader, date) whose counts are live and cross-computed; infinite scroll in batches of 48 with no numbered pages; a multiple selection resets on any filter or search change, announced rather than silent, with the footer always showing the live count.
- [Define Media Library Management Page](issues/10-media-library-management-page.md): An opt-in `MediaAssetResource` (`->withLibraryManagement()`) rendering a table, not the picker's grid, listing every asset unscoped; rename/delete/force delete/restore/download/upload but never replace, revisibility or move; one usage resolver feeding a count column, a view-page panel and the force-delete confirmation; bulk delete and restore but never bulk force delete; `viewAny` gates the page; orphan assets renamed **unattached** and host-less **external references** recorded as Attachments so the usage list can tell the truth.

- [Define Asset Provenance Fields](issues/11-asset-provenance-fields.md): The record gains `source` (`upload`/`import`, origin only, never encoding byte ownership since `object_key` already does), `mime_source` (which rung of the ladder produced the MIME, uploads included), and a nullable `import_source` string on the `host.column` convention; three real columns rather than JSON, re-resolvable through a targeted `media:resolve-mimes` command, and surfaced on the management page only, never as a picker facet.

- [Define Thumbnail and Preview Derivatives](issues/12-thumbnail-and-preview-derivatives.md): The plugin stores two fixed WEBP variants (`thumb` 400px, `preview` 1600px) as child `media_derivatives` rows, never as Media Assets; generated eagerly and queued on upload, lazily on a render miss (which also covers legacy imports, never generated on import), never inline; video poster frames and duration sit behind a probed, swappable `ffmpeg` driver that degrades to a glyph tile rather than a hard dependency; a derivative inherits its parent's visibility, so private thumbnails go through the Delivery route with a variant parameter and immutable long-TTL caching, with public derivatives, presigned derivative URLs, inline `data:` URIs and batch sprite endpoints all rejected; pending and missing cards render a dimmed glyph tile with no polling, and exhausted failures stick as `status: failed` and stop re-dispatching.

## Not yet specified

- Exact readable-name algorithm, collision behavior, Unicode normalization, and whether the name ever influences the object key.
- Multi-disk or tenant-aware asset behavior and whether assets can move between buckets. Ticket 06 fixed that tenant scoping must be a global query scope rather than a per-field setting, and left `->scopeLibrary()` as the sanctioned per-picker escape hatch; how tenancy resolves that scope is still open.
- Multi-value legacy columns: how the importer discovers and orders paths held in a JSON array column, rather than the single-value column ticket 08 assumed.
- Legacy objects on a bucket the application no longer configures, where no disk name can be supplied.
- Debounce and query budget for the picker grid: ticket 09 accepted one aggregate per facet dimension per query change, but not what happens to that budget on a very large library or a slow connection.
- Whether Filament 4 compatibility ships in the same Composer line or a separately tested release, plus the exact package namespace and release tags.
- Perceived grid performance on a slow connection: whether a tiny blurred placeholder is inlined in the grid payload so cards paint before their derivative arrives. Ticket 12 fixed the delivery mechanism and left this as an additive, measurement-driven change rather than an authorization one.
- Derivative storage growth and reclamation: ticket 12 fixed that an asset's derivatives are removable by key prefix and die with the asset, but not how a dimension change retires the objects generated under the old settings, nor what a very large library's derivative footprint costs.

## Out of scope

- Resource-specific blog-post implementation; the blog post is only an example consumer.
- Automatic migration or renaming of every existing hashed object without explicit user action.
- Provider-specific storage APIs outside the Laravel filesystem contract.
- Exposing the legacy importer as a Filament action on the management page: ruled out by [Define Media Library Management Page](issues/10-media-library-management-page.md). It is a migration-window tool taking a disk name, column mapping and `--copy` flag, run by someone with shell access, not by the content editor the page serves.
