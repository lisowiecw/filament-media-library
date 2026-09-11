<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Forms\Components\DropIntake;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

// Livewire stages an upload on its own temporary disk, which nothing else in
// the suite touches.
beforeEach(fn () => Storage::fake(FileUploadConfiguration::disk()));

/**
 * A file where the browser stages one, so the intake can be asked without a
 * field, a form or a Livewire round trip.
 */
function staged(UploadedFile $file): TemporaryUploadedFile
{
    $name = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($file);

    Storage::disk(FileUploadConfiguration::disk())
        ->putFileAs(FileUploadConfiguration::path(), $file, $name);

    return new TemporaryUploadedFile($name, FileUploadConfiguration::disk());
}

/**
 * The titles of everything the intake has said, where Filament puts them
 * outside a Livewire round trip.
 *
 * @return list<string>
 */
function notices(): array
{
    return array_map(
        fn (array $notification): string => strip_tags((string) ($notification['title'] ?? '')),
        session('filament.notifications', []),
    );
}

function intake(bool $multiple = false, ?int $limit = null, array $placement = []): DropIntake
{
    return new DropIntake(
        Placement::resolve(...$placement),
        IngestRules::resolve(),
        multiple: $multiple,
        limit: $limit,
    );
}

it('ingests what was staged and hands back the assets in the order they arrived', function (): void {
    $assets = intake(multiple: true)->take([
        staged(UploadedFile::fake()->image('first.png')),
        staged(UploadedFile::fake()->image('second.png')),
    ]);

    expect($assets)->toHaveCount(2)
        ->and(array_map(fn (MediaAsset $asset): string => $asset->display_name, $assets))
        ->toBe(['first', 'second']);
});

it('ingests through the placement it was built with', function (): void {
    $assets = intake(placement: ['directory' => 'posts/covers'])
        ->take(staged(UploadedFile::fake()->image('dropped.png')));

    expect($assets[0]->object_key)->toStartWith('posts/covers/');
});

it('ignores whatever else sits at the staging path', function (): void {
    expect(intake()->take(['', null, 'a path that is not a file']))->toBe([])
        ->and(MediaAsset::query()->count())->toBe(0);
});

it('takes the first of several files onto a single-selection field, and says so', function (): void {
    $assets = intake()->take([
        staged(UploadedFile::fake()->image('first.png')),
        staged(UploadedFile::fake()->image('second.png')),
    ]);

    expect($assets)->toHaveCount(1)
        ->and($assets[0]->display_name)->toBe('first')
        ->and(MediaAsset::query()->count())->toBe(1);

    expect(notices())->toHaveCount(1);
});

it('never ingests what the field has no room for', function (): void {
    $assets = intake(multiple: true, limit: 3)->take([
        staged(UploadedFile::fake()->image('first.png')),
        staged(UploadedFile::fake()->image('second.png')),
    ], held: 2);

    // The cap is on the gesture: the second file leaves no asset behind for a
    // later sweep to find, because it was never uploaded.
    expect($assets)->toHaveCount(1)
        ->and(MediaAsset::query()->count())->toBe(1);

    expect(notices())->toHaveCount(1);
});

it('takes a file onto a single-selection field that is full already, since it replaces', function (): void {
    $assets = intake()->take(staged(UploadedFile::fake()->image('replacement.png')), held: 1);

    expect($assets)->toHaveCount(1)
        ->and($assets[0]->display_name)->toBe('replacement')
        ->and(notices())->toBe([]);
});

it('takes everything where the field has no ceiling', function (): void {
    $assets = intake(multiple: true)->take([
        staged(UploadedFile::fake()->image('first.png')),
        staged(UploadedFile::fake()->image('second.png')),
        staged(UploadedFile::fake()->image('third.png')),
    ], held: 12);

    expect($assets)->toHaveCount(3);
});

it('keeps the rest of a gesture the ingest floor refuses one file out of, and says which', function (): void {
    $assets = intake(multiple: true)->take([
        staged(UploadedFile::fake()->image('kept.png')),
        staged(UploadedFile::fake()->create('refused.php', 1, 'text/x-php')),
        staged(UploadedFile::fake()->image('also-kept.png')),
    ]);

    expect(array_map(fn (MediaAsset $asset): string => $asset->display_name, $assets))
        ->toBe(['kept', 'also-kept']);

    expect(notices())->toHaveCount(1);
});
