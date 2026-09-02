<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Lisowiecw\MediaLibrary\Derivatives\DerivativeHealth;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Derivatives\RegenerationTargets;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Jobs\GenerateDerivative;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * An asset big enough in bytes that no card would ever paint it as itself.
 */
function abandonableAsset(): MediaAsset
{
    $asset = libraryAsset();

    $asset->forceFill(['size' => 900_000, 'visibility' => 'public'])->save();

    return $asset;
}

/**
 * A pending row last written at the given time, which is what a worker that
 * was killed between the dispatch and the outcome leaves behind.
 */
function pendingDerivative(
    MediaAsset $asset,
    string $since,
    DerivativeVariant $variant = DerivativeVariant::Thumb,
): MediaDerivative {
    $derivative = MediaDerivative::query()->create([
        'media_asset_id' => $asset->id,
        'variant' => $variant->value,
        'disk' => $asset->disk,
        'object_key' => MediaDerivative::keyFor($asset, $variant),
        'status' => DerivativeStatus::Pending->value,
    ]);

    MediaDerivative::query()->whereKey($derivative->getKey())->update(['updated_at' => $since]);

    return $derivative->fresh();
}

describe('a derivative left pending by a worker that died', function (): void {
    beforeEach(fn () => Bus::fake());

    it('asks again once the row has been pending longer than the window', function (): void {
        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subHours(2)->toDateTimeString());

        expect(Derivatives::thumbnailUrl($asset->fresh()))->toBeNull();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);

        // The re-dispatch moves the row's own clock, so the next render meets
        // work in flight rather than the same abandoned row.
        expect($asset->fresh()->derivatives->firstWhere('variant', DerivativeVariant::Thumb)
            ->updated_at->isAfter(now()->subMinute()))->toBeTrue();
    });

    it('leaves a pending row inside the window alone', function (): void {
        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subSeconds(30)->toDateTimeString());

        expect(Derivatives::thumbnailUrl($asset->fresh()))->toBeNull();

        Bus::assertNothingDispatched();
    });

    it('never re-dispatches a failed row, at any age', function (): void {
        $asset = abandonableAsset();
        $derivative = pendingDerivative($asset, now()->subYear()->toDateTimeString());
        MediaDerivative::query()->whereKey($derivative->getKey())->update([
            'status' => DerivativeStatus::Failed->value,
            'updated_at' => now()->subYear(),
        ]);

        expect(Derivatives::thumbnailUrl($asset->fresh()))->toBeNull()
            ->and(Derivatives::wanted($asset->fresh(), DerivativeVariant::Thumb))->toBeFalse();

        Bus::assertNothingDispatched();
    });

    it('still paints a ready row however stale its digest', function (): void {
        $asset = abandonableAsset();
        $derivative = MediaDerivative::query()->create([
            'media_asset_id' => $asset->id,
            'variant' => DerivativeVariant::Thumb->value,
            'disk' => $asset->disk,
            'object_key' => MediaDerivative::keyFor($asset, DerivativeVariant::Thumb),
            'status' => DerivativeStatus::Ready->value,
            'config_digest' => 'written-under-older-settings',
        ]);
        MediaDerivative::query()->whereKey($derivative->getKey())->update(['updated_at' => now()->subYear()]);

        expect(Derivatives::thumbnailUrl($asset->fresh()))->toContain($derivative->object_key);

        Bus::assertNothingDispatched();
    });

    it('takes the window from configuration', function (): void {
        config()->set('media-library.derivatives.abandoned_after', 10);

        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subSeconds(30)->toDateTimeString());

        Derivatives::thumbnailUrl($asset->fresh());

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('queues one job between two renders meeting the same abandoned row', function (): void {
        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subHours(2)->toDateTimeString());

        Derivatives::thumbnailUrl($asset->fresh());
        Derivatives::thumbnailUrl($asset->fresh());

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('agrees with the render about what is wanted', function (): void {
        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subHours(2)->toDateTimeString());

        expect(Derivatives::wanted($asset->fresh(), DerivativeVariant::Thumb))->toBeTrue();

        $fresh = abandonableAsset();
        pendingDerivative($fresh, now()->subSeconds(30)->toDateTimeString());

        expect(Derivatives::wanted($fresh->fresh(), DerivativeVariant::Thumb))->toBeFalse();
    });
});

describe('an operator meeting abandoned rows', function (): void {
    beforeEach(function (): void {
        Bus::fake();
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
    });

    it('selects them under their own reason', function (): void {
        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subHours(2)->toDateTimeString());
        pendingDerivative(abandonableAsset(), now()->subSeconds(30)->toDateTimeString());

        $targets = collect(RegenerationTargets::for(
            DerivativeVariant::cases(),
            failed: false,
            stale: false,
            missing: false,
            abandoned: true,
        ));

        expect($targets->pluck('2')->all())->toBe(['abandoned'])
            ->and($targets->first()[0]->id)->toBe($asset->id);
    });

    it('queues them from the command', function (): void {
        pendingDerivative(abandonableAsset(), now()->subHours(2)->toDateTimeString());

        $this->artisan('media:regenerate-derivatives --abandoned')->assertSuccessful();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('counts as a selector for the guard', function (): void {
        $this->artisan('media:regenerate-derivatives --abandoned --dry-run')->assertSuccessful();
    });

    it('counts them in the health summary, apart from missing', function (): void {
        $asset = abandonableAsset();
        pendingDerivative($asset, now()->subHours(2)->toDateTimeString());

        $counts = DerivativeHealth::counts();

        // A row that exists is not missing, whatever its age: the two sets
        // stay apart so a readout adds them up rather than double counting.
        expect($counts['abandoned'])->toBe(1)
            ->and($counts['missing'])->toBe(1);
    });

    it('queues what it counted', function (): void {
        pendingDerivative(abandonableAsset(), now()->subHours(2)->toDateTimeString());

        $counts = DerivativeHealth::counts();

        expect(DerivativeHealth::regenerate()['queued'])
            ->toBe($counts['abandoned'] + $counts['missing'] + $counts['failed'] + $counts['stale']);
    });

    it('regenerates over the row in place, leaving no orphaned object', function (): void {
        $asset = abandonableAsset();
        $derivative = pendingDerivative($asset, now()->subHours(2)->toDateTimeString());

        Derivatives::regenerate($asset, DerivativeVariant::Thumb);

        expect($asset->derivatives()->where('variant', DerivativeVariant::Thumb->value)->count())->toBe(1)
            ->and($derivative->fresh()->object_key)->toBe($derivative->object_key)
            ->and(Storage::disk($asset->disk)->allFiles(config('media-library.derivatives.prefix')))->toBe([]);
    });
});

describe('a generation that hangs', function (): void {
    it('settles its row rather than stranding it', function (): void {
        expect((new GenerateDerivative(1, DerivativeVariant::Thumb))->failOnTimeout)->toBeTrue();
    });
});
