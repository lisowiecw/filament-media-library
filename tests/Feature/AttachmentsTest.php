<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Workbench\App\Models\Article;

/**
 * @param  list<int|string>  $ids
 */
function reconcile(Article $host, string $field, array $ids): void
{
    app(AttachmentReconciler::class)->reconcile($host, $field, $ids);
}

/**
 * @return list<int>
 */
function attachedIds(Article $host, string $field): array
{
    return $host->media($field)->pluck('id')->all();
}

it('attaches assets in the order the list gives them', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$two->id, $one->id]);

    expect(attachedIds($host, 'gallery'))->toBe([$two->id, $one->id])
        ->and(MediaAttachment::query()->forField($host, 'gallery')->orderBy('order')->pluck('order')->all())->toBe([0, 1]);
});

it('detaches what the list no longer holds and attaches what is new', function (): void {
    $host = article();
    [$one, $two, $three] = [libraryAsset(), libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$one->id, $two->id]);
    reconcile($host, 'gallery', [$two->id, $three->id]);

    expect(attachedIds($host, 'gallery'))->toBe([$two->id, $three->id]);
});

it('keeps attachment identity and created_at when reordering rather than reinserting', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$one->id, $two->id]);

    $before = MediaAttachment::query()->forField($host, 'gallery')->orderBy('order')->get()
        ->mapWithKeys(fn (MediaAttachment $row): array => [$row->media_asset_id => [$row->id, $row->created_at]]);

    $this->travel(1)->minutes();

    reconcile($host, 'gallery', [$two->id, $one->id]);

    $after = MediaAttachment::query()->forField($host, 'gallery')->orderBy('order')->get();

    expect($after->pluck('media_asset_id')->all())->toBe([$two->id, $one->id]);

    foreach ($after as $row) {
        [$id, $createdAt] = $before[$row->media_asset_id];

        expect($row->id)->toBe($id)
            ->and($row->created_at->equalTo($createdAt))->toBeTrue();
    }
});

it('writes nothing when the list already matches', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$one->id, $two->id]);

    $touched = false;
    DB::listen(function ($query) use (&$touched): void {
        if (preg_match('/^(insert|update|delete)/i', $query->sql) === 1) {
            $touched = true;
        }
    });

    reconcile($host, 'gallery', [$one->id, $two->id]);

    expect($touched)->toBeFalse();
});

it('rewrites order only on the rows whose position changed', function (): void {
    $host = article();
    [$one, $two, $three] = [libraryAsset(), libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$one->id, $two->id, $three->id]);

    $updates = 0;
    DB::listen(function ($query) use (&$updates): void {
        if (preg_match('/^update/i', $query->sql) === 1) {
            $updates++;
        }
    });

    reconcile($host, 'gallery', [$one->id, $three->id, $two->id]);

    expect($updates)->toBe(2);
});

it('holds one asset once in a field context however often the list names it', function (): void {
    $host = article();
    $asset = libraryAsset();

    reconcile($host, 'gallery', [$asset->id, $asset->id]);

    expect(attachedIds($host, 'gallery'))->toBe([$asset->id]);
});

it('refuses the same asset twice in one host and field context', function (): void {
    $host = article();
    $asset = libraryAsset();

    reconcile($host, 'gallery', [$asset->id]);

    $row = [
        'media_asset_id' => $asset->id,
        'host_type' => $host->getMorphClass(),
        'host_id' => $host->getKey(),
        'field_name' => 'gallery',
        'order' => 1,
    ];

    expect(fn () => DB::table('media_attachments')->insert($row))->toThrow(QueryException::class);
});

it('keeps field contexts and hosts apart', function (): void {
    [$host, $other] = [article(), article('Another post')];
    $asset = libraryAsset();

    reconcile($host, 'gallery', [$asset->id]);
    reconcile($host, 'cover_image', [$asset->id]);
    reconcile($other, 'gallery', [$asset->id]);

    expect(MediaAttachment::query()->count())->toBe(3)
        ->and(attachedIds($host, 'cover_image'))->toBe([$asset->id]);
});

it('leaves the asset alone when detaching', function (): void {
    $host = article();
    $asset = libraryAsset();

    reconcile($host, 'gallery', [$asset->id]);
    reconcile($host, 'gallery', []);

    expect(MediaAttachment::query()->count())->toBe(0)
        ->and(MediaAsset::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and($asset->fresh()->deleted_at)->toBeNull();
});

it('excludes soft-deleted assets from what a host reads back', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$one->id, $two->id]);
    $one->delete();

    expect(attachedIds($host, 'gallery'))->toBe([$two->id]);
});

it('excludes rows with a null host from every field-context query', function (): void {
    $host = article();
    $asset = libraryAsset();

    MediaAttachment::query()->create([
        'media_asset_id' => $asset->id,
        'reference_identifier' => 'newsletter-2026-08',
        'reference_label' => 'Campaign #412',
    ]);

    expect(attachedIds($host, 'gallery'))->toBe([])
        ->and(MediaAttachment::query()->forField($host, 'gallery')->count())->toBe(0)
        ->and($asset->attachments()->count())->toBe(1);
});

it('refuses a list holding something that is not an asset id', function (): void {
    expect(fn () => reconcile(article(), 'gallery', ['not-an-id']))
        ->toThrow(InvalidArgumentException::class);
});

it('reads the first attachment of a field back, or null', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];

    reconcile($host, 'gallery', [$two->id, $one->id]);

    expect($host->firstMedia('gallery')?->id)->toBe($two->id)
        ->and($host->firstMedia('cover_image'))->toBeNull();
});
