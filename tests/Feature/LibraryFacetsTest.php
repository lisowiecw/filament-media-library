<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Library\LibrarySort;
use Livewire\Features\SupportTesting\Testable;

/**
 * @param  array<string, list<string>>  $filters
 */
function filter(Testable $component, array $filters): Testable
{
    return $component->set('mountedActions.0.data.library.filters', $filters)
        ->call('renderEverything');
}

function sortBy(Testable $component, string $sort): Testable
{
    return $component->set('mountedActions.0.data.library.sort', $sort)
        ->call('renderEverything');
}

it('lists a facet sidebar with type, visibility, usage, uploaded by and uploaded', function (): void {
    makeAsset(['display_name' => 'A photo', 'uploaded_by' => 'ada']);
    makeAsset(['display_name' => 'A document', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'object_key' => 'media/two.pdf', 'uploaded_by' => 'grace']);

    libraryModal()
        ->assertSee('Type')
        ->assertSee('Visibility')
        ->assertSee('Usage')
        ->assertSee('Uploaded by')
        ->assertSee('Uploaded')
        ->assertSee('ada')
        ->assertSee('grace');
});

it('derives the type facet from the field own accepted types, and drops it when the field named only one', function (): void {
    makeAsset(['display_name' => 'A photo']);

    libraryModal(['acceptedFileTypes' => ['image/*', 'application/pdf']])
        ->assertSee('data-facet="type"', escape: false)
        ->assertSee('application/pdf');

    libraryModal(['acceptedFileTypes' => ['image/*']])
        ->assertDontSee('data-facet="type"', escape: false);
});

it('never exposes provenance as a picker facet', function (): void {
    makeAsset(['display_name' => 'A photo']);

    libraryModal()
        ->assertDontSee('data-facet="source"', escape: false)
        ->assertDontSee('data-facet="mime_source"', escape: false)
        ->assertDontSee('data-facet="import_source"', escape: false);
});

it('narrows the grid to the ticked options, widening within a dimension and narrowing across them', function (): void {
    makeAsset(['display_name' => 'Public photo', 'visibility' => 'public']);
    makeAsset(['display_name' => 'Private photo', 'visibility' => 'private', 'object_key' => 'media/two.jpg']);
    makeAsset(['display_name' => 'Private doc', 'visibility' => 'private', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'object_key' => 'media/three.pdf']);

    $component = libraryModal();

    filter($component, ['visibility' => ['private']])
        ->assertDontSee('Public photo')
        ->assertSee('Private photo')
        ->assertSee('Private doc');

    filter($component, ['visibility' => ['private', 'public']])
        ->assertSee('Public photo')
        ->assertSee('Private doc');

    filter($component, ['visibility' => ['private'], 'type' => ['image/*']])
        ->assertSee('Private photo')
        ->assertDontSee('Private doc');
});

it('offers "not attached anywhere" as a view filter in the picker', function (): void {
    $used = makeAsset(['display_name' => 'A used photo']);
    makeAsset(['display_name' => 'An unused photo', 'object_key' => 'media/two.jpg']);

    attach(article(), $used);

    $component = libraryModal();

    $component->assertSee('Not attached anywhere');

    filter($component, ['usage' => ['unattached']])
        ->assertSee('An unused photo')
        ->assertDontSee('A used photo');

    filter($component, ['usage' => ['attached']])
        ->assertSee('A used photo')
        ->assertDontSee('An unused photo');
});

it('counts a facet against every active filter except its own dimension', function (): void {
    makeAsset(['display_name' => 'Public photo', 'visibility' => 'public']);
    makeAsset(['display_name' => 'Private photo', 'visibility' => 'private', 'object_key' => 'media/two.jpg']);
    makeAsset(['display_name' => 'Private doc', 'visibility' => 'private', 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'object_key' => 'media/three.pdf']);

    $component = libraryModal();

    // Nothing ticked: each option describes the whole field-scoped set.
    expect(facetCount($component, 'visibility:private'))->toBe(2)
        ->and(facetCount($component, 'visibility:public'))->toBe(1);

    filter($component, ['type' => ['image/*']]);

    // The type filter narrows the visibility counts, since it is a different
    // dimension; the visibility counts still describe both of their own sides.
    expect(facetCount($component, 'visibility:private'))->toBe(1)
        ->and(facetCount($component, 'visibility:public'))->toBe(1);

    filter($component, ['visibility' => ['public']]);

    // A ticked option never narrows its own dimension's counts, so the count
    // beside "private" still says what clicking it would yield.
    expect(facetCount($component, 'visibility:private'))->toBe(2);
});

it('never clicks into an empty grid: a count of zero is what the grid then holds', function (): void {
    makeAsset(['display_name' => 'Public photo', 'visibility' => 'public']);

    $component = libraryModal();

    expect(facetCount($component, 'visibility:private'))->toBe(0);

    filter($component, ['visibility' => ['private']])
        ->assertSee('Nothing in the library matches.');
});

it('counts against the search as well, in the same round trip as the results', function (): void {
    makeAsset(['display_name' => 'Hero banner', 'visibility' => 'public']);
    makeAsset(['display_name' => 'Footer logo', 'visibility' => 'private', 'object_key' => 'media/two.jpg']);

    $component = search(libraryModal(), 'hero');

    expect(facetCount($component, 'visibility:public'))->toBe(1)
        ->and(facetCount($component, 'visibility:private'))->toBe(0);
});

it('drops the counts above the threshold, leaving the facets listed and clickable', function (): void {
    config()->set('media-library.facet_count_threshold', 1);

    makeAsset(['display_name' => 'Public photo', 'visibility' => 'public']);
    makeAsset(['display_name' => 'Private photo', 'visibility' => 'private', 'object_key' => 'media/two.jpg']);

    $component = libraryModal();

    expect(facetCount($component, 'visibility:public'))->toBeNull()
        ->and(facetCount($component, 'visibility:private'))->toBeNull();

    $component->assertSee('Private')->assertSee('Public');

    // Listed without numbers is still clickable.
    filter($component, ['visibility' => ['public']])
        ->assertSee('Public photo')
        ->assertDontSee('Private photo');
});

it('measures the threshold on the field-scoped set before search and facets', function (): void {
    config()->set('media-library.facet_count_threshold', 1);

    makeAsset(['display_name' => 'Hero banner']);
    makeAsset(['display_name' => 'Footer logo', 'object_key' => 'media/two.jpg']);

    // A search cutting the set to one row does not buy the counts back: the
    // measure is taken before it, so the answer cannot flicker as they type.
    expect(facetCount(search(libraryModal(), 'hero'), 'visibility:private'))->toBeNull();
});

it('resets the selection when a facet changes, and says so', function (): void {
    $ids = seedLibrary(3);

    $component = libraryModal();
    clickCard($component, $ids[0]);

    filter($component, ['visibility' => ['private']]);

    expect(gridState($component, 'selection'))->toBe([]);

    $component->assertSee('The filter changed, so the selection was cleared.');
});

it('sorts newest, oldest, by name and by most used, defaulting to newest', function (): void {
    $old = makeAsset(['display_name' => 'Zebra', 'created_at' => now()->subYear()]);
    $new = makeAsset(['display_name' => 'Aardvark', 'object_key' => 'media/two.jpg']);

    attach(article(), $old);

    $component = libraryModal();

    expect(gridState($component, 'sort'))->toBe(LibrarySort::Newest->value);

    expect(nameOrder($component))->toBe(['Aardvark', 'Zebra'])
        ->and(nameOrder(sortBy($component, 'oldest')))->toBe(['Zebra', 'Aardvark'])
        ->and(nameOrder(sortBy($component, 'name')))->toBe(['Aardvark', 'Zebra'])
        ->and(nameOrder(sortBy($component, 'most_used')))->toBe(['Zebra', 'Aardvark']);

    expect($new->fresh())->not->toBeNull();
});

it('leaves the selection alone when only the sort changes', function (): void {
    $ids = seedLibrary(3);

    $component = libraryModal();
    clickCard($component, $ids[0]);

    sortBy($component, 'name');

    expect(gridState($component, 'selection'))->toBe([$ids[0]]);
});

it('debounces the search box at the configured interval', function (): void {
    config()->set('media-library.search_debounce', 750);

    libraryModal()->assertSee('debounce.750ms', escape: false);
});

it('ignores a filter option the sidebar never listed', function (): void {
    makeAsset(['display_name' => 'A photo']);

    filter(libraryModal(), ['visibility' => ['sideways'], 'nonsense' => ['x']])
        ->assertSee('A photo');
});

/**
 * What the sidebar says clicking an option would yield, read off the rendered
 * button, or null on a library that has outgrown counting.
 */
function facetCount(Testable $component, string $option): ?int
{
    $found = preg_match(
        '/data-facet-option="'.preg_quote($option, '/').'"\s*(?:data-facet-count="(\d+)")?/',
        $component->html(),
        $matches,
    );

    expect($found)->toBe(1, "No {$option} facet option in the sidebar.");

    return isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : null;
}

/**
 * @return list<string>
 */
function nameOrder(Testable $component): array
{
    preg_match_all('/fi-ml-card-name">(?:<mark>)?([^<]+)/', $component->html(), $matches);

    /** @var list<string> $names */
    $names = $matches[1];

    return $names;
}
