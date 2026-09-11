<?php

declare(strict_types=1);

use enshrined\svgSanitize\data\AllowedTags;
use Lisowiecw\MediaLibrary\Ingest\Svg\StrictTags;

// The Strict pass subtracts from the sanitizer's own allowlist, so an upstream
// release that stops shipping one of the three would leave the pass silently
// dropping less than it promises rather than failing (ADR-0005).
it('subtracts from an upstream allowlist that still carries all three', function (string $tag): void {
    expect(AllowedTags::getTags())->toContain($tag)
        ->and(StrictTags::getTags())->not->toContain($tag);
})->with(['image', 'style', 'a']);

it('keeps every other tag the sanitizer allows', function (): void {
    $kept = array_values(array_diff(AllowedTags::getTags(), ['image', 'style', 'a']));

    expect(StrictTags::getTags())->toBe($kept)
        ->and(StrictTags::getTags())->toContain('svg', 'rect', 'path');
});
