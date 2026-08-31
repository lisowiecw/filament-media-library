<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The seam a rich text editor's `saveUploadedFileAttachmentsUsing()` wires to:
 * ingest the file, record an External reference so nothing sweeps it, and hand
 * back a URL that goes into saved HTML.
 */
function editorPlacement(): Placement
{
    return new Placement('media', 'editor', Visibility::Public);
}

function editorUpload(string $reference = 'editor:body'): MediaAsset
{
    $asset = app(IngestService::class)->ingest(pngUpload('inline.png'), editorPlacement());

    $asset->attachments()->createExternal($reference, 'Post body');

    return $asset;
}

it('ingests an editor upload as an ordinary Media Asset', function (): void {
    $asset = editorUpload();

    expect($asset->exists)->toBeTrue()
        ->and($asset->visibility)->toBe(Visibility::Public)
        ->and($asset->display_name)->toBe('inline')
        ->and(Storage::disk('media')->exists($asset->object_key))->toBeTrue();
});

it('resolves a public editor upload to a URL with no expiry to outlive', function (): void {
    config()->set('filesystems.disks.media.url', 'https://cdn.test/files');
    Storage::forgetDisk('media');

    $asset = editorUpload();

    // What the recipe is buying by requiring public placement: the same file
    // on a private placement resolves to a signed URL, which is what would rot
    // once it is saved inside the body's HTML.
    $private = app(IngestService::class)->ingest(
        pngUpload('inline.png'),
        new Placement('media', 'editor', Visibility::Private),
    );

    expect($asset->url())->toBe('https://cdn.test/files/'.$asset->object_key)
        ->and($asset->url())->not->toContain('signature')
        ->and($private->url())->toContain('signature');
});

it('counts an editor upload as used, so a delete is blocked', function (): void {
    $asset = editorUpload();

    expect(fn () => app(AssetLifecycle::class)->delete($asset))
        ->toThrow(DeleteBlocked::class);
});

it('frees the asset for review once the reference is revoked', function (): void {
    $asset = editorUpload();

    $asset->attachments()->revokeExternal('editor:body');

    expect($asset->attachments()->count())->toBe(0)
        ->and(fn () => app(AssetLifecycle::class)->delete($asset))->not->toThrow(DeleteBlocked::class);
});

it('applies the ingest floor on the editor path, denylist included', function (): void {
    expect(fn () => app(IngestService::class)->ingest(
        UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
        editorPlacement(),
    ))->toThrow(IngestRefused::class);
});

it('refuses active content on the editor path, since the placement is public', function (): void {
    expect(fn () => app(IngestService::class)->ingest(
        UploadedFile::fake()->createWithContent('body.html', '<!DOCTYPE html><html><body><p>hi</p></body></html>'),
        editorPlacement(),
    ))->toThrow(IngestRefused::class);
});

it('takes the configured placement when the caller names none', function (): void {
    config()->set('media-library.visibility', 'public');

    $asset = app(IngestService::class)->ingest(pngUpload('inline.png'));

    expect($asset->visibility)->toBe(Visibility::Public);
});
