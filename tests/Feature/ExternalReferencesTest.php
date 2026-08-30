<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;

it('writes an external reference with an identifier and a label', function (): void {
    $asset = libraryAsset();

    $reference = $asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412');

    expect($reference->reference_identifier)->toBe('newsletter-2026-08')
        ->and($reference->reference_label)->toBe('Campaign #412')
        ->and($reference->host_type)->toBeNull()
        ->and($reference->host_id)->toBeNull()
        ->and($reference->field_name)->toBeNull();
});

it('takes an identifier alone, and reads back under it', function (): void {
    $asset = libraryAsset();

    $reference = $asset->attachments()->createExternal('newsletter-2026-08');

    expect($reference->reference_label)->toBeNull()
        ->and(MediaAsset::find($asset->id)->attachments()->count())->toBe(1);
});

it('records one row per identifier, however often the same code runs', function (): void {
    $asset = libraryAsset();

    $asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412');
    $second = $asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412 (resend)');

    expect($asset->attachments()->count())->toBe(1)
        ->and($second->reference_label)->toBe('Campaign #412 (resend)');
});

it('leaves the label alone when a rerun names the identifier alone', function (): void {
    $asset = libraryAsset();

    $asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412');
    $asset->attachments()->createExternal('newsletter-2026-08');

    expect($asset->attachments()->sole()->reference_label)->toBe('Campaign #412');
});

it('refuses two rows for one identifier even when two runs race', function (): void {
    $asset = libraryAsset();
    $asset->attachments()->createExternal('newsletter-2026-08');

    expect(fn () => MediaAttachment::query()->create([
        'media_asset_id' => $asset->id,
        'reference_identifier' => 'newsletter-2026-08',
    ]))->toThrow(QueryException::class);
});

it('keeps the same identifier on two assets apart', function (): void {
    [$one, $two] = [libraryAsset(), libraryAsset()];

    $one->attachments()->createExternal('newsletter-2026-08');
    $two->attachments()->createExternal('newsletter-2026-08');

    expect($one->attachments()->count())->toBe(1)
        ->and($two->attachments()->count())->toBe(1);
});

it('revokes by identifier when the code that made it is gone', function (): void {
    $asset = libraryAsset();
    $asset->attachments()->createExternal('newsletter-2026-08');

    expect($asset->attachments()->revokeExternal('newsletter-2026-08'))->toBe(1)
        ->and($asset->attachments()->count())->toBe(0)
        ->and($asset->attachments()->revokeExternal('newsletter-2026-08'))->toBe(0);
});

it('never revokes a host model row through the panel', function (): void {
    $host = article();
    $asset = libraryAsset();
    app(AttachmentReconciler::class)->reconcile($host, 'cover', [$asset->getKey()]);

    $row = $asset->attachments()->sole();

    expect($asset->attachments()->revokeExternalRow($row->id))->toBe(0)
        ->and($asset->attachments()->count())->toBe(1);
});

it('revokes one external reference by the row it is', function (): void {
    $asset = libraryAsset();
    $reference = $asset->attachments()->createExternal('newsletter-2026-08');

    expect($asset->attachments()->revokeExternalRow($reference->id))->toBe(1)
        ->and($asset->attachments()->count())->toBe(0);
});

it('stamps the unattached clock when the last reference is revoked', function (): void {
    $asset = libraryAsset();
    $asset->attachments()->createExternal('newsletter-2026-08');

    $asset->attachments()->revokeExternal('newsletter-2026-08');

    expect($asset->fresh()->unattached_since)->not->toBeNull();
});

it('blocks a delete through the one mechanism every other use goes through', function (): void {
    $asset = libraryAsset();
    $asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412');

    expect(fn () => app(AssetLifecycle::class)->delete($asset))
        ->toThrow(DeleteBlocked::class);

    expect(MediaAsset::withTrashed()->whereKey($asset->id)->first()->trashed())->toBeFalse();
});

it('never reaches a host model reading its own field', function (): void {
    $host = article();
    $asset = libraryAsset();

    $asset->attachments()->createExternal('newsletter-2026-08');

    expect($host->media('cover')->all())->toBe([])
        ->and($host->firstMedia('cover'))->toBeNull()
        ->and(MediaAttachment::query()->forField($host, 'cover')->count())->toBe(0);
});
