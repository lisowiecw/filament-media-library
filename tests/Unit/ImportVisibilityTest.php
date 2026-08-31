<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Import\ImportVisibility;

it('takes the operator assertion over anything the disk would say', function (): void {
    Storage::disk('media')->put('a.txt', 'bytes', 'private');

    expect(ImportVisibility::resolve('media', 'a.txt', Visibility::Public))->toBe(Visibility::Public);
});

it('reads a local disk, where visibility is a real file mode', function (): void {
    Storage::disk('media')->put('private.txt', 'bytes', 'private');
    Storage::disk('media')->put('public.txt', 'bytes', 'public');

    expect(ImportVisibility::resolve('media', 'private.txt', null))->toBe(Visibility::Private)
        ->and(ImportVisibility::resolve('media', 'public.txt', null))->toBe(Visibility::Public);
});

it('never asks an s3-driver disk, which may not answer the call at all', function (): void {
    // A disk with no credentials and no driver package behind it: resolving it
    // at all would throw, which is what makes this an honest assertion that
    // the visibility read never happens.
    config()->set('filesystems.disks.legacy-r2', [
        'driver' => 's3',
        'bucket' => 'legacy',
        'endpoint' => 'https://account.r2.cloudflarestorage.com',
        'visibility' => 'public',
    ]);

    expect(ImportVisibility::resolve('legacy-r2', 'avatars/a.jpg', null))->toBe(Visibility::Public);
});

it('falls back to private where nothing declares anything', function (): void {
    config()->set('filesystems.disks.legacy-r2', ['driver' => 's3', 'bucket' => 'legacy']);

    expect(ImportVisibility::resolve('legacy-r2', 'avatars/a.jpg', null))->toBe(Visibility::Private);
});

it('falls to private when the disk is configured with something visibility is not', function (): void {
    config()->set('filesystems.disks.odd', ['driver' => 's3', 'visibility' => 'semi-public']);

    expect(ImportVisibility::resolve('odd', 'legacy/one.txt', null))->toBe(Visibility::Private);
});
