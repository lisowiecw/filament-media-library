<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Import\MimeLadder;
use Mockery\MockInterface;

/**
 * A disk that answers the two calls the ladder makes and nothing else.
 */
function ladderDisk(string|false $storedType, string $bytes = 'plain words'): FilesystemAdapter
{
    /** @var FilesystemAdapter&MockInterface $disk */
    $disk = Mockery::mock(FilesystemAdapter::class);

    $disk->shouldReceive('mimeType')->andReturn($storedType);
    $disk->shouldReceive('readStream')->andReturnUsing(function () use ($bytes) {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    });

    return $disk;
}

it('takes the stored header first', function (): void {
    $ladder = MimeLadder::resolve(ladderDisk('image/png'), 'avatars/a.png', 'png');

    expect($ladder->mimeType)->toBe('image/png')
        ->and($ladder->source)->toBe(MimeSource::Header);
});

it('falls to the extension when the disk has no header to give', function (): void {
    $ladder = MimeLadder::resolve(ladderDisk(false), 'avatars/a.png', 'png');

    expect($ladder->mimeType)->toBe('image/png')
        ->and($ladder->source)->toBe(MimeSource::Extension);
});

it('sniffs the bytes only when asked, and before the extension', function (): void {
    $ladder = MimeLadder::resolve(ladderDisk(false), 'avatars/a.png', 'png', sniff: true);

    expect($ladder->mimeType)->toBe('text/plain')
        ->and($ladder->source)->toBe(MimeSource::Sniffed);
});

it('records unknown rather than guessing a type', function (): void {
    $ladder = MimeLadder::resolve(ladderDisk(false), 'avatars/nameless', null);

    expect($ladder->mimeType)->toBeNull()
        ->and($ladder->source)->toBe(MimeSource::Unknown);
});
