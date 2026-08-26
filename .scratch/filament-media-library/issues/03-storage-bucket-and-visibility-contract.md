# Define Storage Bucket and Visibility Contract

Status: resolved
Type: research
Blocked by: 01, 02

## Question

How should the plugin use Laravel filesystem disks and Cloudflare R2 buckets for Media Assets? Decide global defaults, per-picker overrides for disk/bucket, default path, public/private visibility, existing-asset selection rules, and the invariant separating readable names from object keys.

## Answer

Use Laravel's configured filesystem disks as the only storage boundary. The package default resolves to the application's configured default disk, with an optional package-level override; new objects use the relative `media` path prefix and explicitly default to `private` visibility. A picker may override the disk and a relative path prefix, with picker settings taking precedence over package defaults. A bucket is not an independent picker setting: applications configure one Laravel disk per bucket, including its endpoint, credentials, and root/prefix, and the picker selects the disk name.

Persist the resolved disk name, opaque object key, and visibility on each Media Asset. Storage operations always use `(disk, object_key)`; editable readable names and original client filenames are presentation metadata and never determine, rename, or move the object. Duplicate uploads receive distinct object keys.

The picker selects authorized Media Asset records from the database, not arbitrary objects returned by a bucket listing or inferred from URLs. Reusing an existing asset creates an Attachment without changing its storage location or visibility. Public delivery requires explicit provider/disk exposure; private delivery must use the recorded disk and object key after authorization. The plugin does not call R2 ACL APIs or add provider-specific SDK behavior; R2 remains behind Laravel's filesystem contract.

Supporting research: [Research: Storage Bucket and Visibility Contract](../research-03-storage-bucket-and-visibility-contract.md).
