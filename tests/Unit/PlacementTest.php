<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Ingest\Placement;

it('defaults to the application disk, the media prefix and private visibility', function (): void {
    config()->set('media-library.disk', null);

    $placement = Placement::resolve();

    expect($placement->disk)->toBe(config('filesystems.default'))
        ->and($placement->directory)->toBe('media')
        ->and($placement->visibility)->toBe('private');
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
        visibility: 'public',
    );

    expect($placement->disk)->toBe('field')
        ->and($placement->directory)->toBe('posts/covers')
        ->and($placement->visibility)->toBe('public');
});

it('trims stray slashes off the directory prefix', function (): void {
    expect(Placement::resolve(directory: '/posts/covers/')->directory)->toBe('posts/covers');
});

it('treats an empty directory as the bucket root', function (): void {
    expect(Placement::resolve(directory: '')->directory)->toBe('');
});
