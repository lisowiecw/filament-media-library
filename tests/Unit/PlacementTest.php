<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\PlacementMisconfigured;
use Lisowiecw\MediaLibrary\Ingest\Placement;

it('defaults to the application disk, the media prefix and private visibility', function (): void {
    config()->set('media-library.disk', null);

    $placement = Placement::resolve();

    expect($placement->disk)->toBe(config('filesystems.default'))
        ->and($placement->directory)->toBe('media')
        ->and($placement->visibility)->toBe(Visibility::Private);
});

it('prefers the package disk over the application default', function (): void {
    config()->set('media-library.disk', 'packaged');

    expect(Placement::resolve()->disk)->toBe('packaged');
});

it('prefers field configuration over package configuration', function (): void {
    config()->set('media-library.disk', 'packaged');

    $placement = Placement::resolve(
        disk: 'field',
        directory: 'posts/covers',
        visibility: Visibility::Public,
    );

    expect($placement->disk)->toBe('field')
        ->and($placement->directory)->toBe('posts/covers')
        ->and($placement->visibility)->toBe(Visibility::Public);
});

it('trims stray slashes off the directory prefix', function (): void {
    expect(Placement::resolve(directory: '/posts/covers/')->directory)->toBe('posts/covers');
});

it('treats an empty directory as the bucket root', function (): void {
    expect(Placement::resolve(directory: '')->directory)->toBe('');
});

/**
 * The two-bucket deployment, stated once.
 */
function pairedDisks(): void
{
    config()->set('media-library.public_disk', 'r2-public');
    config()->set('media-library.private_disk', 'r2-private');
}

it('sends a public field to the public disk of the configured pair', function (): void {
    pairedDisks();

    expect(Placement::resolve(visibility: Visibility::Public)->disk)->toBe('r2-public');
});

it('sends a private field to the private disk of the configured pair', function (): void {
    pairedDisks();

    expect(Placement::resolve(visibility: Visibility::Private)->disk)->toBe('r2-private');
});

it('pairs off the configured default visibility when the field names none', function (): void {
    config()->set('media-library.visibility', 'public');
    pairedDisks();

    expect(Placement::resolve()->disk)->toBe('r2-public');
});

it('lets a field name a disk directly over the pair', function (): void {
    config()->set('media-library.public_disk', 'r2-public');

    expect(Placement::resolve(disk: 'field', visibility: Visibility::Public)->disk)->toBe('field');
});

it('falls through the pair to the package disk when only the other half is set', function (): void {
    config()->set('media-library.disk', 'packaged');
    config()->set('media-library.public_disk', 'r2-public');
    config()->set('media-library.private_disk', null);

    expect(Placement::resolve(visibility: Visibility::Private)->disk)->toBe('packaged');
});

it('leaves the unpaired resolution untouched when neither key is set', function (): void {
    config()->set('media-library.disk', 'packaged');

    expect(Placement::resolve(visibility: Visibility::Public)->disk)->toBe('packaged')
        ->and(Placement::resolve(visibility: Visibility::Private)->disk)->toBe('packaged');
});

/**
 * The guard reads the declaration, so a disk has to be declared to be guarded.
 */
function declareDisk(string $name, ?string $url = null): void
{
    config()->set('filesystems.disks.'.$name, [
        'driver' => 's3',
        'bucket' => $name,
        'url' => $url,
    ]);
}

it('refuses a public placement on a disk that exposes no public URL', function (): void {
    declareDisk('r2-private');

    Placement::resolve(disk: 'r2-private', visibility: Visibility::Public, field: 'cover_image');
})->throws(PlacementMisconfigured::class, 'The media field "cover_image" declares public visibility on disk "r2-private"');

it('refuses a private placement on the configured public disk', function (): void {
    declareDisk('r2-public', 'https://media.example.com');
    config()->set('media-library.public_disk', 'r2-public');

    Placement::resolve(disk: 'r2-public', visibility: Visibility::Private, field: 'attachment');
})->throws(PlacementMisconfigured::class, 'The media field "attachment" declares private visibility on disk "r2-public"');

it('names the placement rather than a field when no field was given', function (): void {
    declareDisk('r2-private');

    Placement::resolve(disk: 'r2-private', visibility: Visibility::Public);
})->throws(PlacementMisconfigured::class, 'A media placement declares public visibility');

it('allows the pair that can deliver what it promises', function (): void {
    declareDisk('r2-public', 'https://media.example.com');
    declareDisk('r2-private');
    config()->set('media-library.public_disk', 'r2-public');
    config()->set('media-library.private_disk', 'r2-private');

    expect(Placement::resolve(visibility: Visibility::Public)->disk)->toBe('r2-public')
        ->and(Placement::resolve(visibility: Visibility::Private)->disk)->toBe('r2-private');
});

it('leaves a disk the application never declared alone', function (): void {
    expect(Placement::resolve(disk: 'undeclared', visibility: Visibility::Public)->disk)->toBe('undeclared');
});

it('stands down entirely when the invariant is not enforced', function (): void {
    declareDisk('r2-private');
    config()->set('media-library.public_disk', 'r2-private');
    config()->set('media-library.enforce_disk_visibility', false);

    expect(Placement::resolve(disk: 'r2-private', visibility: Visibility::Public)->disk)->toBe('r2-private')
        ->and(Placement::resolve(disk: 'r2-private', visibility: Visibility::Private)->disk)->toBe('r2-private');
});

it('treats a blank public URL as no public URL at all', function (): void {
    declareDisk('r2-private', '');

    Placement::resolve(disk: 'r2-private', visibility: Visibility::Public);
})->throws(PlacementMisconfigured::class, 'exposes no public URL');

it('refuses a private placement on a disk the application declares public', function (): void {
    config()->set('filesystems.disks.stock-public', [
        'driver' => 'local',
        'url' => '/storage',
        'visibility' => 'public',
    ]);

    Placement::resolve(disk: 'stock-public', visibility: Visibility::Private, field: 'attachment');
})->throws(PlacementMisconfigured::class, 'declares private visibility on disk "stock-public"');
