<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Jobs\PurgeStoredObjects;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Lifecycle\UsageList;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

function externalReference(MediaAsset $asset, string $label = 'Spring campaign'): MediaAttachment
{
    return MediaAttachment::query()->create([
        'media_asset_id' => $asset->id,
        'reference_identifier' => 'campaign:17',
        'reference_label' => $label,
    ]);
}

/**
 * Run queued work inline, for the tests that are about what the job does
 * rather than about it being queued.
 */
function runQueuedWorkInline(): void
{
    config()->set('queue.default', 'sync');
}

function lifecycle(): AssetLifecycle
{
    return app(AssetLifecycle::class);
}

describe('detach', function (): void {
    it('touches only the attachment row', function (): void {
        $host = article();
        $asset = storedAsset();
        attach($host, $asset);
        readyDerivative($asset);

        $host->detachMedia('cover_image', $asset);

        expect($host->media('cover_image'))->toHaveCount(0)
            ->and(MediaAsset::query()->find($asset->id))->not->toBeNull()
            ->and($asset->derivatives()->count())->toBe(1)
            ->and(Storage::disk($asset->disk)->exists($asset->object_key))->toBeTrue();
    });

    it('leaves the same asset attached elsewhere alone', function (): void {
        [$one, $two] = [article('One'), article('Two')];
        $asset = storedAsset();
        attach($one, $asset);
        attach($two, $asset);

        $one->detachMedia('cover_image', $asset);

        expect($two->media('cover_image'))->toHaveCount(1);
    });
});

describe('delete', function (): void {
    it('soft-deletes the record and queues removal of the backing object', function (): void {
        Queue::fake();
        $asset = storedAsset();

        lifecycle()->delete($asset);

        Queue::assertPushed(PurgeStoredObjects::class);
        expect(MediaAsset::query()->find($asset->id))->toBeNull()
            ->and(MediaAsset::withTrashed()->find($asset->id))->not->toBeNull();
    });

    it('removes the object and its renderings when the job runs', function (): void {
        runQueuedWorkInline();
        $asset = storedAsset();
        $derivative = readyDerivative($asset);

        lifecycle()->delete($asset);

        expect(Storage::disk($asset->disk)->exists($asset->object_key))->toBeFalse()
            ->and(Storage::disk($asset->disk)->exists($derivative->object_key))->toBeFalse();
    });

    it('queues the derivatives for removal alongside the object and keeps no rows', function (): void {
        Queue::fake();
        $asset = storedAsset();
        $derivative = readyDerivative($asset);

        lifecycle()->delete($asset);

        Queue::assertPushed(PurgeStoredObjects::class, fn (PurgeStoredObjects $job): bool => $job->keys === [
            $asset->object_key,
            $derivative->object_key,
        ]);
        expect(MediaDerivative::query()->count())->toBe(0);
    });

    it('uses standard queue retries rather than tracking failure itself', function (): void {
        expect((new PurgeStoredObjects('media', ['media/x.jpg']))->tries)->toBeGreaterThan(1)
            ->and(method_exists(PurgeStoredObjects::class, 'failed'))->toBeFalse();
    });

    it('is blocked when the asset is attached anywhere, naming the usage', function (): void {
        $asset = storedAsset();
        attach(article('A post'), $asset);

        expect(fn () => lifecycle()->delete($asset))
            ->toThrow(DeleteBlocked::class);

        expect(MediaAsset::query()->find($asset->id))->not->toBeNull()
            ->and(Storage::disk($asset->disk)->exists($asset->object_key))->toBeTrue();
    });

    it('is blocked by an external reference too', function (): void {
        $asset = storedAsset();
        externalReference($asset);

        expect(fn () => lifecycle()->delete($asset))->toThrow(DeleteBlocked::class);
    });

    it('carries the usage list on the block, so the caller can show it', function (): void {
        $asset = storedAsset();
        attach(article('A post'), $asset);
        externalReference($asset);

        $blocked = null;

        try {
            lifecycle()->delete($asset);
        } catch (DeleteBlocked $exception) {
            $blocked = $exception;
        }

        expect($blocked?->usage)->toHaveCount(2)
            ->and($blocked?->usage[0]->label)->toContain('A post')
            ->and($blocked?->usage[1]->label)->toBe('Spring campaign');
    });

    it('goes through when forced, taking the attachment rows with it', function (): void {
        runQueuedWorkInline();
        $asset = storedAsset();
        attach(article('A post'), $asset);

        lifecycle()->delete($asset, force: true);

        expect(MediaAsset::withTrashed()->find($asset->id))->toBeNull()
            ->and(MediaAttachment::query()->count())->toBe(0)
            ->and(Storage::disk($asset->disk)->exists($asset->object_key))->toBeFalse();
    });

    it('queues removal for a force delete as well', function (): void {
        Queue::fake();
        $asset = storedAsset();
        readyDerivative($asset);

        lifecycle()->delete($asset, force: true);

        Queue::assertPushed(PurgeStoredObjects::class);
    });
});

describe('restore', function (): void {
    it('regenerates derivatives lazily rather than resurrecting them', function (): void {
        $asset = storedAsset();
        readyDerivative($asset);

        lifecycle()->delete($asset);
        lifecycle()->restore($trashed = MediaAsset::withTrashed()->findOrFail($asset->id));

        expect($trashed->trashed())->toBeFalse()
            ->and(MediaDerivative::query()->count())->toBe(0);
    });
});

describe('the usage list', function (): void {
    it('names the host model instance and its field context', function (): void {
        $asset = storedAsset();
        attach(article('A post'), $asset);

        $entry = UsageList::for($asset)[0];

        expect($entry->field)->toBe('cover_image')
            ->and($entry->label)->toContain('A post')
            ->and($entry->isExternal)->toBeFalse();
    });

    it('reads an external reference by its own label and no field context', function (): void {
        $asset = storedAsset();
        externalReference($asset);

        $entry = UsageList::for($asset)[0];

        expect($entry->label)->toBe('Spring campaign')
            ->and($entry->field)->toBeNull()
            ->and($entry->isExternal)->toBeTrue();
    });

    it('says an attachment whose host row is gone is still a use', function (): void {
        $asset = storedAsset();
        $host = article('Vanished');
        attach($host, $asset);
        $host->delete();

        expect(UsageList::for($asset))->toHaveCount(1);
    });
});

describe('the unattached report', function (): void {
    it('lists assets unattached for longer than the grace period', function (): void {
        $old = storedAsset();
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $fresh = storedAsset();
        $used = storedAsset();
        $used->forceFill(['created_at' => now()->subDays(40)])->save();
        attach(article(), $used);

        $this->artisan('media:unattached-assets')
            ->expectsOutputToContain($old->ulid)
            ->doesntExpectOutputToContain($fresh->ulid)
            ->doesntExpectOutputToContain($used->ulid)
            ->assertSuccessful();
    });

    it('deletes nothing', function (): void {
        $asset = storedAsset();
        $asset->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->artisan('media:unattached-assets')->assertSuccessful();

        expect(MediaAsset::query()->count())->toBe(1)
            ->and(Storage::disk($asset->disk)->exists($asset->object_key))->toBeTrue();
    });

    it('takes the grace period from config and from the option', function (): void {
        $asset = storedAsset();
        $asset->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('media:unattached-assets')->doesntExpectOutputToContain($asset->ulid);

        config()->set('media-library.unattached_grace_days', 5);
        $this->artisan('media:unattached-assets')->expectsOutputToContain($asset->ulid);

        $this->artisan('media:unattached-assets', ['--days' => 20])->doesntExpectOutputToContain($asset->ulid);
    });

    it('refuses a grace period that is not a whole number of days', function (): void {
        $this->artisan('media:unattached-assets', ['--days' => 'soon'])->assertFailed();
    });
});
