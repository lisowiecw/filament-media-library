<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\User;

it('stores the bytes and returns a persisted asset', function (): void {
    $asset = ingest(pngUpload());

    expect($asset->exists)->toBeTrue()
        ->and($asset->disk)->toBe('media')
        ->and($asset->visibility)->toBe('private');

    Storage::disk('media')->assertExists($asset->object_key);
    expect($asset->size)->toBe(Storage::disk('media')->size($asset->object_key));
});

it('writes the object under the placement directory', function (): void {
    $asset = ingest(pngUpload(), Placement::resolve(directory: 'posts/covers'));

    expect($asset->object_key)->toStartWith('posts/covers/');
});

it('generates an opaque key that never carries the readable name', function (): void {
    $asset = ingest(pngUpload('Team photo, Berlin.png'));

    expect($asset->object_key)->not->toContain('Team')
        ->and($asset->object_key)->not->toContain('erlin')
        ->and($asset->object_key)->toBe('media/'.$asset->ulid.'.png');
});

it('gives two identical uploads distinct keys', function (): void {
    $first = ingest(pngUpload());
    $second = ingest(pngUpload());

    expect($first->object_key)->not->toBe($second->object_key);
});

it('records the readable name the person typed', function (): void {
    $asset = ingest(pngUpload('Team photo, Berlin.PNG'));

    expect($asset->display_name)->toBe('Team photo, Berlin')
        ->and($asset->original_client_filename)->toBe('Team photo, Berlin.PNG')
        ->and($asset->extension)->toBe('png');
});

it('never falls back to the client extension for the key', function (): void {
    $asset = ingest(UploadedFile::fake()->createWithContent('payload.weird', "\x00\x01binary\x02"));

    // The bytes sniff as octet-stream, so the key takes that type's own
    // extension rather than borrowing anything the client named.
    expect($asset->object_key)->toBe('media/'.$asset->ulid.'.bin')
        ->and($asset->extension)->toBe('weird');
});

it('sniffs the mime type from the bytes and says so', function (): void {
    $asset = ingest(pngUpload());

    expect($asset->mime_type)->toBe('image/png')
        ->and($asset->mime_source)->toBe(MimeSource::Sniffed);
});

it('takes the key extension from the sniffed bytes while the stored extension follows the client name', function (): void {
    $asset = ingest(UploadedFile::fake()->createWithContent('report.txt', 'plain words'));

    expect($asset->extension)->toBe('txt')
        ->and($asset->mime_type)->toBe('text/plain')
        ->and($asset->object_key)->toEndWith('.txt');

    // The client name and the bytes can still disagree inside one family, and
    // there the key follows the bytes while the row follows the name.
    $disagreeing = ingest(UploadedFile::fake()->createWithContent('rows.csv', "a,b\n1,2\n"));

    expect($disagreeing->extension)->toBe('csv')
        ->and($disagreeing->mime_type)->toBe('text/plain')
        ->and($disagreeing->object_key)->toEndWith('.txt');
});

it('writes stored headers on every upload, private included', function (string $visibility): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')
        ->once()
        ->with(Mockery::type('string'), Mockery::any(), Mockery::capture($options))
        ->andReturnTrue();
    $disk->shouldReceive('size')->andReturn(1024);

    Storage::shouldReceive('disk')->with('media')->andReturn($disk);

    ingest(pngUpload(), Placement::resolve(visibility: $visibility));

    expect($options)->toBe([
        'visibility' => $visibility,
        'ContentType' => 'image/png',
        'CacheControl' => 'public, max-age=31536000, immutable',
    ]);
})->with(['private', 'public']);

it('records the upload as its own source', function (): void {
    $asset = ingest(pngUpload());

    expect($asset->source)->toBe(MediaSource::Upload)
        ->and($asset->import_source)->toBeNull()
        ->and($asset->uploaded_by)->toBeNull();
});

it('stamps the authenticated uploader', function (): void {
    $user = new User(['id' => 7]);
    $this->be($user);

    expect(ingest(pngUpload())->uploaded_by)->toBe('7');
});

it('reports a name collision to the caller without blocking or overwriting', function (): void {
    $first = ingest(pngUpload('Annual   Report.png'));

    expect($first->nameCollided)->toBeFalse();

    $second = ingest(pngUpload('annual report.png'));

    expect($second->nameCollided)->toBeTrue()
        ->and($second->exists)->toBeTrue()
        ->and($second->id)->not->toBe($first->id)
        ->and(MediaAsset::query()->count())->toBe(2);

    Storage::disk('media')->assertExists($first->object_key);
});

it('does not report a collision across differing separators', function (): void {
    ingest(pngUpload('annual-report.png'));

    expect(ingest(pngUpload('annual report.png'))->nameCollided)->toBeFalse();
});

it('collides with a soft deleted asset, since the comparison is unfiltered', function (): void {
    ingest(pngUpload('Report.png'))->delete();

    expect(ingest(pngUpload('report.png'))->nameCollided)->toBeTrue();
});

it('folds case beyond ASCII when comparing names', function (): void {
    ingest(pngUpload('ОТЧЁТ.png'));

    expect(ingest(pngUpload('отчёт.png'))->nameCollided)->toBeTrue();
});
