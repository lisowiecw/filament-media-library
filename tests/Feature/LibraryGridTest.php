<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Forms\Components\LibraryGrid;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;
use Livewire\Features\SupportTesting\Testable;

/**
 * A library at a size a person would actually browse, seeded in one insert so
 * the test spends its time on the grid rather than on the fixtures.
 *
 * @return list<int>
 */
function seedLibrary(int $count, string $prefix = 'Asset'): array
{
    $rows = [];

    foreach (range(1, $count) as $index) {
        $rows[] = [
            'ulid' => (string) Str::ulid(),
            'display_name' => $prefix.' '.$index,
            'original_client_filename' => Str::slug($prefix).'-'.$index.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'mime_source' => MimeSource::Sniffed->value,
            'size' => 2048,
            'disk' => 'media',
            'object_key' => 'media/'.Str::slug($prefix).'-'.$index.'.jpg',
            'visibility' => 'private',
            'source' => MediaSource::Upload->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    MediaAsset::query()->insert($rows);

    return MediaAsset::query()->orderBy('id')->pluck('id')->all();
}

/**
 * @param  array<string, mixed>  $picker
 */
function libraryModal(array $picker = []): Testable
{
    return pickerForm(article(), $picker)
        ->mountAction(TestAction::make('library')->schemaComponent('cover_image'))
        ->call('renderEverything');
}

function gridState(Testable $component, string $key): mixed
{
    return $component->get('mountedActions.0.data.library.'.$key);
}

function search(Testable $component, string $query): Testable
{
    return $component->set('mountedActions.0.data.library.search', $query)
        ->call('renderEverything');
}

function clickCard(Testable $component, int $id): Testable
{
    /** @var array<int> $selection */
    $selection = gridState($component, 'selection') ?? [];

    $selection = in_array($id, $selection, false)
        ? array_values(array_filter($selection, fn (int $selected): bool => $selected !== $id))
        : [...$selection, $id];

    return $component->set('mountedActions.0.data.library.selection', $selection)
        ->call('renderEverything');
}

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

    $component->assertSee('The search changed, so the selection was cleared.');
});

it('says nothing about a reset when there was no selection to drop', function (): void {
    seedLibrary(3);

    search(libraryModal(), 'asset 2')
        ->assertDontSee('The search changed, so the selection was cleared.');
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
