# Define Readable Name and Upload Identity

Status: resolved
Type: grilling
Blocked by: 02, 03

## Question

How should an upload preserve its original client filename, editable display name, extension, MIME type, size, and storage identity? Decide collision handling, sanitization, whether renaming moves objects, and the initial semantics that every duplicate upload creates a new Media Asset.

## Answer

`original_client_filename` is immutable and preserves the client-supplied filename for display and audit. The initial editable `display_name` is the original filename without its final extension, with surrounding whitespace trimmed; users may edit it later. Original filenames remain available as metadata, while names are sanitized only at output boundaries such as HTML and response headers. Editable display names normalize control characters and surrounding whitespace but allow Unicode and punctuation.

The persisted extension is normalized, MIME type is server-detected, and byte size is measured from the stored bytes. Client MIME information is not trusted for security decisions. Display names are not unique identifiers. When an upload would collide with an existing readable name, show an informational choice between `Create New Asset` and `Cancel`. `Create New Asset` always creates a new Media Asset and a distinct opaque object key; `Cancel` abandons the upload. Duplicate uploads therefore never overwrite an existing asset or storage object.

Editing `display_name` updates presentation metadata only and never moves or renames the stored object. Storage operations use the persisted `(disk, object_key)` pair. Object keys are server-generated, collision-resistant, opaque, independent of client-controlled names, and may retain only a normalized extension where needed for provider or content handling.

## Comments

- Resolved with the requester on 2026-08-26.
