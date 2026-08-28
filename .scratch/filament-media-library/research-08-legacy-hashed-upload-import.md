# Research: Legacy Hashed Upload Import

**Question:** How can existing Laravel Storage uploads with hashed names remain usable while new uploads receive readable names? Decide whether optional import registers existing objects, copies them, or both; how files are discovered and mapped to Media Assets; and how unknown ownership, disk, visibility, and duplicate objects are handled without overwriting existing files.

**Research date:** 2026-08-26. Sources below were accessed on that date. Laravel references use the official `13.x` branch and `laravel.com/docs/13.x`; Flysystem references use the `3.x` branches of `thephpleague/flysystem` and `thephpleague/flysystem-aws-s3-v3` (the exact packages Laravel's S3 driver requires); Cloudflare and AWS references are the vendors' own API documentation.

## Executive conclusion

**Import registers in place; it never copies by default, and it never writes to the source disk at all.** A legacy hashed object such as `avatars/9f2c1b7a4d.jpg` is already a valid `(disk, object_key)` pair, and ticket 03/04 already declare the object key to be opaque, server-generated, and independent of any readable name. A hashed key satisfies that definition perfectly. Import therefore creates a Media Asset row whose `object_key` is the existing legacy path verbatim, leaves the bytes untouched, and derives readable metadata as *presentation* fields. Nothing is renamed, moved, re-keyed, or overwritten, which is exactly the standing "preserve existing hashed uploads" preference. A `--copy` mode is worth offering as an explicit, non-default opt-in for consolidating a legacy prefix under `media/`, but it must be a *copy-then-register-the-new-key* operation that never deletes the source and refuses to write to an occupied destination key.

**The sanctioned default discovery mode is column-driven, not disk traversal.** Blind `allFiles()` traversal is supported as a secondary `--source=disk` mode, but it is explicitly the degraded path: it can find bytes but cannot know ownership, cannot know which host record referenced the object, and on S3-compatible disks it is both a Class A billed operation and fully buffered in PHP memory. Reading an existing host-model column (`users.avatar_path`, `posts.image`, …) is the sanctioned default because the row that holds the path is the same row that supplies the owner, the host model, the field context, and the attachment — the four things a raw key cannot tell you.

**The identity key is a unique index on `(disk, object_key)`.** That makes re-running the import naturally idempotent and makes concurrent runs safe via `firstOrCreate`/`createOrFirst`. Content hashing is deliberately *not* part of identity: two keys holding identical bytes are two assets, consistent with ticket 04's "duplicate uploads always create a new Media Asset". A content hash may be recorded as optional advisory metadata for a later report-only dedup tool, but it must not merge rows.

**Unknown fields are recorded as unknown, never guessed.** `original_client_filename` is unknowable for a hashed object; the honest value is the legacy basename itself (that *is* the only filename that ever existed on the server), never a fabricated "photo.jpg". Unknown `uploaded_by` stays `null`. Unknown visibility falls back to the disk's configured visibility, and on Cloudflare R2 `getVisibility()` cannot be trusted at all because R2 does not implement `GetObjectAcl`.

## Verified facts

### Discovery: what Laravel 13 actually does when you list a disk

- Laravel 13's `Storage::disk(...)->files($directory, $recursive)` calls Flysystem's `listContents()`, filters to files, then calls `sortByPath()` and `toArray()`. `allFiles($directory)` is defined as `files($directory, true)`. Both therefore return a plain PHP `array<string>`. **Source:** [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php), `files()` and `allFiles()`.
- Flysystem's `DirectoryListing` is lazy — `filter()` and `map()` each return a new `DirectoryListing` wrapping a `Generator` — but `sortByPath()` is *not*: its first statement is `$listing = $this->toArray();` followed by `usort(...)`. Since Laravel's `files()` calls `sortByPath()`, **every key in the traversed prefix is materialized in PHP memory before a single path is returned.** `allFiles()` on a large legacy prefix is therefore an unbounded-memory operation. **Sources:** [Flysystem `DirectoryListing`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/DirectoryListing.php) and [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php), `files()`.
- The lazy path is still reachable: `Illuminate\Filesystem\FilesystemAdapter::__call()` forwards unknown methods straight to the underlying Flysystem `FilesystemOperator`, so `Storage::disk($d)->listContents($path, true)` returns Flysystem's `DirectoryListing`, which is `IteratorAggregate` and can be `foreach`-ed without buffering. **Sources:** [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php), `__call()`; [Flysystem `Filesystem::listContents()`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/Filesystem.php); [Flysystem `DirectoryListing`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/DirectoryListing.php).
- On the S3 driver, `listContents($path, $deep)` builds `['Bucket' => …, 'Prefix' => …]`, adds `'Delimiter' => '/'` **only when `$deep === false`**, and iterates `$this->client->getPaginator('ListObjectsV2', $options)` inside a `Generator`, yielding `CommonPrefixes` then `Contents` per page. A recursive listing therefore issues no delimiter and walks the whole prefix. **Source:** [Flysystem `AwsS3V3Adapter::listContents()` and `retrievePaginatedListing()`](https://raw.githubusercontent.com/thephpleague/flysystem-aws-s3-v3/3.x/AwsS3V3Adapter.php).
- Each `ListObjectsV2` page returns at most 1,000 keys: "*Sets the maximum number of keys returned in the response. By default, the action returns up to 1,000 key names. The response might contain fewer keys but will never contain more.*" Pagination continues via `IsTruncated` / `NextContinuationToken`. **Source:** [AWS S3 `ListObjectsV2` API reference](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html), `max-keys`, `IsTruncated`, `NextContinuationToken`.
- R2 implements both `ListObjects` and `ListObjectsV2` and recommends V2: "*Even though `ListObjects` is a supported operation, it is recommended that you use `ListObjectsV2` instead when developing applications.*" R2's `ListObjectsV2` accepts `continuation-token`, `delimiter`, `encoding-type`, `fetch-owner`, `max-keys`, `prefix`, and `start-after`. **Source:** [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/).
- On R2 pricing, list operations are Class A and object reads are Class B: "*Class A Operations include `ListBuckets`, `PutBucket`, `ListObjects`, `PutObject`…*" and "*Class B Operations include `HeadBucket`, `HeadObject`, `GetObject`, `UsageSummary`…*". Class A is billed at a materially higher per-million rate than Class B. **Source:** [Cloudflare R2 pricing](https://developers.cloudflare.com/r2/pricing/). Practical consequence: traversing 1,000,000 legacy keys costs ~1,000 Class A list requests *plus* one Class B `HeadObject` per key if metadata is fetched individually.

### Mapping: what metadata Laravel can and cannot recover from a stored object

- `Storage::size($path)` delegates to Flysystem `fileSize()`. On S3 that is `fetchFileMetadata()`, i.e. a `HeadObject` call, mapped from `ContentLength` (falling back to `Size` when the value came from a listing). Byte size is therefore reliably recoverable on both local and S3/R2 disks. **Sources:** [Laravel 13 `FilesystemAdapter::size()`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php); [Flysystem `AwsS3V3Adapter::fileSize()` and `mapS3ObjectMetadata()`](https://raw.githubusercontent.com/thephpleague/flysystem-aws-s3-v3/3.x/AwsS3V3Adapter.php); [Laravel 13 filesystem docs](https://laravel.com/docs/13.x/filesystem), "File Metadata".
- **`Storage::mimeType()` on S3/R2 does not sniff content — it reports the stored `Content-Type` header.** Flysystem's `mapS3ObjectMetadata()` sets `$mimetype = $metadata['ContentType'] ?? null` from the `HeadObject` result, and `AwsS3V3Adapter::mimeType()` throws `UnableToRetrieveMetadata::mimeType($path)` when that value is `null`. Laravel's `FilesystemAdapter::mimeType()` catches `UnableToRetrieveMetadata` and returns `false` unless the disk has `throw => true`. So on a remote disk, an object uploaded years ago with a wrong or missing `Content-Type` yields a wrong MIME type or `false`, not a corrected one. **Sources:** [Flysystem `AwsS3V3Adapter`](https://raw.githubusercontent.com/thephpleague/flysystem-aws-s3-v3/3.x/AwsS3V3Adapter.php), `mimeType()`, `fetchFileMetadata()`, `mapS3ObjectMetadata()`; [Laravel 13 `FilesystemAdapter::mimeType()`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php).
- Laravel *does* ship a real content sniffer usable on downloaded bytes: `symfony/mime` is a hard `require` of `laravel/framework` 13.x (`"symfony/mime": "^7.4.0 || ^8.0.0"`), and Laravel itself instantiates `Symfony\Component\Mime\MimeTypes` in `Illuminate\Http\Testing\MimeType`. `MimeTypes::guessMimeType()` inspects the file's bytes (finfo), unlike the extension-based `MimeType::from()` helper. **Sources:** [Laravel 13 `composer.json`](https://raw.githubusercontent.com/laravel/framework/13.x/composer.json); [Laravel 13 `Illuminate\Http\Testing\MimeType`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Http/Testing/MimeType.php).
- **`Storage::getVisibility()` is unsafe on R2.** Laravel's `getVisibility()` contains **no** `try`/`catch` — it calls `$this->driver->visibility($path)` directly (contrast `setVisibility()`, `mimeType()` and `checksum()`, which all catch and report). Flysystem's S3 `visibility()` issues a `GetObjectAcl` command and wraps any failure in `UnableToRetrieveMetadata::visibility(...)`. Cloudflare documents `GetObjectAcl` and `PutObjectAcl` as "*Completely unimplemented*" on R2. The composed code path means a `getVisibility()` call against an R2 disk raises an uncaught `UnableToRetrieveMetadata` rather than returning a value. **Sources:** [Laravel 13 `FilesystemAdapter::getVisibility()`/`setVisibility()`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php); [Flysystem `AwsS3V3Adapter::visibility()`](https://raw.githubusercontent.com/thephpleague/flysystem-aws-s3-v3/3.x/AwsS3V3Adapter.php); [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/).
- Laravel 13 knows about this and works around it for copy/move only: `FilesystemManager::createFlysystem()` does `if (str_contains($config['endpoint'] ?? '', 'r2.cloudflarestorage.com')) { $config['retain_visibility'] = false; }`. Flysystem's `Filesystem::resolveConfigForMoveAndCopy()` reads `Config::OPTION_RETAIN_VISIBILITY` and, when false, skips propagating the source's visibility — which is what avoids the ACL read during `copy()`/`move()`. It does **not** make a direct `getVisibility()` call safe. **Sources:** [Laravel 13 `FilesystemManager::createFlysystem()`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php); [Flysystem `Filesystem::resolveConfigForMoveAndCopy()`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/Filesystem.php); [Flysystem `Config`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/Config.php).
- Laravel's visibility abstraction is exactly two values: "*Files may either be declared `public` or `private`.*" For the local driver, "*`public` visibility translates to `0755` permissions for directories and `0644` permissions for files*", configurable via the disk's `permissions` array. So on a local legacy disk, `getVisibility()` returns a real answer derived from file mode. **Source:** [Laravel 13 filesystem docs](https://laravel.com/docs/13.x/filesystem), "File Visibility" and "Local Files and Visibility".
- `Storage::checksum($path)` exists but is **not a content hash on S3/R2**: Flysystem's S3 adapter accepts only `checksum_algo => 'etag'` (throwing `ChecksumAlgoIsNotSupported` otherwise) and returns the object's `ETag` header, throwing `UnableToProvideChecksum` when `ETag` is absent. An S3 `ETag` is only an MD5 of the whole object for single-part uploads; for multipart uploads it is a composite value. Laravel's `checksum()` catches `UnableToProvideChecksum` and returns `false` unless the disk throws. **Sources:** [Flysystem `AwsS3V3Adapter::checksum()`](https://raw.githubusercontent.com/thephpleague/flysystem-aws-s3-v3/3.x/AwsS3V3Adapter.php); [Laravel 13 `FilesystemAdapter::checksum()`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php).
- `Storage::exists($path)` / `Storage::missing($path)` are the documented existence checks, and `Storage::copy($from, $to)` / `Storage::move($from, $to)` are the documented copy/rename operations. **Source:** [Laravel 13 filesystem docs](https://laravel.com/docs/13.x/filesystem), "Retrieving Files" and "Copying and Moving Files".
- Laravel's `local` driver root defaults to `storage/app/private`; the bundled `public` disk uses `storage/app/public` and declares `'visibility' => 'public'`. Legacy uploads created with `$request->file('x')->store('avatars')` therefore sit under whichever of those roots the app's default disk pointed at, and `store()` "*will generate a unique ID to serve as the filename*" — i.e. the hashed names this ticket is about. **Source:** [Laravel 13 filesystem docs](https://laravel.com/docs/13.x/filesystem), "The Local Driver", "The Public Disk", "File Uploads".

### Idempotency and long-running command mechanics

- Eloquent's `firstOrCreate()` first attempts a `where($attributes)->first()`, then delegates to `createOrFirst()`, which wraps the insert in a savepoint and, on `UniqueConstraintViolationException`, re-reads through the write PDO. This gives a race-safe upsert **only when a real unique index backs the attributes** — otherwise the violation never fires and duplicates are inserted. `updateOrCreate()` is `firstOrCreate()` plus a `fill()->save()` when the row already existed. **Source:** [Laravel 13 Eloquent `Builder`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Eloquent/Builder.php), `firstOrCreate()`, `createOrFirst()`, `updateOrCreate()`.
- The composite unique index is available in schema builder as `$table->unique($columns, $name = null, $algorithm = null)`. **Source:** [Laravel 13 `Blueprint::unique()`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Schema/Blueprint.php).
- For iterating large source tables, Laravel 13 provides `chunk()`, `chunkById()`, `each()`, `eachById()`, `lazy($chunkSize = 1000)`, and `lazyById($chunkSize = 1000, $column = null, $alias = null)` on the query builder. `chunkById`/`lazyById` are the keyset-paginated variants that stay correct while rows are being written during the walk. **Source:** [Laravel 13 `BuildsQueries`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Concerns/BuildsQueries.php).
- `Illuminate\Support\LazyCollection` is generator-backed and explicitly rejects a bare `Generator` in its constructor ("*Generators should not be passed directly to LazyCollection. Instead, pass a generator function.*"), so a disk-traversal source must be wrapped as `new LazyCollection(fn () => yield from …)`. **Source:** [Laravel 13 `LazyCollection`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Collections/LazyCollection.php).
- Artisan signature syntax supports value options and boolean switches: `'mail:send {user} {--queue}'` for a switch, `'mail:send {user} {--queue=}'` for a value option, `'mail:send {user} {--queue=default}'` for a default, `'mail:send {--id=*}'` for an array option, and `{user : The ID of the user}` for inline descriptions. **Source:** [Laravel 13 Artisan docs](https://laravel.com/docs/13.x/artisan), "Arguments", "Options", "Input Arrays", "Input Descriptions".
- Implementing `Illuminate\Contracts\Console\Isolatable` makes a `--isolated` option available automatically; Laravel then "*ensure[s] that no other instances of that command are already running*" by acquiring an atomic cache lock, and `isolatableId()` can fold arguments into the lock key. **Source:** [Laravel 13 Artisan docs](https://laravel.com/docs/13.x/artisan), "Isolatable Commands".
- `$this->withProgressBar($iterable, $callback)` is the documented long-task progress helper. **Source:** [Laravel 13 Artisan docs](https://laravel.com/docs/13.x/artisan), "Progress Bars".

### Coexistence facts already fixed by earlier tickets

- Ticket 03 fixed the invariant: "*For every Media Asset, storage operations use `(disk, object_key)`; UI labels and download filenames use readable metadata. No storage lookup derives an object key from a readable name.*" It also already anticipated this ticket: "*an imported asset can register its existing hashed object key without pretending that the hash is its readable name.*" **Source:** `.scratch/filament-media-library/research-03-storage-bucket-and-visibility-contract.md` (resolved).
- Ticket 04 fixed that object keys are "*server-generated, collision-resistant, opaque, independent of client-controlled names*", that `display_name` edits "*never move or rename the stored object*", and that duplicates "*never overwrite an existing asset or storage object*". **Source:** issue [#28](https://github.com/lisowiecw/filament-media-library/issues/28) (resolved).

## Recommended contract

### 1. Register in place is the default; copy is an explicit opt-in; never overwrite

Three modes, one default:

| Mode | Flag | Writes to storage? | `object_key` recorded |
| --- | --- | --- | --- |
| **Register** (default) | *(none)* | No | The legacy key, verbatim |
| **Copy** | `--copy` | Writes a *new* object only | A freshly generated `media/{ulid}.{ext}` key |
| **Copy and retire** | *not offered* | — | — |

- **Register** is the default because a hashed legacy key already satisfies the ticket-04 definition of an object key: opaque, collision-resistant, not derived from a client name. Re-keying it buys nothing and risks everything — the legacy column, cached CDN URLs, and any hard-coded reference in the host app all still point at the old key.
- **Copy** uses `Storage::disk($from)->readStream()` → `Storage::disk($to)->writeStream()` (or `Storage::copy()` when source and destination are the same disk) with an explicit `'visibility' => 'private'` per ticket 03. It **must not delete the source**, so the legacy column keeps working. There is no "move" mode: moving is the auto-migration the user put explicitly out of scope.
- **Never overwrite** is enforced structurally, not by convention: the destination key is a freshly generated ULID-based key, and the importer still asserts `Storage::disk($to)->missing($destinationKey)` before writing, aborting that item if the key is somehow occupied.
- The importer performs **zero writes to the source disk in register mode** — no `setVisibility()`, no metadata correction, no `Content-Type` repair. Correcting a stored `Content-Type` is a separate, explicitly-requested operation.

### 2. Discovery: column-driven is the sanctioned default

```php
// config/media-library.php — the app declares what its legacy columns are.
'import' => [
    'sources' => [
        [
            'model'      => \App\Models\User::class,
            'column'     => 'avatar_path',   // string column holding the legacy key
            'disk'       => 'public',        // required: the disk that column's paths live on
            'field'      => 'avatar',        // ticket-02 field context for the attachment
            'owner'      => 'id',            // host column to map onto uploaded_by, or null
            'attach'     => true,            // create the ticket-02 attachment as well
        ],
    ],
],
```

Why this is the default rather than traversal:

- It is the only mode in which **ownership is knowable**. The row that holds `avatar_path` also holds the user id, which is the honest `uploaded_by`, and the host model + id + field context, which is exactly the ticket-02 attachment tuple. Traversal produces bytes with no owner and no host.
- It is bounded and resumable: `Model::query()->whereNotNull($column)->lazyById(500)`.
- It costs one `HeadObject` (Class B) per referenced object, and **zero** `ListObjectsV2` calls (Class A) — cheaper than traversal on R2, per the pricing classes above.
- It naturally skips orphans, temp directories, thumbnails, and framework junk that traversal would sweep up.

`--source=disk` traversal is the secondary mode, for apps whose legacy paths were never recorded in a column:

- It must iterate `Storage::disk($d)->listContents($prefix, true)` (lazy, generator-backed) and **must not** use `allFiles()`, because `files()` calls `sortByPath()`, which buffers every key in memory first.
- It requires an explicit `--path=` prefix; a bare recursive listing of a whole bucket root is refused, because a recursive S3 listing sends no `Delimiter` and walks everything.
- Every row it produces gets `uploaded_by = null` and no attachment. Traversal-imported assets are unowned and unattached by construction, and the command says so in its summary.

### 3. Mapping a legacy object to a Media Asset

```php
final readonly class LegacyObjectMapping
{
    public function __construct(
        public string  $disk,                     // never guessed — see §5
        public string  $object_key,               // legacy key verbatim (register mode)
        public string  $original_client_filename, // basename($object_key)  — see note
        public string  $display_name,             // ticket-04 rule applied to the basename
        public ?string $extension,                // strtolower(pathinfo(..., PATHINFO_EXTENSION)) ?: null
        public ?string $mime_type,                // see resolution ladder below
        public int     $byte_size,                // Storage::disk($disk)->size($object_key)
        public string  $visibility,               // see §5
        public ?int    $uploaded_by,              // host row's owner column, else null
        public bool    $imported,                 // true — provenance marker
        public ?string $imported_source,          // 'column:App\Models\User.avatar_path' | 'disk:public'
    ) {}
}
```

- **`original_client_filename`.** The honest value is `basename($object_key)` — e.g. `9f2c1b7a4d8e.jpg`. This is not a fudge: for an object stored by `UploadedFile::store()`, the hashed basename is genuinely the only filename that ever existed server-side; the true client name was discarded at upload time and is not recoverable from storage. Fabricating a plausible name would violate ticket 04's "immutable, preserves the client-supplied filename" contract by storing something that was never client-supplied. The `imported` flag lets the UI render this field as "unknown (imported)" rather than as a real upload name.
- **`display_name`.** Apply ticket 04's rule mechanically — basename minus final extension, trimmed — giving `9f2c1b7a4d8e`. It is ugly, and that is correct: the display name is editable, so the user fixes the ones they care about, and the plugin has not invented information. Ticket 04 already establishes display names are not unique identifiers, so a thousand hash-shaped display names collide harmlessly.
- **MIME type resolution ladder** (stop at the first that succeeds):
  1. `Storage::disk($disk)->mimeType($key)`. On local disks this sniffs; on S3/R2 it returns the *stored* `Content-Type` from `HeadObject`, or `false` when the disk was written without one.
  2. Only with `--sniff` (opt-in, downloads bytes): stream the object to a temp file and call `Symfony\Component\Mime\MimeTypes::getDefault()->guessMimeType($tmp)`. This is the only way to get a true content-derived MIME for a remote object, and it costs a full `GetObject` per file — hence opt-in.
  3. Extension lookup via `MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null`.
  4. `null`, recorded as unknown. Never `application/octet-stream` as a fake answer.
  Record which rung answered (`mime_source` = `stored|sniffed|extension|unknown`), because a stored `Content-Type` is a claim, not a measurement, and ticket 04 forbids trusting untrusted MIME for security decisions.
- **Byte size.** `Storage::size()` — reliable on both local and S3/R2 (`ContentLength`). If it throws or returns a non-integer, skip the item and report it; do not store `0`.
- **`uploaded_by`.** From the host row's configured owner column in column mode; `null` in traversal mode. Ticket 07 requires `uploaded_by` as provenance, and `null` is truthful provenance for an object whose uploader was never recorded.

### 4. Idempotency and duplicate objects

**Identity key:** a database unique index on the pair.

```php
Schema::table('media_assets', function (Blueprint $table) {
    $table->unique(['disk', 'object_key'], 'media_assets_disk_object_key_unique');
});
```

- The importer resolves each candidate with `MediaAsset::query()->firstOrCreate(['disk' => $m->disk, 'object_key' => $m->object_key], $rest)`. Backed by the unique index, `createOrFirst()`'s savepoint + `UniqueConstraintViolationException` retry makes this safe under concurrent runs. Without the index, that retry path never fires and duplicates land silently — the index is load-bearing, not cosmetic.
- **Re-running the import is a no-op** for already-registered objects. Deliberately use `firstOrCreate`, **not** `updateOrCreate`: a second run must not clobber a `display_name` the user has since edited.
- **When a row already exists with different metadata**, the default is *report, do not touch*. The command prints a drift line (`existing byte_size=104321, storage reports 104999`) and exits that item. A separate `--refresh-metadata` flag may refresh only the machine-derived fields — `byte_size`, `mime_type`, `visibility` — and must never touch `display_name`, `original_client_filename`, `object_key`, `disk`, or `uploaded_by`.
- **Same bytes at two keys → two Media Assets.** This is required for consistency with ticket 04 ("duplicate uploads therefore never overwrite an existing asset") and with ticket 05 (deletion is blocked when an asset is shared; silently merging two legacy objects into one row would make one host's delete affect another host's file). Content hashing is therefore **not** part of identity.
- **Optional advisory `content_hash`.** Worth recording only behind `--hash`, and only as report fodder for a future report-only dedup tool (mirroring ticket 05's report-only orphan cleanup). Note the constraint: `Storage::checksum()` on S3/R2 returns the **ETag**, which equals the content MD5 only for single-part uploads and is a composite for multipart ones — so ETag is *not* a usable cross-key content hash. A real hash requires downloading the bytes (`hash_init('xxh128')` over `readStream()`), which is why it stays opt-in.

### 5. Unknown ownership, disk, and visibility

- **Unknown disk → hard failure, never a guess.** `--disk` (or the per-source `disk` key) is **required**. There is no fallback to `filesystems.default`, because a legacy path like `avatars/abc.jpg` is meaningful on several disks at once and guessing wrong produces a Media Asset whose `(disk, object_key)` pair silently resolves to the wrong bytes or to nothing. If the named disk is not configured, or `Storage::disk($d)->exists($key)` is false, the item is skipped and reported as `missing-object`, and **no row is created**. A Media Asset pointing at a non-existent object is worse than no asset.
- **Unknown `uploaded_by` → `null`.** Not the running console user, not a synthetic "system" user. Ticket 07 uses `uploaded_by` as provenance; attributing a legacy file to whoever ran the command manufactures false provenance. The `MediaAssetPolicy` must therefore already handle `uploaded_by === null` (fail-closed for owner-scoped checks, deferring to the role/gate checks ticket 07 defines).
- **Unknown visibility → fall back to the disk's configured visibility, and never call `getVisibility()` blindly.** The resolution order is:
  1. If `--visibility=` is supplied, use it (it is an operator assertion about the legacy prefix, which is usually correct — legacy apps typically had one public avatars prefix and one private documents prefix).
  2. Else if the disk's driver supports reading it — in practice the `local` driver, where visibility is derived from file mode — use `Storage::disk($d)->getVisibility($key)`, wrapped in `try`/`catch (UnableToRetrieveMetadata)` because **Laravel's `getVisibility()` does not catch it for you**.
  3. Else use `config("filesystems.disks.{$disk}.visibility")`.
  4. Else `private`, matching ticket 03's default-private posture.
  On an R2 disk, step 2 is skipped entirely: R2 does not implement `GetObjectAcl`, so the call raises `UnableToRetrieveMetadata`. Detect this by disk driver + endpoint (`str_contains($endpoint, 'r2.cloudflarestorage.com')`, the same test Laravel 13 itself uses in `createFlysystem()`), or simply by never attempting step 2 on the `s3` driver at all — the stored `visibility` field is delivery intent per ticket 03, not a readback of provider state.
  Crucially, the importer **never calls `setVisibility()`** to reconcile a mismatch. That is a write to a legacy object, which is out of scope.
- **Public legacy objects.** Recording `visibility = 'public'` for an already-public legacy object is correct and does not conflict with ticket 07: ticket 07 routes *private* content through the plugin Delivery route, and public reads are its documented fail-open case.

### 6. The mechanism: a service plus a thin command

Both, with the service as the real API and the command as a wrapper — the plugin is a library, so an app may need to import from inside its own migration or job.

```php
interface LegacyImporter
{
    /** @return LegacyImportReport */
    public function import(LegacyImportRequest $request): LegacyImportReport;
}
```

```php
protected $signature = 'media-library:import-legacy
    {--source=column : Discovery mode: "column" (default, ownership-aware) or "disk" (traversal)}
    {--model= : Host model class, required for --source=column}
    {--column= : Legacy path column on the host model}
    {--field= : Ticket-02 field context to attach under}
    {--disk= : REQUIRED. Laravel disk the legacy paths resolve against}
    {--path= : Key prefix, required for --source=disk}
    {--visibility= : Assert visibility for every imported object (public|private)}
    {--copy : Copy bytes to a new media/ key instead of registering in place}
    {--sniff : Download bytes to detect MIME (costs one GET per object)}
    {--hash : Record an advisory content hash (costs one GET per object)}
    {--refresh-metadata : Refresh byte_size/mime_type/visibility on rows that already exist}
    {--chunk=500 : Rows or keys per batch}
    {--dry-run : Report what would happen; write nothing}';
```

- Implements `Illuminate\Contracts\Console\Isolatable` with `isolatableId()` folding in `--disk` and `--model`/`--path`, so two concurrent imports of the same source cannot interleave. (The unique index is still the real correctness guarantee; the lock is an ergonomics guard.)
- `--dry-run` is the **default posture in documentation**: the command should be run dry first and prints an identical report either way, differing only in whether rows were written.
- Column mode iterates `$model::query()->whereNotNull($column)->lazyById((int) $this->option('chunk'))`; disk mode wraps `listContents()` in `new LazyCollection(fn () => yield from …)` and chunks with `->chunk($n)`. Neither mode materializes the full set.
- Per-item failures are collected, never fatal: the report tallies `registered`, `already-present`, `copied`, `skipped-missing-object`, `skipped-metadata-error`, `drift-detected`, and lists the first N of each. Exit code is non-zero only if the run could not start (bad disk, bad model).
- Attachments (ticket 02) are created in the same transaction as the asset row in column mode, honoring ticket 02's "duplicate attachment within one host+field is prevented" rule, so a re-run does not stack attachments.

### 7. Coexistence: why nothing needs renaming

Legacy hashed assets and new readable-name assets are **the same kind of record**, differing only in the *shape* of an opaque string:

| | Legacy imported asset | New upload |
| --- | --- | --- |
| `object_key` | `avatars/9f2c1b7a4d8e.jpg` (legacy hash) | `media/01J…ULID.jpg` (generated) |
| `original_client_filename` | `9f2c1b7a4d8e.jpg` (unknowable; basename) | `Q3 Report Cover.jpg` |
| `display_name` | `9f2c1b7a4d8e`, user-editable | `Q3 Report Cover`, user-editable |
| Storage call | `Storage::disk($a->disk)->…($a->object_key)` | identical |

Ticket 03's invariant — storage operations use `(disk, object_key)`; UI uses readable metadata; no lookup derives a key from a name — is precisely what makes renaming unnecessary. The picker, grid, search, delivery route, and deletion path all consume the same four fields regardless of how the key was shaped, so no code branches on "is this legacy". A hashed key is not a defect to be migrated away; it is a well-formed opaque key that happens to predate the plugin.

**Where the legacy path shows through to a user** — three places, all cosmetic and all fixable by the user:

1. **The initial `display_name` is hash-shaped** until someone edits it. This is the one real ergonomic cost, and it is the honest one.
2. **The download filename.** Ticket 04 sends readable metadata as the download name, so an unedited legacy asset downloads as `9f2c1b7a4d8e.jpg`. Acceptable, and improved by editing the display name.
3. **The public URL of a public legacy object** still contains the legacy prefix (`/storage/avatars/9f2c…jpg` rather than `/storage/media/…`). This is a feature: existing links, caches, and CDN entries keep working.

Everything else — thumbnails, search, filters, attachment, authorization, delivery, soft-delete — is key-shape-agnostic by construction.

## Open questions and risks

- **Exact R2 error for `GetObjectAcl`.** Cloudflare documents the operation as unimplemented but does not publish the HTTP status/error code it returns. I verified from source that Laravel's `getVisibility()` does not catch `UnableToRetrieveMetadata` and that Flysystem's S3 `visibility()` wraps *any* `Throwable` into it; I did **not** execute a live R2 call to confirm the resulting exception class and message. The recommendation (never call `getVisibility()` on an S3-driver disk) is safe either way, but the precise failure mode should be confirmed against a real R2 bucket before writing tests that assert on it.
- **Whether `imported`/`imported_source`/`mime_source` belong on the Media Asset table.** Ticket 02 enumerated the canonical field list and did not include them. They are recommended here as provenance, but adding columns to the ticket-02 record is a change to a resolved decision and should be confirmed rather than assumed.
- **Attachment ordering for column-driven imports.** Ticket 02 requires explicit ordering on attachments. For a single-value legacy column, order `0` is obvious; for a legacy JSON array column holding multiple paths, the array index is the natural order — but multi-value legacy columns were not specified in this ticket and their discovery shape (`--column-is-json`?) is unresolved.
- **Legacy objects on a disk the app no longer configures.** If a legacy prefix lives on a bucket that has since been decommissioned or moved, `--disk` cannot name it. Laravel's `Storage::build()` on-demand disks could bridge this, but ticket 03 explicitly puts credentials and disk identity in application configuration, so the recommendation is "configure a read-only disk for the legacy bucket" — untested against a real decommissioned-bucket scenario.
- **Cost of `--sniff` on very large legacy sets.** One `GetObject` per object is Class B on R2 (cheap per request) but incurs full egress-equivalent read volume. I found no Cloudflare documentation of a bulk metadata operation that would avoid this; there does not appear to be one.
- **Filament-side surface.** This research covers the importer contract only. Whether the import is exposed as a Filament page/action inside the ticket-10 management page, or stays CLI-only, is not settled here.
- **`retain_visibility` on non-R2 S3-compatible providers.** Laravel 13 special-cases only the `r2.cloudflarestorage.com` endpoint string. Other providers that omit ACL support (some MinIO/RustFS configurations) will hit the same `getVisibility()` failure without the framework workaround. The recommendation to skip step 2 for all `s3`-driver disks covers this, but the set of affected providers is unverified.

## Sources

- [Laravel 13 filesystem documentation](https://laravel.com/docs/13.x/filesystem)
- [Laravel 13 Artisan Console documentation](https://laravel.com/docs/13.x/artisan)
- [Laravel 13 `FilesystemAdapter`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemAdapter.php)
- [Laravel 13 `FilesystemManager`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Filesystem/FilesystemManager.php)
- [Laravel 13 Eloquent `Builder`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Eloquent/Builder.php)
- [Laravel 13 `BuildsQueries`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Concerns/BuildsQueries.php)
- [Laravel 13 `Blueprint`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Database/Schema/Blueprint.php)
- [Laravel 13 `LazyCollection`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Collections/LazyCollection.php)
- [Laravel 13 `Illuminate\Http\Testing\MimeType`](https://raw.githubusercontent.com/laravel/framework/13.x/src/Illuminate/Http/Testing/MimeType.php)
- [Laravel 13 `composer.json`](https://raw.githubusercontent.com/laravel/framework/13.x/composer.json)
- [Flysystem 3.x `DirectoryListing`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/DirectoryListing.php)
- [Flysystem 3.x `Filesystem`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/Filesystem.php)
- [Flysystem 3.x `Config`](https://raw.githubusercontent.com/thephpleague/flysystem/3.x/src/Config.php)
- [Flysystem AWS S3 v3 adapter 3.x `AwsS3V3Adapter`](https://raw.githubusercontent.com/thephpleague/flysystem-aws-s3-v3/3.x/AwsS3V3Adapter.php)
- [AWS S3 `ListObjectsV2` API reference](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html)
- [Cloudflare R2 S3 API compatibility](https://developers.cloudflare.com/r2/api/s3/api/)
- [Cloudflare R2 pricing](https://developers.cloudflare.com/r2/pricing/)
- Resolved decisions: issue [#26](https://github.com/lisowiecw/filament-media-library/issues/26), issue [#28](https://github.com/lisowiecw/filament-media-library/issues/28), `.scratch/filament-media-library/research-03-storage-bucket-and-visibility-contract.md`
