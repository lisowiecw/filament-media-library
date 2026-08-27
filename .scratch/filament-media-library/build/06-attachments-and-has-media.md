# 06: Attachments and the HasMedia Trait

**What to build:** an asset can be attached to a host model in a named field context, in an explicit order, and the host can read its own media back without knowing the pivot table exists.

**Blocked by:** 02

**Status:** ready-for-agent

- [ ] `media_attachments` migration: asset id, nullable `host_type` / `host_id` / `field_name`, `order`, timestamps, plus the identifier and label columns an External reference will use
- [ ] Unique constraint preventing the same asset appearing twice in one host and field context
- [ ] An attachment reconciliation module taking a host, a field name and an ordered array of asset ids: attach the new, detach the missing, rewrite `order` only where it differs, no-op on equality
- [ ] Detach removes the attachment row and never touches the asset or its object
- [ ] `HasMedia` trait with `media(string $field)` returning an ordered collection and `firstMedia(string $field)`
- [ ] The trait excludes soft-deleted assets, and applies no tenant scope and no policy check
- [ ] Rows with a null host are excluded from every field-context query
- [ ] Tests cover the diff preserving attachment identity and `created_at` rather than delete-and-reinsert
