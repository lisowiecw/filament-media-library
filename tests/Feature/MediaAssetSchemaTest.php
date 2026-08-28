<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Writes past the model so the database's own constraints are what answers.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertAssetRow(array $overrides = []): void
{
    DB::table('media_assets')->insert(array_merge([
        'ulid' => (string) Str::ulid(),
        'display_name' => 'Holiday photo',
        'mime_source' => 'sniffed',
        'disk' => 'media',
        'object_key' => 'media/raw-'.Str::random(8).'.jpg',
        'visibility' => 'private',
        'source' => 'upload',
    ], $overrides));
}

it('reads naming, type, storage and provenance back off a created asset', function (): void {
    $asset = makeAsset([
        'alt' => 'A beach',
        'import_source' => 'articles.hero_path',
        'uploaded_by' => '7',
        'tenant_id' => 'acme',
        'blurhash' => 'LEHV6nWB2yk8',
        'source' => MediaSource::Import,
    ])->fresh();

    expect($asset->display_name)->toBe('Holiday photo')
        ->and($asset->original_client_filename)->toBe('holiday photo.jpg')
        ->and($asset->extension)->toBe('jpg')
        ->and($asset->alt)->toBe('A beach')
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->mime_source)->toBe(MimeSource::Sniffed)
        ->and($asset->size)->toBe(2048)
        ->and($asset->disk)->toBe('media')
        ->and($asset->object_key)->toBe('media/holiday-photo.jpg')
        ->and($asset->visibility)->toBe('private')
        ->and($asset->source)->toBe(MediaSource::Import)
        ->and($asset->import_source)->toBe('articles.hero_path')
        ->and($asset->uploaded_by)->toBe('7')
        ->and($asset->tenant_id)->toBe('acme')
        ->and($asset->blurhash)->toBe('LEHV6nWB2yk8');
});

it('stamps a ulid on creation', function (): void {
    expect(makeAsset()->ulid)->toHaveLength(26);
});

it('soft deletes rather than removing the row', function (): void {
    $asset = makeAsset();
    $asset->delete();

    expect(MediaAsset::query()->count())->toBe(0)
        ->and(MediaAsset::withTrashed()->count())->toBe(1);
});

it('declares attachments and derivatives relations', function (): void {
    $asset = makeAsset();

    expect($asset->attachments()->getForeignKeyName())->toBe('media_asset_id')
        ->and($asset->derivatives()->getForeignKeyName())->toBe('media_asset_id');
});

it('refuses a second asset on the same disk and object key', function (): void {
    makeAsset();

    expect(fn () => makeAsset(['display_name' => 'Another']))
        ->toThrow(QueryException::class);
});

it('allows the same object key on a different disk', function (): void {
    makeAsset();
    makeAsset(['disk' => 'archive']);

    expect(MediaAsset::query()->count())->toBe(2);
});

it('refuses a mime source outside the ladder', function (): void {
    expect(fn () => insertAssetRow(['mime_source' => 'guessed']))
        ->toThrow(QueryException::class);
});

it('accepts every rung of the mime source ladder', function (MimeSource $mimeSource): void {
    $asset = makeAsset([
        'mime_source' => $mimeSource,
        'object_key' => 'media/'.$mimeSource->value.'.jpg',
    ]);

    expect($asset->fresh()->mime_source)->toBe($mimeSource);
})->with(MimeSource::cases());

it('accepts either origin a media asset can have', function (MediaSource $source): void {
    $asset = makeAsset([
        'source' => $source,
        'object_key' => 'media/'.$source->value.'.jpg',
    ]);

    expect($asset->fresh()->source)->toBe($source);
})->with(MediaSource::cases());

it('refuses a source that is neither upload nor import', function (): void {
    expect(fn () => insertAssetRow(['source' => 'sync']))
        ->toThrow(QueryException::class);
});

it('refuses an asset with no source at all', function (): void {
    expect(fn () => insertAssetRow(['source' => null]))
        ->toThrow(QueryException::class);
});

it('refuses an asset with no display name', function (): void {
    expect(fn () => insertAssetRow(['display_name' => null]))
        ->toThrow(QueryException::class);
});

it('indexes tenant id', function (): void {
    $indexes = collect(Schema::getIndexes('media_assets'))
        ->pluck('columns');

    expect($indexes)->toContainEqual(['tenant_id'])
        ->and($indexes)->toContainEqual(['disk', 'object_key']);
});

it('fixes the table name with no prefix knob', function (): void {
    expect((new MediaAsset)->getTable())->toBe('media_assets')
        ->and(DB::getTablePrefix())->toBe('');
});
