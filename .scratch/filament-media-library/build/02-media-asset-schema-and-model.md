# 02: Media Asset Schema and Model

**What to build:** the plugin owns a Media Asset record. A developer can create one in a test and read its naming, type, storage and provenance back. Nothing writes bytes yet.

**Blocked by:** 01

**Status:** ready-for-agent

- [ ] `media_assets` migration: `id`, `ulid`, `display_name` (not null), `original_client_filename`, `extension`, `alt`, `mime_type`, `mime_source`, `size`, `disk`, `object_key`, `visibility`, `source`, `import_source`, `uploaded_by`, `tenant_id`, `blurhash`, timestamps, soft deletes
- [ ] `mime_source` constrained to `header`, `sniffed`, `extension`, `unknown`; `source` constrained to `upload`, `import` and non-nullable
- [ ] Unique index on `(disk, object_key)`; index on `tenant_id`
- [ ] `MediaAsset` model with `SoftDeletes`, casts, and `attachments()` / `derivatives()` relations declared (targets land in 06 and 13)
- [ ] Table names are fixed (`media_assets`), with no prefix configuration knob
- [ ] Tests cover the unique constraint and the enum constraints
