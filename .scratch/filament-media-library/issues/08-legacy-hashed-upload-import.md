# Define Legacy Hashed Upload Import

Status: resolved
Type: research
Blocked by: 02, 03, 04

## Question

How can existing Laravel Storage uploads with hashed names remain usable while new uploads receive readable names? Decide whether optional import registers existing objects, copies them, or both; how files are discovered and mapped to Media Assets; and how unknown ownership, disk, visibility, and duplicate objects are handled without overwriting existing files.

## Answer

Import **registers existing objects in place** and never writes to the source disk. A legacy hashed key such as `avatars/9f2c1b7a4d.jpg` already satisfies ticket 04's definition of an object key — opaque, collision-resistant, and independent of any client-supplied name — so the imported Media Asset records that legacy path verbatim as its `object_key` and the bytes are left untouched. Nothing is renamed, moved, re-keyed, or overwritten. A `--copy` mode exists as an explicit, non-default opt-in for consolidating a legacy prefix under `media/`: it writes a new server-generated key, asserts the destination is `missing()` first, and never deletes the source. There is no move mode; automatic re-keying of every existing object remains out of scope.

**Discovery is column-driven by default.** The application declares which host model, column, disk, and field context to import, because the row holding the legacy path is the only place that knows ownership, host identity, and field context — the four things a bare object key cannot supply. It also costs zero list operations. Disk traversal is a supported but explicitly degraded `--source=disk` fallback: it requires a prefix, and it must iterate Flysystem's lazy `listContents($prefix, true)` rather than Laravel's `allFiles()`, whose `sortByPath()` buffers every key in memory before returning.

**Mapping.** `object_key` is the legacy path verbatim; `disk` is stated explicitly and never guessed. `original_client_filename` is the legacy basename — the only filename that ever existed server-side — and is never fabricated. `display_name` is that basename minus its extension, editable thereafter. Byte size comes from `Storage::size()`. MIME type resolves through a recorded ladder — stored `Content-Type`, then opt-in `--sniff` via `Symfony\Component\Mime\MimeTypes::guessMimeType()` on the fetched bytes, then extension, then null — because `Storage::mimeType()` on S3/R2 reports the stored header rather than sniffing content.

**Unknowns are recorded as unknown, never guessed.** An undeterminable disk is a hard failure. A missing object produces no row. Unknown `uploaded_by` stays null. Visibility resolves by explicit `--visibility` assertion, then a local-driver-only `getVisibility()` in a try/catch, then the disk's configured visibility, then private; it is never called on an `s3`-driver disk, because R2 leaves `GetObjectAcl` unimplemented and Laravel's `getVisibility()` has no catch, so the call raises rather than returns.

**Duplicates.** Identity is a unique index on `(disk, object_key)`, with `firstOrCreate` rather than `updateOrCreate` so later user edits survive a re-run. Re-running the import is therefore idempotent and concurrency-safe. Identical bytes stored at two keys remain two assets, consistent with ticket 04. Content hashing is not part of identity; `Storage::checksum()` returns an S3 ETag, which is not a content hash for multipart uploads and is unusable here.

**Coexistence needs no migration.** Because a readable name is presentation metadata and the object key is opaque storage identity, legacy hashed objects and new readable-named uploads differ only in how their keys happen to look. Storage operations use `(disk, object_key)` for both; users see `display_name` for both.

Supporting research: [Research: Legacy Hashed Upload Import](../research-08-legacy-hashed-upload-import.md).

## Comments

- Resolved by research subagent on 2026-08-26.
