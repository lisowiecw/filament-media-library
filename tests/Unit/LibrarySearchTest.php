<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Library\LibrarySearch;
use Lisowiecw\MediaLibrary\Library\OfferScope;

function found(string $query): array
{
    $builder = (new OfferScope(IngestRules::resolve(), Visibility::Private))->query();

    LibrarySearch::of($query)->apply($builder);

    return $builder->pluck('display_name')->all();
}

it('matches a case-insensitive substring of the readable name', function (): void {
    makeAsset(['display_name' => 'Hero banner']);
    makeAsset(['display_name' => 'Footer logo', 'object_key' => 'media/two.jpg']);

    expect(found('ERO BAN'))->toBe(['Hero banner']);
});

it('matches across the original filename, alt text, uploader and object key', function (): void {
    makeAsset(['display_name' => 'One', 'original_client_filename' => 'seaside.jpg']);
    makeAsset(['display_name' => 'Two', 'alt' => 'A quiet harbour', 'object_key' => 'media/two.jpg']);
    makeAsset(['display_name' => 'Three', 'uploaded_by' => 'ada-lovelace', 'object_key' => 'media/three.jpg']);
    makeAsset(['display_name' => 'Four', 'object_key' => 'media/2024/rooftop.jpg']);

    expect(found('seaside'))->toBe(['One'])
        ->and(found('harbour'))->toBe(['Two'])
        ->and(found('lovelace'))->toBe(['Three'])
        ->and(found('rooftop'))->toBe(['Four']);
});

it('narrows rather than widens when the query has several terms', function (): void {
    makeAsset(['display_name' => 'Hero 2024']);
    makeAsset(['display_name' => 'Hero 2023', 'object_key' => 'media/two.jpg']);
    makeAsset(['display_name' => 'Footer 2024', 'object_key' => 'media/three.jpg']);

    expect(found('hero 2024'))->toBe(['Hero 2024']);
});

it('lets a term match a different column than its neighbour', function (): void {
    makeAsset(['display_name' => 'Hero', 'alt' => 'A rooftop at dusk']);
    makeAsset(['display_name' => 'Hero', 'alt' => 'A harbour', 'object_key' => 'media/two.jpg']);

    expect(found('hero rooftop'))->toHaveCount(1);
});

it('takes a wildcard in the query as a literal character', function (): void {
    makeAsset(['display_name' => 'Hero banner']);

    expect(found('%'))->toBe([]);
});

it('highlights every matched term in the name', function (): void {
    $highlighted = LibrarySearch::of('hero 2024')->highlight('Hero banner 2024');

    expect($highlighted->toHtml())->toBe('<mark>Hero</mark> banner <mark>2024</mark>');
});

it('escapes the name it highlights', function (): void {
    expect(LibrarySearch::of('logo')->highlight('<b>logo</b>')->toHtml())
        ->toBe('&lt;b&gt;<mark>logo</mark>&lt;/b&gt;');

    expect(LibrarySearch::of('')->highlight('<b>logo</b>')->toHtml())
        ->toBe('&lt;b&gt;logo&lt;/b&gt;');
});

it('merges overlapping term matches into one mark', function (): void {
    expect(LibrarySearch::of('her hero')->highlight('Hero')->toHtml())->toBe('<mark>Hero</mark>');
});
