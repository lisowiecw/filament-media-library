# Define Media Asset and Relationship Model

Status: resolved
Type: grilling
Blocked by: 01

## Question

What is the canonical Media Asset record and reusable relationship contract? Decide the metadata fields, polymorphic attachment shape, single versus multiple selection representation, ordering, repeat-selection rules, and the boundary between host application models and plugin-owned records.

## Answer

The plugin owns the canonical `Media Asset` record and all asset metadata: original client filename, editable display name, extension, MIME type, byte size, disk, object key, visibility, and timestamps. Uploader identity is deferred to the authorization decision.

Attachments use a polymorphic relationship between the plugin-owned Media Asset and any host model. The attachment stores the asset, polymorphic host identity, stable field context, and explicit ordering. Single-selection fields persist one attachment; multiple-selection fields persist an ordered collection through the same relationship model.

An asset may be reused across host records and distinct field contexts, but duplicate attachment of the same asset within one host model and field context is prevented. Host applications opt into the relationship and field configuration; they do not own asset metadata, attachment persistence, or storage identity.

## Comments

- Resolved with the requester on 2026-08-26.
