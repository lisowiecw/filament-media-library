<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Workbench\App\Models\Article;

it('reads a field out of the loaded relation without querying', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one, $two);

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);

    $ids = null;

    expect(queriesFor(function () use ($loaded, &$ids): void {
        $ids = $loaded->media('gallery')->pluck('id')->all();
    }))->toBe(0)
        ->and($ids)->toBe([$one->id, $two->id]);
});

it('keeps the relation order rule when reading in memory', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one, $two);

    MediaAttachment::query()->forField($host, 'gallery')
        ->where('media_asset_id', $two->id)->update(['order' => -1]);

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);

    expect($loaded->media('gallery')->pluck('id')->all())->toBe([$two->id, $one->id]);
});

it('drops a soft-deleted asset on the in-memory path too', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one, $two);

    $one->delete();

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);

    expect($loaded->media('gallery')->pluck('id')->all())->toBe([$two->id]);
});

it('reads only the field asked for', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one);
    attachToField($host, 'thumbnail', $two);

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);

    expect($loaded->media('thumbnail')->pluck('id')->all())->toBe([$two->id])
        ->and($loaded->media('gallery')->pluck('id')->all())->toBe([$one->id]);
});

it('trusts a hand-written eager load for every field', function (): void {
    $host = article();
    attachToField($host, 'gallery', libraryAsset());

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);

    expect(queriesFor(fn () => $loaded->media('thumbnail')))->toBe(0);
});

it('will not answer for a field the loaded relation does not cover', function (): void {
    $host = article();
    $asset = libraryAsset();
    attachToField($host, 'gallery', $asset);

    $loaded = Article::query()->findOrFail($host->id);
    $loaded->setRelation('mediaAttachments', $loaded->mediaAttachments()
        ->forField($loaded, 'thumbnail')->with('asset')->get());
    $loaded->mediaFieldsLoaded('thumbnail');

    expect($loaded->media('gallery')->pluck('id')->all())->toBe([$asset->id]);
});

it('queries again once the relation is unset', function (): void {
    $host = article();
    $asset = libraryAsset();
    attachToField($host, 'gallery', $asset);

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);
    $loaded->media('gallery');
    $loaded->unsetRelation('mediaAttachments');

    attachToField($host, 'gallery', libraryAsset());

    expect($loaded->media('gallery'))->toHaveCount(2);
});

it('queries when an attachment on the field has no loaded asset', function (): void {
    $host = article();
    $asset = libraryAsset();
    attachToField($host, 'gallery', $asset);

    $loaded = Article::query()->with('mediaAttachments')->findOrFail($host->id);

    expect($loaded->media('gallery')->pluck('id')->all())->toBe([$asset->id])
        ->and(queriesFor(fn () => $loaded->media('gallery')))->toBe(0);
});

it('fills the cache so a second read of the same field is free', function (): void {
    $host = article();
    attachToField($host, 'gallery', libraryAsset());

    $fresh = Article::query()->findOrFail($host->id);

    expect(queriesFor(function () use ($fresh): void {
        $fresh->media('gallery');
        $fresh->media('gallery');
    }))->toBe(2);
});

it('reads an empty field out of the cache the second time', function (): void {
    $host = article();

    $fresh = Article::query()->findOrFail($host->id);
    $fresh->media('gallery');

    expect(queriesFor(fn () => $fresh->media('gallery')))->toBe(0);
});

it('holds the union of two field loads without crossing them', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one);
    attachToField($host, 'thumbnail', $two);

    $fresh = Article::query()->findOrFail($host->id);

    expect($fresh->media('gallery')->pluck('id')->all())->toBe([$one->id])
        ->and($fresh->media('thumbnail')->pluck('id')->all())->toBe([$two->id])
        ->and(queriesFor(fn () => $fresh->media('gallery')))->toBe(0);
});

it('clears the cache when the host detaches an asset', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one, $two);

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);
    $loaded->media('gallery');

    $loaded->detachMedia('gallery', $one);

    expect($loaded->media('gallery')->pluck('id')->all())->toBe([$two->id]);
});

it('clears the cache when a reconcile writes through the host it was handed', function (): void {
    $host = article();
    [$one, $two] = [libraryAsset(), libraryAsset()];
    attachToField($host, 'gallery', $one);

    $loaded = Article::query()->with('mediaAttachments.asset')->findOrFail($host->id);
    $loaded->media('gallery');

    app(AttachmentReconciler::class)->reconcile($loaded, 'gallery', [$one->id, $two->id]);

    expect($loaded->media('gallery')->pluck('id')->all())->toBe([$one->id, $two->id]);
});

it('costs the same number of queries however many hosts are read', function (): void {
    foreach (range(1, 5) as $index) {
        attachToField(article('Post '.$index), 'gallery', libraryAsset());
    }

    $hosts = Article::query()->with('mediaAttachments.asset')->get();

    expect(queriesFor(function () use ($hosts): void {
        foreach ($hosts as $each) {
            $each->media('gallery');
        }
    }))->toBe(0);
});
