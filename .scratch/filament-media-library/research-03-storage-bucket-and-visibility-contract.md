# Research: Storage Bucket and Visibility Contract

**Question:** How should the plugin use Laravel filesystem disks and Cloudflare R2 buckets for Media Assets? Decide global defaults, per-picker overrides for disk/bucket, default path, public/private visibility, existing-asset selection rules, and the invariant separating readable names from object keys.

**Research date:** 2026-08-26. Sources below were accessed on that date. Laravel 13 source references use the official `13.x` branch; Cloudflare references are official R2 documentation.

## Executive conclusion

The plugin should use Laravel's configured filesystem disks as its only storage boundary. The global default disk should be the application's `filesystems.default` value, which Laravel 13 initializes from `FILESYSTEM_DISK` and otherwise `local`. The plugin should use a package-level relative path of `media` for new objects and should default every new Media Asset to `private` visibility, regardless of whether the selected disk is local or S3-compatible.

A picker may override the disk name and path prefix. It should not accept an arbitrary bucket name as an independent storage setting: in Laravel, an S3-compatible bucket is part of the disk configuration together with its endpoint and credentials. A different bucket therefore means a different configured disk. If a product needs multiple R2 buckets, the application should configure one Laravel disk per bucket and the picker should select among those disk names.

The library should select existing Media Assets from the plugin's database records, subject to the caller's authorization and normal library filters. It should not treat every object returned by a bucket listing as selectable media, and it should not infer a Media Asset from a public URL. Each record must preserve the exact disk name, object key, and visibility used for storage. The invariant is: **a readable name is presentation metadata; an object key is an opaque, unique storage identity.** Renaming a readable name must not rename or move the object.

## Verified facts

### Laravel global disk, path, and visibility behavior

- Laravel reads the default filesystem disk from `filesystems.default`; the official Laravel 13 framework configuration sets that value to `env('FILESYSTEM_DISK', 'local')`. Calls to `Storage` without `disk(...)` use that default disk. **Sources:** [Laravel 13 filesystem configuration](https://raw.githubusercontent.com/laravel/framework/13.x/config/filesystems.php) and [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem), sections “Obtaining Disk Instances” and “Configuration”.
- Laravel's default `local` disk uses `storage/app/private` as its root. The included `public` disk is separate, uses `storage/app/public`, and declares `visibility => public`; it is not the default unless the application explicitly selects it. **Source:** [Laravel 13 filesystem configuration](https://raw.githubusercontent.com/laravel/framework/13.x/config/filesystems.php).
- File paths passed to Laravel's filesystem API are relative to the disk root. Laravel supports a configured `prefix` for scoped disks, which automatically prefixes every operation. **Sources:** [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem), sections “The Local Driver” and “Scoped, Read-Only, and Read-Through Filesystems”, and [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php), constructor and `path()` implementation.
- Laravel's S3 driver accepts `bucket`, `endpoint`, credentials, and an optional `root` prefix from the disk configuration. The framework constructs the S3 adapter with the configured bucket and root; it does not expose bucket selection as an argument to ordinary `Storage::disk(name)` operations. **Source:** [Laravel 13 `FilesystemManager`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php), `createS3Driver()` and `getConfig()`.
- Laravel's S3 driver defaults its Flysystem visibility converter to `public` when the disk has no explicit `visibility` setting. Local disks default to private permissions unless configured otherwise. **Source:** [Laravel 13 `FilesystemManager`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php), `createS3Driver()` and `createLocalDriver()`.
- Laravel treats visibility as a two-value abstraction, `public` or `private`, and allows visibility to be specified when writing, read with `getVisibility`, or changed with `setVisibility`. For uploaded files, `putFile`/`putFileAs` accept visibility options. **Sources:** [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem), section “File Visibility”, and [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php), `put()`, `getVisibility()`, and `setVisibility()`.
- Laravel's `url()` operates on the storage path, while `temporaryUrl()` creates a time-limited URL when the adapter supports it. `files()` and `allFiles()` return storage paths, and `exists()` checks a path on the selected disk. **Sources:** [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem), sections “File URLs”, “Temporary URLs”, “File Metadata”, and “Directories”, and [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php).
- Laravel 13 recognizes R2 endpoints in `createFlysystem()` and sets `retain_visibility` to `false` for them. This is a framework/provider compatibility detail, not a reason for the plugin to use R2-specific APIs. **Source:** [Laravel 13 `FilesystemManager`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php), `createFlysystem()`.
- Laravel supports on-demand disks through `Storage::build()`, but this does not change the recommendation that application configuration owns credentials, endpoint, bucket, and disk identity. **Sources:** [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem), section “On-Demand Disks”, and [Laravel 13 `FilesystemManager`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php), `build()`.

### Cloudflare R2 S3 compatibility and public/private behavior

- R2 exposes an S3-compatible API at an account endpoint and uses `auto` as the S3 region; `us-east-1` and an empty region are compatibility aliases for `auto`. **Source:** [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/), section “Bucket region”.
- Cloudflare's R2 authentication documentation instructs S3 clients to use the account-specific R2 endpoint and credentials, and says jurisdictional buckets require their jurisdiction-specific endpoint. This reinforces that endpoint, credentials, and bucket belong together in a disk configuration. **Source:** [Cloudflare R2 authentication](https://developers.cloudflare.com/r2/api/s3/tokens/).
- R2 supports the S3 operations needed by a library workflow, including `HeadObject`, `GetObject`, `PutObject`, `DeleteObject`, and `ListObjectsV2`; Cloudflare recommends `ListObjectsV2` over the older listing operation. **Source:** [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/), object-level operations and the listing caution.
- R2 does not implement S3 ACL operations such as `GetObjectAcl`, `PutObjectAcl`, or bucket ACL operations. **Source:** [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/), implemented and unimplemented operation tables.
- R2 buckets are never publicly accessible by default. Public exposure must be explicitly enabled through a Cloudflare-managed `r2.dev` URL or a connected custom domain. The `r2.dev` endpoint is intended for non-production traffic and is rate-limited. **Source:** [Cloudflare public buckets](https://developers.cloudflare.com/r2/buckets/public-buckets/).
- R2 presigned URLs grant temporary access to a specific object and operation. They require the account ID, bucket name, object path, and R2 S3 credentials; R2 supports `GET`, `HEAD`, `PUT`, and `DELETE` presigned operations, and presigned URLs cannot be used with custom domains. **Source:** [Cloudflare R2 presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/).

## Recommended contract

### Global defaults

Use package configuration with these defaults:

```php
return [
    'disk' => env('MEDIA_LIBRARY_DISK', config('filesystems.default')),
    'path' => 'media',
    'visibility' => 'private',
];
```

The exact configuration file and environment variable names remain implementation details, but the behavior should be stable:

- **Disk:** resolve the package default to Laravel's configured default disk. `MEDIA_LIBRARY_DISK` may explicitly choose a configured disk without changing the application's global filesystem default.
- **Path:** use `media` as a relative prefix for new Media Asset object keys. A picker may add a configured subprefix below that path, but callers must not provide absolute filesystem paths or provider URLs.
- **Visibility:** default new objects to `private` and pass that visibility explicitly on every write. This avoids inheriting Laravel's S3-driver default of public visibility and makes a public object an intentional decision.
- **Bucket:** do not add a global bucket setting separate from the disk. The selected Laravel disk is the storage location; its S3-compatible configuration identifies the endpoint, bucket, credentials, and optional root/prefix.

The plugin should validate that the selected disk exists and can perform the required operation. A misconfigured disk should fail the upload or delivery operation clearly rather than silently falling back to another disk.

### Per-picker disk and bucket overrides

A picker may override `disk` and an optional relative `path` prefix. The resolution order should be:

1. Picker setting, when present.
2. Package global configuration.
3. Laravel's `filesystems.default` for the package disk default.

A picker-level `bucket` option should not be an independent raw bucket override. It creates an ambiguous and unsafe combination when the bucket does not match the disk's endpoint, credentials, jurisdiction, or URL configuration. To target another bucket, configure another Laravel disk, for example `r2_media` and `r2_private`, and set the picker `disk` to that disk. Persist the resolved disk name on each Media Asset so later reads, previews, deletes, and private delivery use the original location.

A picker may set a visibility policy only if the product explicitly needs it, but `private` should remain the fallback. The picker must not turn a private existing asset public merely because it is being selected or previewed.

### Public and private behavior

`public` and `private` are storage and delivery intent, not guarantees that Laravel can manufacture independently of provider configuration.

- **Private asset:** use the recorded disk and object key for server-side reads or a short-lived `temporaryUrl()`/equivalent delivery mechanism. Authorization must happen before issuing a URL or streaming the object. A public-looking disk URL must never be used as proof that a private asset is readable.
- **Public asset:** use the recorded disk's configured public URL behavior, but require the application/operator to expose the bucket or disk through an appropriate public endpoint. For R2 this means explicitly enabling a public bucket access method or using an application-controlled delivery route; `visibility => public` alone cannot enable an R2 bucket that is private by default.
- **R2 ACLs:** do not call ACL APIs or promise per-object ACL behavior. R2's documented S3 compatibility excludes those operations. Treat the database visibility field as the plugin's delivery intent and let Laravel/provider configuration enforce the actual access path.
- **URL generation:** pass the object key, never the readable name, to Laravel `url()` or `temporaryUrl()`. For R2 private access, Laravel's S3-compatible adapter and the configured R2 endpoint should be used; provider-specific signing code remains outside the plugin boundary.

### Existing-asset selection rules

The Media Library is a database-backed catalog, not an arbitrary bucket browser:

- Show and select only Media Asset records that the current user is authorized to view and attach.
- Filter records using stored metadata and the recorded disk/object key. Search should target readable metadata such as display name, original client filename, extension, and MIME type, not a generated URL.
- A record is selectable only if its stored disk and object key identify the asset and the caller is authorized. The implementation may perform an existence or metadata check for previews and integrity feedback, but a transient storage failure should not silently create a new record or substitute a different object.
- Selection reuses the existing Media Asset and creates an Attachment; it does not copy, rename, or change the object's visibility. Reuse remains permitted across hosts and field contexts, while duplicate attachment within one host and field context remains prohibited per the resolved relationship contract.
- Listing raw disk objects is reserved for a separately specified import workflow. It is not part of ordinary picker selection, because an object alone has no trusted readable metadata, ownership, authorization context, or canonical Media Asset record.

### Readable-name and object-key invariant

Every Media Asset must keep these concepts distinct:

- `display_name`: editable, human-readable presentation metadata;
- `original_name`: the client-supplied filename retained as metadata, subject to the upload security rules;
- `object_key`: the exact path passed to Laravel filesystem operations;
- `disk`: the Laravel disk name that supplies the root/prefix, endpoint, and bucket.

The object key must be unique within its disk and must be generated independently of the readable name. It may use an opaque identifier plus a normalized extension, for example `media/{unique-id}.jpg`; the exact identifier and collision algorithm belong to the readable-name/upload-identity decision. Duplicate uploads must receive different object keys even when their client filenames are identical. Editing `display_name` or `original_name` must never move, overwrite, or recreate the object. The invariant can be stated as:

> For every Media Asset, storage operations use `(disk, object_key)`; UI labels and download filenames use readable metadata. No storage lookup derives an object key from a readable name.

This also preserves compatibility with legacy hashed uploads: an imported asset can register its existing hashed object key without pretending that the hash is its readable name.

## Implementation implications

- Store the disk name, object key, visibility, and readable metadata on the Media Asset record established by issue 02.
- Resolve disk configuration through Laravel's `Storage` facade/contracts. Do not add Cloudflare SDK dependencies or direct R2 API calls for normal upload, listing, read, URL, or delete operations.
- Pass `visibility => private` explicitly on new writes, and persist the resolved visibility alongside the asset.
- Use `Storage::disk($asset->disk)->exists($asset->object_key)` and related metadata methods for the recorded pair. Do not call the default disk for an asset whose record names another disk.
- Treat a bucket as a property of a configured disk. Document that multi-bucket applications need multiple disks and that changing a picker disk affects new uploads, not the location of already-created assets.
- Keep private URL lifetime, authorization rules, and the exact route-versus-direct-URL delivery choice for the authorization/private-delivery ticket.

## Unresolved decisions

- The exact readable-name sanitization, Unicode normalization, extension policy, opaque identifier format, and collision algorithm belong to the readable-name/upload-identity ticket.
- The exact private URL lifetime, response headers, inline/download behavior, authorization checks, and fallback when a disk lacks temporary URLs belong to the authorization/private-delivery ticket.
- The import workflow must decide how a legacy object is authorized, mapped to a Media Asset, and handled when its disk or visibility is unknown; ordinary picker selection should not absorb that behavior.
- Whether tenant-aware or user-specific storage requires a disk resolver rather than a static disk name remains a later multi-disk design decision.

## Sources

- [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem)
- [Laravel 13 filesystem configuration](https://raw.githubusercontent.com/laravel/framework/13.x/config/filesystems.php)
- [Laravel 13 `FilesystemManager`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php)
- [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php)
- [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/)
- [Cloudflare R2 authentication](https://developers.cloudflare.com/r2/api/s3/tokens/)
- [Cloudflare public buckets](https://developers.cloudflare.com/r2/buckets/public-buckets/)
- [Cloudflare R2 presigned URLs](https://developers.cloudflare.com/r2/api/s3/presigned-urls/)
