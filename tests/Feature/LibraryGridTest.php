<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Forms\Components\LibraryGrid;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

it('opens a modal with a Library tab and an Upload tab', function (): void {
    libraryModal()
        ->assertSee('Library')
        ->assertSee('Upload');
});

it('lists what the library holds', function (): void {
    seedLibrary(3);

    libraryModal()->assertSee('Asset 1')->assertSee('Asset 3');
});

it('offers a private asset to a field that uploads private, and hides it from one that uploads public', function (): void {
    makeAsset(['display_name' => 'Hidden away', 'visibility' => 'private']);
    makeAsset(['display_name' => 'Out in the open', 'visibility' => 'public', 'object_key' => 'media/two.jpg']);

    libraryModal(['visibility' => 'private'])
        ->assertSee('Hidden away')
        ->assertSee('Out in the open');

    libraryModal(['visibility' => 'public', 'directory' => 'public'])
        ->assertDontSee('Hidden away')
        ->assertSee('Out in the open');
});

it('offers only what the accepted file types name, whatever the disk and directory say', function (): void {
    makeAsset(['display_name' => 'A photo', 'mime_type' => 'image/jpeg', 'disk' => 'media', 'object_key' => 'elsewhere/one.jpg']);
    makeAsset(['display_name' => 'A document', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'object_key' => 'media/two.pdf']);

    libraryModal(['acceptedFileTypes' => ['image/*'], 'directory' => 'posts/covers'])
        ->assertSee('A photo')
        ->assertDontSee('A document');
});

it('never offers a blocked type', function (): void {
    makeAsset(['display_name' => 'A script', 'mime_type' => 'application/x-httpd-php', 'extension' => 'php', 'object_key' => 'media/one.php']);
    makeAsset(['display_name' => 'A photo']);

    libraryModal()->assertSee('A photo')->assertDontSee('A script');
});

it('searches across everything a person might remember, and marks what matched', function (): void {
    makeAsset(['display_name' => 'Hero banner', 'alt' => 'A rooftop at dusk']);
    makeAsset(['display_name' => 'Footer logo', 'object_key' => 'media/two.jpg']);

    $component = search(libraryModal(), 'hero');

    $component->assertDontSee('Footer logo');

    $component->assertSee('<mark>Hero</mark> banner', escape: false);
});

it('narrows on every term of the query rather than widening', function (): void {
    makeAsset(['display_name' => 'Hero 2024']);
    makeAsset(['display_name' => 'Hero 2023', 'object_key' => 'media/two.jpg']);

    search(libraryModal(), 'hero 2024')
        ->assertSee('2024')
        ->assertDontSee('Hero 2023');
});

it('loads the library in batches of 48, with a load more button and no page-size control', function (): void {
    seedLibrary(120);

    $component = libraryModal();

    expect(gridState($component, 'loaded'))->toBe(LibraryGrid::BATCH);

    $component->assertSee('Asset 120')
        ->assertDontSee('Asset 72')
        ->assertSee('Load more')
        ->assertDontSee('per page');

    $component->set('mountedActions.0.data.library.loaded', 96)
        ->call('renderEverything')
        ->assertSee('Asset 25')
        ->assertDontSee('Asset 24');
});

it('marks the end of the library with the total once everything is loaded', function (): void {
    seedLibrary(3);

    libraryModal()
        ->assertDontSee('Load more')
        ->assertSee('3 assets in the library.');
});

it('toggles a card in and out of the selection, keeping the order they were picked', function (): void {
    $ids = seedLibrary(3);

    $component = libraryModal();

    clickCard($component, $ids[2]);
    clickCard($component, $ids[0]);

    expect(gridState($component, 'selection'))->toBe([$ids[2], $ids[0]]);

    clickCard($component, $ids[2]);

    expect(gridState($component, 'selection'))->toBe([$ids[0]]);
});

it('shows the live ordered selection in the footer', function (): void {
    $ids = seedLibrary(2);

    $component = libraryModal()->assertSee('Nothing selected yet.');

    clickCard($component, $ids[1]);
    clickCard($component, $ids[0]);

    $html = $component->html();

    expect(strpos($html, 'Asset 2'))->toBeLessThan(strpos($html, 'Asset 1'));
});

it('resets the selection when the search changes, and says so', function (): void {
    $ids = seedLibrary(3);

    $component = libraryModal();
    clickCard($component, $ids[0]);

    search($component, 'asset 2');

    expect(gridState($component, 'selection'))->toBe([]);

    $component->assertSee('The filter changed, so the selection was cleared.');
});

it('says nothing about a reset when there was no selection to drop', function (): void {
    seedLibrary(3);

    search(libraryModal(), 'asset 2')
        ->assertDontSee('The filter changed, so the selection was cleared.');
});

it('badges every card public or private', function (): void {
    makeAsset(['display_name' => 'A private one', 'visibility' => 'private']);
    makeAsset(['display_name' => 'A public one', 'visibility' => 'public', 'object_key' => 'media/two.jpg']);

    $html = libraryModal()->html();

    expect(substr_count($html, 'fi-ml-card-visibility-private'))->toBe(1)
        ->and(substr_count($html, 'fi-ml-card-visibility-public'))->toBe(1);
});

it('renders a tinted glyph tile for an asset with nothing to preview', function (): void {
    makeAsset(['display_name' => 'A document', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'object_key' => 'media/one.pdf']);

    libraryModal()
        ->assertSee('fi-ml-card-glyph-application', escape: false)
        ->assertSee('PDF');
});

it('paints the pending tile, with the BlurHash beside it, while a thumb is in flight', function (): void {
    makeAsset([
        'display_name' => 'A big photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash' => 'L6PZfSi_.AyE_3t7t7R**0o#DgR4',
        'object_key' => 'media/big.jpg',
    ]);

    libraryModal()
        ->assertSee('fi-ml-card-glyph-image', escape: false)
        ->assertSee('L6PZfSi_.AyE_3t7t7R**0o#DgR4', escape: false)
        ->assertSee('radial-gradient', escape: false)
        ->assertDontSee('fi-ml-card-thumb', escape: false);
});

it('leaves the pending tile dimmed and unpainted where the asset carries no hash', function (): void {
    makeAsset([
        'display_name' => 'A big photo',
        'visibility' => 'public',
        'size' => 900_000,
        'object_key' => 'media/big.jpg',
    ]);

    libraryModal()
        ->assertSee('fi-ml-card-glyph-image', escape: false)
        ->assertDontSee('radial-gradient', escape: false)
        ->assertDontSee('data-blurhash', escape: false);
});

it('leaves the pending tile dimmed rather than painting from a stored value that is not a hash', function (): void {
    makeAsset([
        'display_name' => 'A big photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash' => 'not a hash',
        'object_key' => 'media/big.jpg',
    ]);

    libraryModal()
        ->assertDontSee('radial-gradient', escape: false)
        ->assertSee('data-blurhash', escape: false);
});

it('paints the thumb once one is ready', function (): void {
    $asset = makeAsset(['display_name' => 'A big photo', 'visibility' => 'public', 'size' => 900_000, 'object_key' => 'media/big.jpg']);

    $asset->derivatives()->create([
        'variant' => DerivativeVariant::Thumb,
        'disk' => $asset->disk,
        'object_key' => MediaDerivative::keyFor($asset, DerivativeVariant::Thumb),
        'status' => DerivativeStatus::Ready,
    ]);

    libraryModal()->assertSee('thumb.webp', escape: false);
});

it('badges a video card rather than looking for a poster frame', function (): void {
    makeAsset(['display_name' => 'A clip', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'object_key' => 'media/clip.mp4']);

    libraryModal()
        ->assertSee('fi-ml-card-play', escape: false)
        ->assertSee('fi-ml-card-glyph-video', escape: false);
});

it('attaches the selection in the order it was picked when the modal is confirmed', function (): void {
    $ids = seedLibrary(3);

    $component = pickerForm(article(), ['maxItems' => 3])
        ->mountAction(TestAction::make('library')->schemaComponent('cover_image'))
        ->call('renderEverything');

    clickCard($component, $ids[2]);
    clickCard($component, $ids[0]);

    $component->callMountedAction()->assertHasNoActionErrors();

    expect($component->get('data.cover_image'))->toBe([$ids[2], $ids[0]]);
});

it('costs one policy evaluation per asset to paint a page of cards', function (): void {
    Gate::policy(MediaAsset::class, HostPolicy::class);
    HostPolicy::$allows = false;
    HostPolicy::$evaluations = 0;

    seedLibrary(60);

    $component = libraryModal();

    // The mount already painted a page; count the next paint on its own.
    HostPolicy::$evaluations = 0;

    $component->call('renderEverything');

    expect(HostPolicy::$evaluations)->toBe(LibraryGrid::BATCH);
});

it('lets a single-selection field hold one card at a time, so the footer never promises more than the field keeps', function (): void {
    [$first, $second] = seedLibrary(2);

    $component = clickCard(libraryModal(), $first);

    // Clicking the second card while the first is picked leaves the field with
    // the second alone, rather than a pair the picker would truncate on confirm.
    $component->assertSee('selection\', JSON.parse(\'['.$second.']\')', escape: false);
});

it('lets a multiple-selection field hold up to its maximum', function (): void {
    [$first, $second] = seedLibrary(2);

    $component = clickCard(libraryModal(['maxItems' => 3]), $first);

    $component->assertSee('selection\', JSON.parse(\'['.$first.','.$second.']\')', escape: false);
});

it('polls while a card on the page is still unresolved', function (): void {
    makeAsset(['display_name' => 'A photo', 'visibility' => 'public', 'size' => 900_000]);

    libraryModal()->assertSee('wire:poll', escape: false);
});

it('stops polling once every card on the page is ready', function (): void {
    $asset = makeAsset([
        'display_name' => 'A photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
        'blurhash_status' => BlurHashStatus::Ready,
    ]);

    readyDerivative($asset);

    libraryModal()->assertDontSee('wire:poll', escape: false);
});

it('stops polling over a card whose hash and rendering have both failed', function (): void {
    $asset = makeAsset([
        'display_name' => 'A broken photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash_status' => BlurHashStatus::Failed,
    ]);

    $asset->derivatives()->create([
        'variant' => DerivativeVariant::Thumb,
        'disk' => $asset->disk,
        'object_key' => MediaDerivative::keyFor($asset, DerivativeVariant::Thumb),
        'status' => DerivativeStatus::Failed,
    ]);

    libraryModal()->assertDontSee('wire:poll', escape: false);
});

it('counts a failed hash as resolved, with the thumb already ready', function (): void {
    $asset = makeAsset([
        'display_name' => 'A photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash_status' => BlurHashStatus::Failed,
    ]);

    readyDerivative($asset);

    libraryModal()->assertDontSee('wire:poll', escape: false);
});

it('counts a failed rendering as resolved, with the hash already ready', function (): void {
    $asset = makeAsset([
        'display_name' => 'A photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
        'blurhash_status' => BlurHashStatus::Ready,
    ]);

    $asset->derivatives()->create([
        'variant' => DerivativeVariant::Thumb,
        'disk' => $asset->disk,
        'object_key' => MediaDerivative::keyFor($asset, DerivativeVariant::Thumb),
        'status' => DerivativeStatus::Failed,
    ]);

    libraryModal()->assertDontSee('wire:poll', escape: false);
});

it('keeps polling while a hash is still in flight', function (): void {
    $asset = makeAsset([
        'display_name' => 'A photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash_status' => BlurHashStatus::Pending,
    ]);

    readyDerivative($asset);

    libraryModal()->assertSee('wire:poll', escape: false);
});

it('never polls a field that paints its own thumbnails', function (): void {
    makeAsset(['display_name' => 'A photo', 'visibility' => 'public', 'size' => 900_000]);

    libraryModal(['thumbnailUsing' => 'stamped'])->assertDontSee('wire:poll', escape: false);
});

it('keeps polling while one card of a resolved page is still unresolved', function (): void {
    $ready = makeAsset([
        'display_name' => 'A photo',
        'visibility' => 'public',
        'size' => 900_000,
        'blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
        'blurhash_status' => BlurHashStatus::Ready,
    ]);

    readyDerivative($ready);

    makeAsset(['display_name' => 'A newer photo', 'visibility' => 'public',
        'size' => 900_000, 'object_key' => 'media/newer.jpg']);

    libraryModal()->assertSee('wire:poll', escape: false);
});

it('never polls over a card that is a glyph tile for good', function (): void {
    makeAsset(['display_name' => 'A clip', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'object_key' => 'media/clip.mp4']);

    libraryModal()->assertDontSee('wire:poll', escape: false);
});
