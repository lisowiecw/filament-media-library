<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;

beforeEach(function (): void {
    Storage::fake('r2-public');
    Storage::fake('r2-private');

    config()->set('media-library.disk', 'media');
});

/**
 * One field definition, which declares its visibility and names no disk. The
 * same definition is read through the same seam under both config shapes, so
 * the only thing that moves between the two cases is the pairing.
 */
function coverField(): MediaPicker
{
    return MediaPicker::make('cover_image')->visibility('public');
}

it('lands a visibility-only field on the package disk when no pair is configured', function (): void {
    $asset = ingest(pngUpload(), coverField()->getPlacement());

    expect($asset->disk)->toBe('media')
        ->and($asset->visibility)->toBe(Visibility::Public);

    Storage::disk('media')->assertExists($asset->object_key);
});

it('lands the same field in the public bucket once the pair is configured', function (): void {
    config()->set('media-library.public_disk', 'r2-public');
    config()->set('media-library.private_disk', 'r2-private');

    $asset = ingest(pngUpload(), coverField()->getPlacement());

    expect($asset->disk)->toBe('r2-public')
        ->and($asset->visibility)->toBe(Visibility::Public);

    Storage::disk('r2-public')->assertExists($asset->object_key);
    Storage::disk('r2-private')->assertDirectoryEmpty('/');
});

it('sends a private field of the same shape to the other bucket', function (): void {
    config()->set('media-library.public_disk', 'r2-public');
    config()->set('media-library.private_disk', 'r2-private');

    $placement = MediaPicker::make('attachment')->visibility('private')->getPlacement();
    $asset = ingest(pngUpload(), $placement);

    expect($asset->disk)->toBe('r2-private');

    Storage::disk('r2-private')->assertExists($asset->object_key);
});
