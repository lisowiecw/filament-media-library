<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Mockery\MockInterface;

/**
 * Stand the `media` disk up as a mock that answers the two calls the ladder
 * makes and nothing else, so a test can say what the object store knows and
 * count what the run read. A faked local disk cannot: it detects a type from
 * the bytes on `mimeType()`, so the header rung would answer every time and no
 * test could tell a sniff from it.
 */
function resolverDisk(string|false $storedType, string $bytes = 'plain words', int $reads = 0): FilesystemAdapter
{
    /** @var FilesystemAdapter&MockInterface $disk */
    $disk = Mockery::mock(FilesystemAdapter::class);

    $disk->shouldReceive('mimeType')->andReturn($storedType);
    $disk->shouldReceive('readStream')->times($reads)->andReturnUsing(function () use ($bytes) {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    });

    Storage::set('media', $disk);

    return $disk;
}

/**
 * @param  array<string, mixed>  $options
 */
function runResolve(array $options = []): PendingCommand
{
    /** @var PendingCommand $command */
    $command = test()->artisan('media:resolve-mimes', $options);

    return $command;
}

it('re-runs the ladder over the extension rung by default', function (): void {
    resolverDisk('image/png');

    $named = makeAsset(['mime_type' => 'image/jpeg', 'mime_source' => MimeSource::Extension]);
    $sniffed = libraryAsset();

    runResolve()->assertSuccessful();

    expect($named->fresh()->mime_source)->toBe(MimeSource::Header)
        ->and($named->fresh()->mime_type)->toBe('image/png')
        ->and($sniffed->fresh()->mime_source)->toBe(MimeSource::Sniffed)
        ->and($sniffed->fresh()->mime_type)->toBe('image/jpeg');
});

it('selects any rung the operator names', function (): void {
    resolverDisk('image/png');

    $unknown = makeAsset(['mime_type' => null, 'mime_source' => MimeSource::Unknown]);

    runResolve(['--from' => 'unknown'])->assertSuccessful();

    expect($unknown->fresh()->mime_source)->toBe(MimeSource::Header)
        ->and($unknown->fresh()->mime_type)->toBe('image/png');
});

it('refuses a rung the ladder does not have', function (): void {
    makeAsset(['mime_source' => MimeSource::Extension]);

    runResolve(['--from' => 'guessed'])
        ->expectsOutputToContain('Unknown mime source "guessed"')
        ->assertFailed();
});

it('reads no bytes unless the operator asks for a sniff', function (): void {
    resolverDisk(false, reads: 0);

    $asset = makeAsset(['extension' => 'jpg', 'mime_source' => MimeSource::Extension]);

    runResolve()->assertSuccessful();

    expect($asset->fresh()->mime_source)->toBe(MimeSource::Extension)
        ->and($asset->fresh()->mime_type)->toBe('image/jpeg');
});

it('writes the type and the rung together when a sniff answers', function (): void {
    resolverDisk(false, reads: 1);

    $asset = makeAsset(['extension' => 'jpg', 'mime_source' => MimeSource::Extension]);

    runResolve(['--sniff' => true])->assertSuccessful();

    expect($asset->fresh()->mime_source)->toBe(MimeSource::Sniffed)
        ->and($asset->fresh()->mime_type)->toBe('text/plain');
});

it('buys the bytes even where the object already claims a type', function (): void {
    resolverDisk('image/png', reads: 1);

    $asset = makeAsset(['extension' => 'jpg', 'mime_source' => MimeSource::Extension]);

    runResolve(['--sniff' => true])->assertSuccessful();

    expect($asset->fresh()->mime_source)->toBe(MimeSource::Sniffed)
        ->and($asset->fresh()->mime_type)->toBe('text/plain');
});

it('leaves a row alone rather than demoting the rung it already holds', function (): void {
    resolverDisk(false);

    $asset = makeAsset([
        'extension' => null,
        'mime_type' => 'image/jpeg',
        'mime_source' => MimeSource::Extension,
    ]);

    runResolve()->assertSuccessful();

    expect($asset->fresh()->mime_source)->toBe(MimeSource::Extension)
        ->and($asset->fresh()->mime_type)->toBe('image/jpeg');
});

it('writes nothing on a dry run', function (): void {
    resolverDisk('image/png');

    $asset = makeAsset(['mime_source' => MimeSource::Extension]);

    runResolve(['--dry-run' => true])
        ->expectsOutputToContain('1 asset(s) would be rewritten')
        ->assertSuccessful();

    expect($asset->fresh()->mime_source)->toBe(MimeSource::Extension);
});

it('leaves a trashed asset out of the pass', function (): void {
    resolverDisk('image/png');

    $asset = makeAsset(['mime_source' => MimeSource::Extension]);
    $asset->delete();

    runResolve()->assertSuccessful();

    expect(MediaAsset::withTrashed()->sole()->mime_source)->toBe(MimeSource::Extension);
});
