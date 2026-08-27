# 03: Ingest Service, Happy Path

**What to build:** handing the plugin an uploaded file plus a Placement produces a stored object and a Media Asset row whose readable name is what the person typed and whose object key is opaque. This is the seam the picker and the management page will both call.

**Blocked by:** 02

**Status:** ready-for-agent

- [ ] A Placement value object carrying disk, directory prefix and visibility, resolved from field configuration over package config, defaulting to private under a `media` prefix
- [ ] One ingest entry point taking an uploaded file plus a Placement and returning a `MediaAsset`
- [ ] Name algorithm: basename only, C0/C1 and bidi overrides stripped, NFC, trimmed, `original_client_filename` capped at 255 bytes; `display_name` is the name with its final extension removed, trimmed, whitespace runs collapsed, capped at 255 characters, falling back to the full filename when stripping empties it, never null
- [ ] Scripts preserved and never transliterated; no separator or case prettifying; a leading dot is part of the name
- [ ] Persisted `extension` follows the client name lowercased; the object key's extension follows the sniffed bytes
- [ ] Object keys are server-generated, opaque and collision-resistant, never derived from user input
- [ ] Bytes sniffed with `finfo`; `mime_type` and `mime_source: sniffed` written together
- [ ] Stored headers written on every upload, private included: content type from the sniffed bytes, and `CacheControl: public, max-age=31536000, immutable`
- [ ] `source: upload`, `uploaded_by` set to the authenticated id or null
- [ ] A name collision (NFC plus case-fold plus whitespace-collapse match, library-wide) is reported to the caller as informational only, never blocking and never overwriting
- [ ] Tested directly against `Storage::fake()`, including the name scrub table
