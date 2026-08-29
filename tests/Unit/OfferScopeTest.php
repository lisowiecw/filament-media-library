<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Library\OfferScope;

/**
 * @param  list<string>|null  $accepted
 */
function offered(?array $accepted = null, Visibility $uploads = Visibility::Private): array
{
    return (new OfferScope(IngestRules::resolve(acceptedTypes: $accepted), $uploads))
        ->query()
        ->pluck('display_name')
        ->all();
}

it('offers every unblocked asset to a field that uploads private', function (): void {
    makeAsset(['display_name' => 'Private one', 'visibility' => 'private']);
    makeAsset(['display_name' => 'Public one', 'visibility' => 'public', 'object_key' => 'media/two.jpg']);

    expect(offered())->toEqualCanonicalizing(['Private one', 'Public one']);
});

it('offers only public assets to a field that uploads public', function (): void {
    makeAsset(['display_name' => 'Private one', 'visibility' => 'private']);
    makeAsset(['display_name' => 'Public one', 'visibility' => 'public', 'object_key' => 'media/two.jpg']);

    expect(offered(uploads: Visibility::Public))->toBe(['Public one']);
});

it('offers an asset whose mime matches a family wildcard', function (): void {
    makeAsset(['display_name' => 'Photo', 'mime_type' => 'image/jpeg']);
    makeAsset(['display_name' => 'Clip', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'object_key' => 'media/two.mp4']);

    expect(offered(['image/*']))->toBe(['Photo']);
});

it('offers an asset whose mime matches an exact accepted type', function (): void {
    makeAsset(['display_name' => 'Photo', 'mime_type' => 'image/jpeg']);
    makeAsset(['display_name' => 'Drawing', 'mime_type' => 'image/png', 'extension' => 'png', 'object_key' => 'media/two.png']);

    expect(offered(['image/png']))->toBe(['Drawing']);
});

it('offers an asset whose extension matches a bare accepted extension', function (): void {
    makeAsset(['display_name' => 'Sheet', 'mime_type' => null, 'extension' => 'csv', 'object_key' => 'media/one.csv']);
    makeAsset(['display_name' => 'Photo', 'mime_type' => 'image/jpeg']);

    expect(offered(['csv']))->toBe(['Sheet']);
});

it('never offers a blocked type, however the field is configured', function (): void {
    makeAsset(['display_name' => 'Script', 'mime_type' => 'application/x-httpd-php', 'extension' => 'php', 'object_key' => 'media/one.php']);
    makeAsset(['display_name' => 'Photo', 'mime_type' => 'image/jpeg']);

    expect(offered())->toBe(['Photo']);
});

it('offers an asset that has no extension at all', function (): void {
    makeAsset(['display_name' => 'Keyless', 'extension' => null, 'object_key' => 'media/one']);

    expect(offered())->toBe(['Keyless']);
});

it('offers the newest asset first', function (): void {
    makeAsset(['display_name' => 'First']);
    makeAsset(['display_name' => 'Second', 'object_key' => 'media/two.jpg']);

    expect(offered())->toBe(['Second', 'First']);
});
