<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\PlacementMisconfigured;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;

/**
 * The two-bucket deployment as the application declares it: the public bucket
 * has a public host, the private one has none and never could. Only the
 * declaration is read, so the fakes exist to prove nothing was written.
 */
beforeEach(function (): void {
    config()->set('filesystems.disks.r2-public', [
        'driver' => 's3',
        'bucket' => 'media-public',
        'url' => 'https://media.example.com',
    ]);

    config()->set('filesystems.disks.r2-private', [
        'driver' => 's3',
        'bucket' => 'media-private',
    ]);

    config()->set('media-library.public_disk', 'r2-public');
    config()->set('media-library.private_disk', 'r2-private');

    Storage::fake('r2-public');
    Storage::fake('r2-private');
});

it('refuses to resolve a public field on the private bucket', function (): void {
    $field = MediaPicker::make('cover_image')->disk('r2-private')->visibility('public');

    expect(fn () => $field->getPlacement())
        ->toThrow(PlacementMisconfigured::class, 'The media field "cover_image" declares public visibility on disk "r2-private"');

    Storage::disk('r2-private')->assertDirectoryEmpty('/');
});

it('refuses to resolve a private field on the public bucket', function (): void {
    $field = MediaPicker::make('attachment')->disk('r2-public')->visibility('private');

    expect(fn () => $field->getPlacement())
        ->toThrow(PlacementMisconfigured::class, 'The media field "attachment" declares private visibility on disk "r2-public"');

    Storage::disk('r2-public')->assertDirectoryEmpty('/');
});

it('fails on the first render rather than the first upload', function (): void {
    $field = MediaPicker::make('cover_image')->disk('r2-private')->visibility('public');

    expect(fn () => $field->getPlacementSummary())->toThrow(PlacementMisconfigured::class);
});

it('lands an upload either way once the pair delivers what it promises', function (): void {
    $public = ingest(pngUpload(), MediaPicker::make('cover_image')->visibility('public')->getPlacement());
    $private = ingest(pngUpload(), MediaPicker::make('attachment')->visibility('private')->getPlacement());

    expect($public->disk)->toBe('r2-public')
        ->and($public->visibility)->toBe(Visibility::Public)
        ->and($private->disk)->toBe('r2-private')
        ->and($private->visibility)->toBe(Visibility::Private);
});

it('lets an application that serves its public disk itself opt out', function (): void {
    config()->set('media-library.enforce_disk_visibility', false);

    $asset = ingest(
        pngUpload(),
        MediaPicker::make('cover_image')->disk('r2-private')->visibility('public')->getPlacement(),
    );

    expect($asset->disk)->toBe('r2-private')
        ->and($asset->visibility)->toBe(Visibility::Public);

    Storage::disk('r2-private')->assertExists($asset->object_key);
});
