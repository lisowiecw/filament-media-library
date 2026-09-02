<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;
use Lisowiecw\MediaLibrary\Derivatives\DerivativeHealth;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Jobs\ComputeBlurHash;
use Lisowiecw\MediaLibrary\Jobs\GenerateDerivative;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * A rendering that exists, recorded with the digest of whatever the settings
 * said when it was written.
 */
function generatedDerivative(
    MediaAsset $asset,
    DerivativeVariant $variant = DerivativeVariant::Thumb,
    ?string $digest = null,
): MediaDerivative {
    return MediaDerivative::query()->create([
        'media_asset_id' => $asset->id,
        'variant' => $variant->value,
        'disk' => $asset->disk,
        'object_key' => MediaDerivative::keyFor($asset, $variant),
        'width' => 400,
        'height' => 300,
        'bytes' => 4096,
        'status' => DerivativeStatus::Ready->value,
        'config_digest' => $digest ?? $variant->digest(),
    ]);
}

/**
 * An asset big enough in bytes that no card would ever paint it as itself.
 */
function renderableAsset(): MediaAsset
{
    return makeAsset(['size' => 900_000, 'visibility' => 'public']);
}

/**
 * Move the settings out from under whatever has already been generated.
 */
function changeDerivativeSettings(): void
{
    config()->set('media-library.derivatives.variants.thumb.edge', 512);
    config()->set('media-library.derivatives.variants.preview.edge', 2048);
}

describe('detecting staleness', function (): void {
    it('reads a null digest as unknown rather than stale', function (): void {
        $asset = renderableAsset();
        $derivative = generatedDerivative($asset, digest: 'x');
        $derivative->forceFill(['config_digest' => null])->save();

        changeDerivativeSettings();

        expect($derivative->fresh()->isStale())->toBeFalse()
            ->and(DerivativeHealth::stale())->toBe(0);
    });

    it('marks a rendering stale once the settings it names have moved', function (): void {
        $derivative = generatedDerivative(renderableAsset());

        expect($derivative->isStale())->toBeFalse();

        changeDerivativeSettings();

        expect($derivative->fresh()->isStale())->toBeTrue()
            ->and(DerivativeHealth::stale())->toBe(1);
    });

    it('never calls a pending or failed row stale, since it has no bytes to be wrong about', function (): void {
        $asset = renderableAsset();
        generatedDerivative($asset)->forceFill(['status' => DerivativeStatus::Failed->value])->save();

        changeDerivativeSettings();

        expect(DerivativeHealth::stale())->toBe(0)
            ->and(DerivativeHealth::failed())->toBe(1);
    });

    it('compares each variant against its own digest', function (): void {
        $asset = renderableAsset();
        generatedDerivative($asset, DerivativeVariant::Thumb);
        generatedDerivative($asset, DerivativeVariant::Preview);

        config()->set('media-library.derivatives.variants.thumb.edge', 512);

        expect(DerivativeHealth::stale())->toBe(1);
    });

    it('ignores the encoder and the format, so only the edge and the quality move it', function (): void {
        $before = DerivativeVariant::Thumb->digest();

        config()->set('media-library.derivatives.prefix', 'somewhere-else');

        expect(DerivativeVariant::Thumb->digest())->toBe($before);

        config()->set('media-library.derivatives.quality', 40);

        expect(DerivativeVariant::Thumb->digest())->not->toBe($before);
    });
});

describe('serving a stale rendering', function (): void {
    it('still paints it, and queues nothing', function (): void {
        Bus::fake();

        $asset = renderableAsset();
        $derivative = generatedDerivative($asset);

        changeDerivativeSettings();

        expect(Derivatives::thumbnailUrl($asset->fresh()))->toContain($derivative->object_key);

        Bus::assertNothingDispatched();
    });
});

describe('the URL of a stale rendering', function (): void {
    it('carries the digest the rendering was written with, not the one the settings now want', function (): void {
        $asset = renderableAsset();
        $derivative = generatedDerivative($asset);

        $before = $derivative->url();

        changeDerivativeSettings();

        // The bytes have not moved, so neither has the URL: a cache holding
        // the old rendering keeps serving it rather than re-caching it under
        // a new URL it would then never invalidate.
        expect($derivative->fresh()->url())->toBe($before);

        $derivative->forceFill(['config_digest' => DerivativeVariant::Thumb->digest()])->save();

        expect($derivative->fresh()->url())->not->toBe($before);
    });

    it('leaves a rendering of unknown provenance the bare URL it has always had', function (): void {
        $derivative = generatedDerivative(renderableAsset());
        $derivative->forceFill(['config_digest' => null])->save();

        expect($derivative->fresh()->url())->not->toContain('digest=');
    });

    it('stamps a private rendering the same way, through the delivery route', function (): void {
        $asset = makeAsset(['size' => 900_000, 'visibility' => 'private']);
        $derivative = generatedDerivative($asset);

        expect($derivative->url())->toContain('digest='.DerivativeVariant::Thumb->digest());
    });
});

describe('the health counts', function (): void {
    it('leaves out a row whose asset is gone, since the command skips it too', function (): void {
        $asset = renderableAsset();
        generatedDerivative($asset);
        changeDerivativeSettings();

        expect(DerivativeHealth::stale())->toBe(1);

        $asset->delete();

        expect(DerivativeHealth::stale())->toBe(0);
    });
});

describe('the regenerate command', function (): void {
    beforeEach(function (): void {
        Bus::fake();

        // The clock is frozen and the fake sleep moves it, so a run that waits
        // out a spent minute reaches the next one rather than spinning.
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
    });

    it('refuses to run without a selector', function (): void {
        $this->artisan('media:regenerate-derivatives')->assertFailed();

        Bus::assertNothingDispatched();
    });

    it('refuses a variant the package does not have', function (): void {
        $this->artisan('media:regenerate-derivatives --stale --variant=huge')->assertFailed();
    });

    it('queues the stale renderings', function (): void {
        generatedDerivative(renderableAsset());
        changeDerivativeSettings();

        $this->artisan('media:regenerate-derivatives --stale')->assertSuccessful();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('leaves a stale row ready while its refresh runs, so no card blanks', function (): void {
        $derivative = generatedDerivative(renderableAsset());
        changeDerivativeSettings();

        $this->artisan('media:regenerate-derivatives --stale')->assertSuccessful();

        expect($derivative->fresh()->status)->toBe(DerivativeStatus::Ready)
            ->and($derivative->fresh()->config_digest)->toBe($derivative->config_digest);
    });

    it('reports without queueing under --dry-run', function (): void {
        $asset = renderableAsset();
        generatedDerivative($asset);
        changeDerivativeSettings();

        $this->artisan('media:regenerate-derivatives --stale --dry-run')
            ->expectsOutputToContain($asset->ulid)
            ->assertSuccessful();

        Bus::assertNothingDispatched();
    });

    it('queues the failed renderings', function (): void {
        generatedDerivative(renderableAsset())->forceFill([
            'status' => DerivativeStatus::Failed->value,
            'failure_reason' => 'nope',
        ])->save();

        $this->artisan('media:regenerate-derivatives --failed')->assertSuccessful();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('queues the missing renderings for both variants', function (): void {
        renderableAsset();

        $this->artisan('media:regenerate-derivatives --missing')->assertSuccessful();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 2);

        expect(MediaDerivative::query()->where('status', DerivativeStatus::Pending->value)->count())->toBe(2);
    });

    it('narrows to one variant', function (): void {
        renderableAsset();

        $this->artisan('media:regenerate-derivatives --missing --variant=thumb')->assertSuccessful();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);

        expect(MediaDerivative::query()->first()->variant)->toBe(DerivativeVariant::Thumb);
    });

    it('leaves alone what nothing could render, and what is its own rendering', function (): void {
        makeAsset(['mime_type' => 'video/mp4', 'object_key' => 'media/clip.mp4']);
        makeAsset(['mime_type' => 'image/svg+xml', 'object_key' => 'media/logo.svg']);
        makeAsset(['size' => 512, 'object_key' => 'media/icon.png', 'mime_type' => 'image/png']);

        $this->artisan('media:regenerate-derivatives --missing')->assertSuccessful();

        Bus::assertNothingDispatched();
    });

    it('obeys the same per-minute cap as lazy backfill', function (): void {
        config()->set('media-library.derivatives.lazy_dispatch.per_minute', 2);

        foreach (range(1, 3) as $ignored) {
            libraryAsset()->forceFill(['size' => 900_000])->save();
        }

        $this->artisan('media:regenerate-derivatives --missing --variant=thumb')->assertSuccessful();

        // The third one waits for the next minute rather than being dropped:
        // a run over a large library finishes, it just trickles.
        Sleep::assertSlept(fn (): bool => true, 1);

        Bus::assertDispatchedTimes(GenerateDerivative::class, 3);
    });
});

describe('the regenerate command asked for hashes', function (): void {
    beforeEach(function (): void {
        Bus::fake();

        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);
    });

    it('queues hash work and no derivative work', function (): void {
        $asset = renderableAsset();

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
        Bus::assertNotDispatched(GenerateDerivative::class);

        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Pending);
    });

    it('refuses to mix the hash selector with the derivative ones', function (): void {
        renderableAsset();

        $this->artisan('media:regenerate-derivatives --hashes --missing')->assertFailed();

        Bus::assertNothingDispatched();
    });

    it('reports without queueing or claiming under --dry-run', function (): void {
        $asset = renderableAsset();

        $this->artisan('media:regenerate-derivatives --hashes --dry-run')
            ->expectsOutputToContain($asset->ulid)
            ->assertSuccessful();

        Bus::assertNothingDispatched();

        expect($asset->fresh()->blurhash_status)->toBeNull();
    });

    it('says how long a real run of that size would take', function (): void {
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 2);

        foreach (range(1, 5) as $ignored) {
            libraryAsset()->forceFill(['size' => 900_000])->save();
        }

        $this->artisan('media:regenerate-derivatives --hashes --dry-run')
            ->expectsOutputToContain('about 3 minute(s) at 2 a minute')
            ->assertSuccessful();
    });

    it('leaves alone an asset that already has a hash', function (): void {
        renderableAsset()->forceFill([
            'blurhash' => 'LEHV6nWB2yk8',
            'blurhash_status' => BlurHashStatus::Ready->value,
        ])->save();

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Bus::assertNothingDispatched();
    });

    it('leaves alone an asset whose bytes already refused to decode', function (): void {
        renderableAsset()->forceFill(['blurhash_status' => BlurHashStatus::Failed->value])->save();

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Bus::assertNothingDispatched();
    });

    it('leaves alone an asset in flight, since the claim was already made', function (): void {
        renderableAsset()->forceFill(['blurhash_status' => BlurHashStatus::Pending->value])->save();

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Bus::assertNothingDispatched();
    });

    it('leaves alone what nothing could hash, and what is its own rendering', function (): void {
        makeAsset(['mime_type' => 'video/mp4', 'object_key' => 'media/clip.mp4']);
        makeAsset(['mime_type' => 'image/svg+xml', 'object_key' => 'media/logo.svg']);
        makeAsset(['size' => 512, 'object_key' => 'media/icon.png', 'mime_type' => 'image/png']);

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Bus::assertNothingDispatched();
    });

    it('waits out the hash allowance rather than refusing, so a large library finishes', function (): void {
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 2);

        foreach (range(1, 3) as $ignored) {
            libraryAsset()->forceFill(['size' => 900_000])->save();
        }

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Sleep::assertSlept(fn (): bool => true, 1);

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 3);
    });

    it('obeys the hash allowance rather than the derivative one', function (): void {
        config()->set('media-library.derivatives.lazy_dispatch.per_minute', 1);

        foreach (range(1, 3) as $ignored) {
            libraryAsset()->forceFill(['size' => 900_000])->save();
        }

        $this->artisan('media:regenerate-derivatives --hashes')->assertSuccessful();

        Sleep::assertNeverSlept();

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 3);
    });
});
