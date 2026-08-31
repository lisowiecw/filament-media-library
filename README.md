<div align="center">
    <h1>Filament Media Library</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/lisowiecw/filament-media-library"><img src="https://img.shields.io/packagist/v/lisowiecw/filament-media-library.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/lisowiecw/filament-media-library"><img src="https://img.shields.io/packagist/php-v/lisowiecw/filament-media-library.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/lisowiecw/filament-media-library"><img src="https://badge.laravel.cloud/badge/lisowiecw/filament-media-library?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/lisowiecw/filament-media-library/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/lisowiecw/filament-media-library/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/lisowiecw/filament-media-library"><img src="https://img.shields.io/packagist/dt/lisowiecw/filament-media-library.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A reusable media library and configurable file picker for Filament.

## Compatibility

| Package | PHP | Laravel | Filament |
| ------- | --- | ------- | -------- |
| 0.x | 8.3, 8.4, 8.5 | 13.x | 5.x (guaranteed), 4.x (best effort) |

### PHP extensions

`fileinfo`, `intl`, `mbstring` and `gd`. GD is what generates thumbnails: without it the queued derivative job fails and every card falls back to a glyph tile. No optional binary is required anywhere.

Filament 4 support is best effort, limited to the plugin and field APIs both majors share. It rides the same Composer line as Filament 5 and is guarded by a CI job on every push, so a red Filament 4 job blocks a release. See [ADR 0008](docs/adr/0008-filament-4-support-rides-one-line-guarded-by-ci.md).

## Installation

You can install the package via Composer:

```bash
composer require lisowiecw/filament-media-library
```

Then register the plugin on any Filament panel:

```php
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugin(MediaLibraryPlugin::make());
}
```

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="media-library-config"
```

### Publishing and Running the Migrations

The package's migrations run automatically. Publish them only if you want to edit them:

```bash
php artisan vendor:publish --tag="media-library-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="media-library-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="media-library-translations"
```

## Usage

<!-- Add a basic usage example here. -->

### Authorization

The package registers a `MediaAssetPolicy` that denies everything, and two gates,
`uploadMedia` and `attachMedia`, that deny as well. Forgetting to write a policy
therefore denies rather than allows. Replace them from your own application:

```php
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

Gate::policy(MediaAsset::class, App\Policies\MediaAssetPolicy::class);

Gate::define('uploadMedia', fn (User $user, Model|string|null $host, ?string $field) => $user->isEditor());
Gate::define('attachMedia', fn (User $user, Model|string|null $host, ?string $field) => $user->isEditor());
```

The policy abilities are `viewAny`, `view`, `update`, `delete`, `forceDelete`,
`restore`, `detach`, and, for the management page's bulk buttons alone,
`deleteAny` and `restoreAny`. Renaming an asset asks `update` and downloading
one asks `view`; neither has an ability of its own. `view` governs an asset's actual content rather than its
listing, so it is checked where bytes are delivered and never per row in a grid.
Reading a public asset asks nothing, since its content is already publicly
addressable. That exception is the plugin's rather than the policy's, because
the policy is the piece you replace: ask
`Lisowiecw\MediaLibrary\Authorization\MediaAuthorization` rather than the `Gate`
facade, and a public asset answers true without a policy ever being consulted.

### Storage placement

A field's placement is the disk, directory and visibility its uploads land with.
An application that keeps public and private media in two buckets names both
disks once, in `media-library.public_disk` and `media-library.private_disk`
(`MEDIA_LIBRARY_PUBLIC_DISK`, `MEDIA_LIBRARY_PRIVATE_DISK`), and a field that
declares only its visibility lands in the matching one. A field that names a disk
of its own still wins.

Because a bucket's access is a property of the bucket rather than of the object,
a disk that cannot deliver the visibility declared on it is a configuration
error. A public placement on a disk configured with no `url`, or a private
placement on a disk you have declared public (as `public_disk`, or with
`'visibility' => 'public'` on the disk itself), throws `PlacementMisconfigured` when
the placement resolves, so the field fails on the first render rather than on the
first upload. Nothing asks the storage provider: the check reads your
configuration only.

If you deliberately serve a public disk through your own origin, set
`media-library.enforce_disk_visibility` (`MEDIA_LIBRARY_ENFORCE_DISK_VISIBILITY`)
to false, which stands both rules down.

#### Two buckets, one library

A Cloudflare R2 deployment that keeps public and private media apart runs two
buckets, and a bucket is a Laravel disk, so the whole arrangement is two disks
named once:

```php
// config/filesystems.php
'r2-public' => [
    'driver' => 's3',
    // ... key, secret, region, bucket, endpoint
    'url' => env('R2_PUBLIC_URL'),  // the public hostname bound to this bucket
],

'r2-private' => [
    'driver' => 's3',
    // ... key, secret, region, bucket, endpoint
    // no `url`: nothing about this bucket is publicly addressable
],
```

```dotenv
MEDIA_LIBRARY_PUBLIC_DISK=r2-public
MEDIA_LIBRARY_PRIVATE_DISK=r2-private
```

With the pair set, a field states its visibility and nothing else, and its
uploads land in the matching bucket:

```php
MediaPicker::make('gallery')->visibility('public');   // r2-public
MediaPicker::make('contracts')->visibility('private'); // r2-private
```

No field needs a disk of its own once the pair is set. `media-library.disk`
narrows to the fallback for a visibility whose half of the pair is unset, which
is what a half-migrated deployment gets. See
[ADR 0012](docs/adr/0012-the-disk-pair-is-configured-not-named-per-field.md).

#### The bucket is the enforcement

On R2 the asset's `visibility` column is delivery intent: it decides how the
package addresses the bytes, not who can read them. Access is a property of the
bucket. A private asset sitting in a public bucket is not private, however the
column reads: the package will route it through the Delivery route and check
`view` on it, while anyone who guesses the object key fetches it straight from
the bucket.

That is why the pairing is a guard rather than a convention, and why the guard
above refuses those two pairings when the placement resolves. It reads your
configuration only, so a bucket left public by mistake at the provider is still
something only you can see. See
[ADR 0013](docs/adr/0013-a-disk-that-cannot-deliver-its-visibility-is-refused.md).

#### ACLs, and why the package makes none

Laravel's S3 adapter sends an `ACL` parameter on every `PutObject`, derived from
the visibility it was handed and, where the disk names no `visibility` of its
own, from Laravel's default for the S3 driver, which is `public`. R2 implements
no ACL headers at all (`x-amz-acl` and `x-amz-grant-*` are unimplemented) and no
ACL operations (`GetObjectAcl`, `PutObjectAcl`), so that parameter is accepted
and ignored. On R2 it is neither the reason a public object is readable nor a
leak on a private one: the bucket is.

The package makes no ACL call of its own, and never reads visibility back from
the provider. `Storage::getVisibility()` is a `GetObjectAcl` behind the scenes,
which R2 does not implement, so on an R2 disk it fails rather than answering.
The stored column and your two buckets are the whole picture.

### Delivery

A private asset's content reaches a browser through one signed route the plugin
registers per panel, inside that panel's middleware. Every request to it re-checks
`view`, so a leaked URL stops working the moment the policy says so, and no raw
presigned URL is ever handed to a browser. `media-library.signed_url_ttl`
(`MEDIA_LIBRARY_SIGNED_URL_TTL`) sets how long a signature lasts, five minutes by
default.

The route serves an asset for rendering in place only when it is not active content
and its mime type came from a stored header or a content sniff; everything else is
served for saving, and `?download=1` forces that anyway. Every response carries
`Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`,
and an asset that renders in place is streamed rather than redirected so the header
survives.

A redirect asks the disk to honour the S3 `response-content-type` and
`response-content-disposition` query overrides, so the earned disposition
survives the hop. Those overrides are standard on AWS S3. On R2 they are
**observed to work rather than documented**: Cloudflare's S3 compatibility page
says nothing about them either way, and they were observed working on 2026-08-27
against a live R2 bucket, over both an `r2.dev` development URL and a custom
domain. A disk that ignores them serves the object's stored headers instead,
which is why they are written to say the same thing at upload (see below).

**The route's URL, name and parameters are internal.** They may change in any
release. Do not build them by hand or hardcode them in a template.

#### The saved filename

A file saved to a viewer's disk is called whatever the uploader's own file was
called, and the asset's Display name only where there is no original filename.
One resolver overrides that for the whole application:

```php
use Lisowiecw\MediaLibrary\Models\MediaAsset;

MediaLibraryPlugin::make()
    ->downloadFilenameUsing(fn (MediaAsset $asset): string => $asset->display_name);
```

The resolver is handed the asset and nothing else, so it cannot vary the name by
host model: an asset can be attached in many places, and the header baked onto
the stored object is written at upload, before any attachment exists. The
editable Display name is the per-asset lever to reach for instead.

Its answer is scrubbed by the same rules as an uploaded filename, so a resolver
cannot break or inject a header, and the asset's own extension is appended where
the answer is a stem without one. The same resolver names both the route's
`Content-Disposition` and the one written onto the object at upload, so a disk
that ignores response overrides still serves the same name.

#### A delivery gate of your own

An application whose downloads carry rules the plugin knows nothing about, an
order token, an expiry, a remaining-downloads count, keeps its own route and
controller and reads the storage location off the `MediaAsset`:

```php
Route::get('/orders/{order}/download/{token}', function (Order $order, string $token) {
    abort_unless($order->downloadTokenIsValid($token), 403);
    abort_if($order->downloads_remaining < 1, 410);

    $asset = $order->product->pdf;   // a MediaAsset, however your app reaches it

    $order->decrement('downloads_remaining');

    return Storage::disk($asset->disk)->download(
        $asset->object_key,
        DownloadFilename::for($asset),
    );
})->name('orders.download');
```

`Lisowiecw\MediaLibrary\Delivery\DownloadFilename` is what the plugin's own
route asks, so your gate saves a file under the same name rather than deriving a
second one. `download()` streams the bytes through your application. To hand the transfer to
the bucket instead, sign your own URL from the same two columns:

```php
return redirect()->away(Storage::disk($asset->disk)->temporaryUrl(
    $asset->object_key,
    now()->addMinutes(5),
));
```

Signing means the count is spent when the link is issued rather than when the
bytes are fetched, and the signature outlives a rule you change in the next
minute. Stream where the rules are strict, sign where the files are large.

What this recipe leans on is promised surface: the `MediaAsset` model, its
`disk` and `object_key` columns, `DownloadFilename`, and the policy abilities. **The Delivery route's
URL, name and parameters are not**, so build your own route rather than signing
or wrapping the plugin's.

The alternative is to fold the rule into the `view` ability instead, and let the
plugin's own route enforce it:

```php
public function view(?User $user, MediaAsset $asset): bool
{
    return $user !== null && Order::forUser($user)->hasUnspentDownloadOf($asset);
}
```

Then `$asset->downloadUrl()` is the whole integration, and the rule is re-checked
on every hit of the route rather than once at issue. Reach for it when the rule
is a property of the viewer and the asset, since that is the question `view` asks.
Keep your own route when the rule belongs to something else entirely, an order,
a token, a counter to decrement, because a policy is asked whether access is
allowed, not told to spend something.

### Card placeholders

A grid card whose thumbnail is not generated yet paints the asset's own BlurHash
rather than a flat tile. The package ships no JavaScript and no stylesheet, so the
hash is decoded in PHP and painted as a handful of CSS gradients in a `style`
attribute. Nothing to install, and nothing to build.

That painting is coarse on purpose. The card also carries the hash verbatim as
`data-blurhash` on the same element, so an application that already has a decoder
can render a proper one over the top:

```js
document.querySelectorAll('[data-blurhash]').forEach((tile) => {
    // decode tile.dataset.blurhash however your app already does
})
```

`data-blurhash` is present only where the asset has a hash and the tile is
standing in for a thumbnail; a card showing a real thumbnail never carries one.
It is emitted even where the package declined to paint from it, so a decoder of
your own still gets the value.

The package's own painting lives entirely in that element's `style` attribute,
so a decoder that renders over the top either paints above it or clears it with
`tile.style.background = ''`.

### Lifecycle and cleanup

Removing a picture from a record detaches it, which touches the attachment row and
nothing else. The asset, its object and its renderings stay, and so does every
other place it is attached:

```php
$article->detachMedia('cover_image', $asset);
```

Deleting is the separate, explicit act. It soft-deletes the record and queues
removal of the backing object and every derivative made from it, so a mistake is
recoverable for as long as the queue takes and the bucket is still cleaned
afterwards. The removal job uses the queue's own retries and lands in
`failed_jobs` when they are exhausted, so a bucket outage is retried with
`queue:retry` like anything else.

A delete is blocked while anything still references the asset, including an
external reference. The refusal carries the usage list, so the caller can show it
and ask again:

```php
use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;

try {
    app(AssetLifecycle::class)->delete($asset);
} catch (DeleteBlocked $blocked) {
    // $blocked->usage is the list to review, then:
    app(AssetLifecycle::class)->delete($asset, force: true);
}
```

A host model may say how it reads in that list by defining `mediaUsageLabel()`;
without one, the list names the model and its key. Restoring a soft-deleted asset
brings back the record alone: its renderings are regenerated lazily on the next
render rather than resurrected.

These rules are package-global. A field cannot switch them off, because the asset
one field deletes is the asset every other field shares.

#### External references

Something outside any host model can record that it uses an asset: a newsletter,
an export, a scheduled campaign. It is written as an attachment with no host, so
it appears in the usage list and blocks a delete exactly like a host row does,
with no second mechanism behind it:

```php
$asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412');
```

The identifier is your own handle on the thing making the reference and the label
is what a person reviewing a delete reads. Registering the same identifier twice
is the same reference stated again, so it lands on one row, enforced by a unique
index rather than by the order two runs happen to land in. A label is written
only when one is given, so a rerun that names the identifier alone leaves the
existing wording alone. Withdraw it when the thing that made it is gone:

```php
$asset->attachments()->revokeExternal('newsletter-2026-08');
```

An external reference belongs to no field context, so `media()` and `firstMedia()`
never return one and a picker never sees it. On the management page it can also be
revoked per row from the usage panel, behind the `detach` ability. Host rows are
listed there but not removable, and neither revoke will touch one whatever it is
handed, since detaching belongs on the host record.

Finding unused files is a report you ask for, never something that happens to you:

```bash
php artisan media:unattached-assets
php artisan media:unattached-assets --days=90
```

It lists assets nothing has referenced for longer than the grace period
(`media-library.unattached_grace_days`, 30 days by default), deletes nothing, and
is not scheduled by the package. The period counts from when an asset last
stopped being referenced, recorded on `media_assets.unattached_since`, so an
asset detached yesterday keeps its full grace period however old it is; an asset
nothing ever referenced counts from its upload instead. Being unattached is evidence rather than proof:
a URL can live in a sent email or an export the plugin cannot see.

### Rich text attachments

Filament's `RichEditor` uploads its own inline files straight to a disk, which
leaves the library knowing about every picked image and nothing about anything
an author dragged into a post body. Point it at the ingest seam instead and an
inline image becomes an ordinary Media Asset:

```php
use Filament\Forms\Components\RichEditor;
use Illuminate\Http\UploadedFile;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

function editorReference(MediaAsset $asset): string
{
    return 'editor:'.$asset->ulid;
}

RichEditor::make('body')
    ->saveUploadedFileAttachmentsUsing(function (UploadedFile $file): string {
        $asset = app(IngestService::class)->ingest($file, Placement::resolve(
            directory: 'editor',
            visibility: Visibility::Public,
        ));

        $asset->attachments()->createExternal(editorReference($asset), 'Post body');

        return $asset->url();
    });
```

`IngestService::ingest()` is the promised entry point, and it is the same one
the picker and the management page use, so the whole floor applies here without
being restated: the blocked-type list, the configured accepted types, the family
mismatch refusal, SVG sanitization and its strict pass, the refusal of active
content on public placement, the stored headers, and the tenant and uploader
stamps. A refused file throws `IngestRefused`, which Filament surfaces to the
author. The `Placement` argument is optional; leave it off entirely and the
configured disk pair and default visibility apply. Resolve it rather than
constructing one, so the disk comes from your configured pair and the invariant
that a public placement needs a disk with a URL to give is checked here rather
than at the first upload.

The External reference is what makes the asset count as used. Without it nothing
records the image as used at all, so it blocks no delete and the unattached
sweep reports it for review however live the post is. The identifier names the
upload rather than the post, because the callback runs while the file is being
dropped in, which on a create form is before the post has a key; one body holds
many images and each is its own reference. Keep the identifier derivable, as
here from the asset's own `ulid`, so the revoking half can rebuild it.

Placement must be public here. A private asset resolves to a signed Delivery
URL, and that URL goes into the saved HTML, where it rots on its own expiry: the
body renders fine for whoever saved it and shows broken images to everyone
reading it an hour later. A public asset resolves to the disk's own URL, which
has no expiry to outlive. Editor uploads are therefore a public-placement
feature, and an author dragging in Active content is refused rather than
silently stored privately.

Revoking is yours to trigger. When an image is removed from the body, or the
post is deleted, withdraw the reference so the asset can be reviewed and deleted:

```php
$asset->attachments()->revokeExternal(editorReference($asset));
```

Which assets those are is a question only your application can answer, from the
saved HTML or from a table of your own that records each upload against the post
it went into. The package does not parse your saved HTML to work out that an
image is gone. It never reads the body, so an asset stays referenced until your
code says otherwise, which is the safe direction to fail in: a stale reference
blocks a delete, while a missed one would sweep an image a live page still
points at.

There is no second picker surface and no editor plugin. The seam is the whole
integration: ingest the file, record the reference, return the URL.

### The management page

The picker is what an editor uses. The library itself is a separate page, off by
default and opted into per panel:

```php
->plugin(MediaLibraryPlugin::make()->withLibraryManagement())
```

Opting in is not opening up: the page is still gated on the `viewAny` ability,
which the packaged policy refuses until your own policy says otherwise. The bulk
actions ask for `deleteAny` and `restoreAny` for the button, then ask about every
row individually before touching it, so a bulk action can only ever do what the
same person could have done a row at a time.

It is a table rather than the picker's grid, it lists everything the picker hides
(private assets, blocked types, and the soft-deleted behind the trashed filter),
and an object key pasted into the search box finds its asset. The view page shows
the disk and object key as copyable fields, where the type came from, where an
import came from, and the usage list that a force delete asks you to review.

What it can do is rename (name and alt text), delete, restore, force delete,
download and upload. What it deliberately cannot do is replace an asset's bytes
in place, change its visibility, or move it between disks or directories: a
published URL is a promise, and each of those would change what an existing
address serves. Renaming is offered precisely because it touches nothing in
storage.

Cleanup has its own filter with a grace-period preset, and a bulk delete
restricted to what that preset selects. Eligibility is recomputed at the moment
of the delete rather than trusted from the filter the rows were listed under.

A health readout carries the failed, missing and stale derivative counts with a
regenerate action beside them. It queues a bounded batch, since it runs in a
request, and names `media:regenerate-derivatives` for whatever is left. The
importer stays a command and is never exposed here.

### Tenancy

The library knows nothing about tenants until a panel tells it who the current
one is:

```php
->plugin(MediaLibraryPlugin::make()->tenantUsing(fn () => Filament::getTenant()))
```

A panel that already has Filament tenancy gets that resolver by default, so the
call above is only needed where the tenant the library sorts by is not the
panel's own. Leave the resolver unset and nothing in this section applies: a
single-tenant application is untouched, byte for byte.

The tenant is stamped onto `media_assets.tenant_id` once, at upload, from
whoever was current. It is never reassigned, and an attempt to move an asset
from one tenant to another throws rather than writing.

Scope decides what is offered and the policy decides what is delivered, which
are two different questions. The picker and the management page query within
the current tenant, so nothing outside it is ever shown; separately, `view` is
refused for an asset outside the current tenant, so a route-model binding or a
guessed Delivery URL cannot sail past a boundary the query merely narrowed. A
cross-tenant Delivery request answers 404 rather than 403, because 403 would
confirm the asset exists.

An asset with no tenant belongs to no one rather than to everyone. No tenant
sees it, and no tenant is delivered it. That is what makes upgrading an existing
single-tenant library safe: the day a resolver is configured, the whole library
goes quiet instead of appearing in every tenant at once.

Claiming is how it comes back, one way and allowed once:

```bash
php artisan media:assign-tenant acme
php artisan media:assign-tenant acme --asset=01J... --dry-run
```

The same claim is available as a bulk action on the management page, for a
person the host application has unlocked with the `viewAllTenants` ability. That
ability is refused by the packaged policy and unlocks an "All tenants" toggle, a
tenant column and a tenant facet on the listing, which is the only place the
library is ever shown unscoped.

An attachment made before tenancy existed is left alone rather than broken: it
still counts as usage and still blocks deletion, and its tile degrades to a
dimmed glyph, since the viewer may not look at the bytes. Attaching anything new
across a tenant boundary is refused.

Imports say who the adopted objects belong to, and `none` is a valid answer:

```bash
php artisan media:import --disk=media --prefix=legacy --source=disk --tenant=none
```

Jobs and commands are neither scoped nor policy-checked. An operator on the
server is not a request inside a panel, and a claim that could only be made from
inside the tenant it was claiming for could never be made at all.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Filament Media Library! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Wojciech Lisowiec](https://github.com/lisowiecw)
- [All Contributors](../../contributors)

## License

Filament Media Library is open-sourced software licensed under the [MIT license](LICENSE.md).

SVG sanitizing is handled by [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer), which is GPL-2.0 licensed. It is a runtime dependency pulled in by Composer, not vendored into this package, so this package stays MIT. Anyone who cannot take a GPL dependency should be aware of it before installing.
