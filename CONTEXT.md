# Domain Context

## Media Library

- **Media Asset**: A reusable file record with human-readable file metadata and storage metadata. Its readable name is distinct from the storage object identity.
- **Attachment**: A relationship between a Media Asset and a host model. An attachment belongs to a named field context when a host model uses more than one media field.
- **Host model**: An application-owned model that can attach reusable Media Assets without owning their metadata or storage identity.
- **Field context**: The named media field within a host model that scopes ordered selection and duplicate prevention.
- **Detach**: Removing an Attachment for a given host model and field context. Never affects the Media Asset record or its storage object.
  _Avoid_: Remove, unlink, delete (detach and delete are distinct actions)
- **Replace**: Attaching a newly uploaded Media Asset into a field context in place of the one currently attached there, then detaching the previous one. The previous asset becomes an orphan asset if nothing else references it; replacing never deletes it.
- **Delete**: The explicit, library-management action that soft-deletes a Media Asset record and queues its storage object for removal. Blocked by default when the asset has other Attachments, unless performed as a force delete.
  _Avoid_: Destroy, remove
- **Force delete**: A delete performed on a Media Asset that still has other Attachments, overriding the default block after the requester reviews the usage list.
- **Usage list**: The resolved, human-readable list of Attachments referencing a Media Asset, shown when a delete is blocked. Each entry names the host model instance and field context, using the host model's label callback when configured.
- **Media Picker**: The single field component that renders a host model's attachments for one field context and opens the library modal. The only picker surface; it never exposes delete or a visibility choice.
- **Placement**: The disk, directory and visibility a Media Picker applies to **new uploads**. Placement is fixed on the asset at upload and never re-applied by attaching, so a shared asset keeps its own placement wherever it is reused.
  _Avoid_: Destination, location (these read as the object key, which placement is not)
- **Offer**: To show an asset in a picker's library grid as selectable. A picker offers an asset when its mime matches the field's accepted file types and the asset is public, or the field's placement is private. Disk and directory never affect what is offered.
- **Orphan asset**: A Media Asset with zero Attachments. Surfaced for review by a report-only sweep after a configurable grace period; never deleted automatically.
