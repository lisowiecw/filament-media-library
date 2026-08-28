<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

it('refuses a file over the configured size', function (): void {
    ingest(
        UploadedFile::fake()->create('big.pdf', 2048),
        rules: IngestRules::resolve(maxUploadSize: 1024),
    );
})->throws(IngestRefused::class, 'is 2048 KB, which is over the 1024 KB limit');

it('lets a field raise the size above the package default', function (): void {
    config()->set('media-library.max_upload_size', 64);

    $asset = ingest(
        UploadedFile::fake()->create('big.pdf', 128),
        rules: IngestRules::resolve(maxUploadSize: 256),
    );

    expect($asset->exists)->toBeTrue();
});

it('refuses a blocked extension whatever the bytes say', function (): void {
    ingest(UploadedFile::fake()->createWithContent('shell.php', 'plain words'));
})->throws(IngestRefused::class, 'is of a blocked type');

it('refuses a blocked mime whatever the name says', function (): void {
    ingest(
        UploadedFile::fake()->createWithContent('notes.txt', 'plain words'),
        rules: IngestRules::resolve(blockedTypes: ['text/plain']),
    );
})->throws(IngestRefused::class, 'is of a blocked type');

it('names both the declared and the sniffed type without exposing the object key', function (): void {
    try {
        ingest(UploadedFile::fake()->createWithContent('shell.php', 'plain words'));
    } catch (IngestRefused $refusal) {
        expect($refusal->getMessage())->toContain('shell.php')
            ->and($refusal->getMessage())->toContain('php')
            ->and($refusal->getMessage())->toContain('text/plain')
            ->and($refusal->getMessage())->not->toContain('media/');
    }
});

it('stores nothing at all when it refuses', function (): void {
    try {
        ingest(UploadedFile::fake()->createWithContent('shell.php', 'plain words'));
    } catch (IngestRefused) {
        // The refusal is the point; what matters is what it left behind.
    }

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

it('re-runs the accepted-type gate against the sniffed truth', function (): void {
    $asset = ingest(
        UploadedFile::fake()->createWithContent('rows.csv', 'plain words'),
        rules: IngestRules::resolve(acceptedTypes: ['text/csv']),
    );

    expect($asset->mime_type)->toBe('text/plain')
        ->and($asset->extension)->toBe('csv');
});

it('refuses a type the field does not accept', function (): void {
    ingest(pngUpload(), rules: IngestRules::resolve(acceptedTypes: ['application/pdf']));
})->throws(IngestRefused::class, 'is not an accepted type');

it('refuses a sniff in a different family than the extension, even where both are accepted', function (): void {
    ingest(
        UploadedFile::fake()->image('photo.txt', 20, 20),
        rules: IngestRules::resolve(acceptedTypes: ['image/*', 'text/plain']),
    );
})->throws(IngestRefused::class, 'which is a different kind of file');

it('does not read unrecognised bytes as a family mismatch', function (): void {
    $asset = ingest(UploadedFile::fake()->createWithContent('payload.weird', "\x00\x01binary\x02"));

    expect($asset->exists)->toBeTrue();
});

it('stores active content on a private placement', function (): void {
    $asset = ingest(UploadedFile::fake()->createWithContent('page.html', '<html><body>hi</body></html>'));

    expect($asset->exists)->toBeTrue()
        ->and($asset->mime_type)->toBe('text/html')
        ->and($asset->isActiveContent())->toBeTrue();
});

it('refuses active content on a public placement rather than downgrading it', function (): void {
    ingest(
        UploadedFile::fake()->createWithContent('page.html', '<html><body>hi</body></html>'),
        Placement::resolve(visibility: Visibility::Public),
    );
})->throws(IngestRefused::class, 'which the browser would execute');

it('reads active content off the stored type rather than off a column', function (): void {
    $asset = ingest(pngUpload());

    expect($asset->isActiveContent())->toBeFalse();

    $asset->mime_type = 'text/html';

    expect($asset->isActiveContent())->toBeTrue();
});

it('excludes blocked types from an offer query without touching the stored row', function (): void {
    $allowed = ingest(pngUpload());

    // A stored asset whose type the application blocked afterwards: still in
    // the library, never offered.
    $blocked = MediaAsset::query()->find($allowed->id)->replicate();
    $blocked->ulid = (string) Str::ulid();
    $blocked->object_key = 'media/blocked.php';
    $blocked->extension = 'php';
    $blocked->mime_type = 'application/x-httpd-php';
    $blocked->save();

    expect(MediaAsset::query()->excludingBlockedTypes()->pluck('id')->all())->toBe([$allowed->id])
        ->and(MediaAsset::query()->count())->toBe(2)
        ->and($blocked->fresh())->not->toBeNull();
});

it('never rejects, hides or deletes an asset when the rules tighten', function (): void {
    $asset = ingest(UploadedFile::fake()->createWithContent('notes.txt', 'plain words'));

    config()->set('media-library.blocked_types', ['txt']);
    config()->set('media-library.max_upload_size', 1);

    expect($asset->fresh())->not->toBeNull();
    Storage::disk('media')->assertExists($asset->object_key);
});

it('writes a saving disposition onto stored active content', function (): void {
    $headers = app(IngestService::class);

    expect($headers->storedHeaders('text/html'))->toHaveKey('ContentDisposition', 'attachment')
        ->and($headers->storedHeaders('image/png'))->not->toHaveKey('ContentDisposition');
});
