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

The policy abilities are `viewAny`, `view`, `update`, `delete`, `forceDelete` and
`detach`. Renaming an asset asks `update` and downloading one asks `view`; neither
has an ability of its own. `view` governs an asset's actual content rather than its
listing, so it is checked where bytes are delivered and never per row in a grid.
Reading a public asset asks nothing, since its content is already publicly
addressable. That exception is the plugin's rather than the policy's, because
the policy is the piece you replace: ask
`Lisowiecw\MediaLibrary\Authorization\MediaAuthorization` rather than the `Gate`
facade, and a public asset answers true without a policy ever being consulted.

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
