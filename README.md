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
