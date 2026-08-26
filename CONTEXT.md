# Domain Context

## Media Library

- **Media Asset**: A reusable file record with human-readable file metadata and storage metadata. Its readable name is distinct from the storage object identity.
- **Attachment**: A relationship between a Media Asset and a host model. An attachment belongs to a named field context when a host model uses more than one media field.
- **Host model**: An application-owned model that can attach reusable Media Assets without owning their metadata or storage identity.
- **Field context**: The named media field within a host model that scopes ordered selection and duplicate prevention.
