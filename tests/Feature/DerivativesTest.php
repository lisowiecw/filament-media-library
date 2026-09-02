<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashing;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Derivatives\Raster;
use Lisowiecw\MediaLibrary\Derivatives\RegenerationTargets;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Forms\Components\LibraryGrid;
use Lisowiecw\MediaLibrary\Ingest\Placement;
use Lisowiecw\MediaLibrary\Jobs\ComputeBlurHash;
use Lisowiecw\MediaLibrary\Jobs\GenerateDerivative;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * A real raster of the given size, so the pipeline has something GD can decode
 * rather than a fake with no pixels.
 */
function largeImage(string $name = 'photo.png', int $width = 1200, int $height = 900): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

function storeImage(MediaAsset $asset, int $width = 1200, int $height = 900): void
{
    // The fake upload has to outlive the read: its temp file goes with it.
    $file = largeImage('x.png', $width, $height);

    Storage::disk($asset->disk)->put($asset->object_key, (string) file_get_contents((string) $file->getRealPath()));
}

describe('the ingest seam', function (): void {
    it('queues a thumb for an image too big to be its own', function (): void {
        Queue::fake();

        $asset = ingest(largeImage());

        Queue::assertPushed(GenerateDerivative::class);

        expect($asset->derivatives()->count())->toBe(1)
            ->and($asset->derivatives()->first()->status)->toBe(DerivativeStatus::Pending)
            ->and($asset->derivatives()->first()->object_key)
            ->toBe('media-derivatives/'.$asset->ulid.'/thumb.webp');
    });

    it('registers no rows for a small renderable original', function (): void {
        Queue::fake();

        $asset = ingest(UploadedFile::fake()->image('tiny.png', 40, 40));

        Queue::assertNothingPushed();

        expect($asset->derivatives()->count())->toBe(0);
    });

    it('queues nothing for an SVG, which is its own thumbnail', function (): void {
        Queue::fake();

        $asset = ingest(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));

        Queue::assertNothingPushed();

        expect($asset->derivatives()->count())->toBe(0);
    });

    it('queues nothing for a video', function (): void {
        Queue::fake();

        ingest(UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'));

        Queue::assertNothingPushed();
    });
});

describe('the generation job', function (): void {
    it('writes a webp beside the asset and marks the row ready', function (): void {
        $asset = makeAsset(['visibility' => 'public']);
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        $derivative = $asset->derivatives()->first();

        expect($derivative->status)->toBe(DerivativeStatus::Ready)
            ->and($derivative->width)->toBe(400)
            ->and($derivative->height)->toBe(300)
            ->and($derivative->bytes)->toBeGreaterThan(0)
            ->and($derivative->config_digest)->not->toBeNull()
            ->and($derivative->disk)->toBe($asset->disk);

        Storage::disk('media')->assertExists($derivative->object_key);
    });

    it('never upscales', function (): void {
        $asset = makeAsset();
        storeImage($asset, 900, 200);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        expect($asset->derivatives()->first()->height)->toBeLessThan(200);
    });

    it('tops up the blurhash of an asset that arrived without one', function (): void {
        $asset = makeAsset(['size' => 900_000]);
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        expect($asset->fresh()->blurhash)->toBeString()->not->toBeEmpty()
            ->and($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Ready);
    });

    it('leaves a hash that is already ready exactly where it was', function (): void {
        $asset = makeAsset([
            'size' => 900_000,
            'blurhash' => 'LEHV6nWB2yk8',
            'blurhash_status' => BlurHashStatus::Ready,
        ]);
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        expect($asset->fresh()->blurhash)->toBe('LEHV6nWB2yk8');
    });

    it('leaves a hash that landed while it was decoding exactly where it was', function (): void {
        $asset = makeAsset(['size' => 900_000]);
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);

        // The other path finishes after this job read its row and before the
        // job writes, which a guard read off the model in hand would miss.
        $stale = MediaAsset::query()->find($asset->id);
        MediaAsset::query()->whereKey($asset->id)->update([
            'blurhash' => 'LEHV6nWB2yk8',
            'blurhash_status' => BlurHashStatus::Ready->value,
        ]);

        BlurHashing::fromRaster($stale, Raster::decode(
            (string) Storage::disk($asset->disk)->get($asset->object_key),
        ));

        expect($asset->fresh()->blurhash)->toBe('LEHV6nWB2yk8')
            ->and($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Ready);
    });

    it('never turns a recorded failure ready by the side door', function (): void {
        $asset = makeAsset(['size' => 900_000, 'blurhash_status' => BlurHashStatus::Failed]);
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        expect($asset->fresh()->blurhash)->toBeNull()
            ->and($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed);
    });

    it('leaves no rows at all where the original turns out to be its own thumbnail', function (): void {
        $asset = makeAsset(['size' => 512]);
        storeImage($asset, 40, 40);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        // An original that paints itself never reaches a placeholder, so it is
        // not hashed here either, and is left as never asked rather than as a
        // hash somebody could have painted over the picture itself.
        expect($asset->derivatives()->count())->toBe(0)
            ->and($asset->fresh()->blurhash)->toBeNull()
            ->and($asset->fresh()->blurhash_status)->toBeNull();
    });

    it('sticks at failed with a reason once the object cannot be decoded', function (): void {
        $asset = makeAsset();
        Storage::disk('media')->put($asset->object_key, 'not an image');

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        $job = new GenerateDerivative($asset->id, DerivativeVariant::Thumb);

        try {
            $job->handle();
        } catch (Throwable $e) {
            $job->failed($e);
        }

        $derivative = $asset->derivatives()->first();

        expect($derivative->status)->toBe(DerivativeStatus::Failed)
            ->and($derivative->failure_reason)->toBeString()->not->toBeEmpty();

        // A failed row is terminal: a later render never queues it again.
        Bus::fake();
        Derivatives::thumbnailUrl($asset->fresh());
        Bus::assertNothingDispatched();
    });

    it('still computes for an asset trashed between the claim and the job', function (): void {
        $asset = makeAsset(['size' => 900_000]);
        storeImage($asset);

        BlurHashing::dispatchLazily($asset);
        $asset->delete();

        (new ComputeBlurHash($asset->id))->handle();

        $fresh = MediaAsset::withTrashed()->find($asset->id);

        expect($fresh->blurhash)->toBeString()->not->toBeEmpty()
            ->and($fresh->blurhash_status)->toBe(BlurHashStatus::Ready);
    });

    it('does nothing where the asset is gone by the time the job runs', function (): void {
        $asset = makeAsset();
        $id = $asset->id;
        $asset->forceDelete();

        (new GenerateDerivative($id, DerivativeVariant::Thumb))->handle();

        expect(MediaDerivative::count())->toBe(0);
    });
});

describe('resolving a thumbnail at render time', function (): void {
    it('paints a ready derivative of a public asset', function (): void {
        $asset = makeAsset(['visibility' => 'public']);
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        expect(Derivatives::thumbnailUrl($asset->fresh()))->toContain('thumb.webp');
    });

    it('paints a small original as itself without queueing anything', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 512, 'visibility' => 'public']);

        expect(Derivatives::thumbnailUrl($asset))->toBe($asset->url());

        Bus::assertNothingDispatched();
    });

    it('dispatches once for a missing derivative and paints the pending state', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);

        expect(Derivatives::thumbnailUrl($asset))->toBeNull();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);

        // The pending row it left behind is what stops the second render
        // queueing the same work again.
        expect(Derivatives::thumbnailUrl($asset->fresh()))->toBeNull();

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('caps how much backfill one render may queue', function (): void {
        Bus::fake();
        config()->set('media-library.derivatives.lazy_dispatch.per_request', 2);

        foreach (range(1, 4) as $i) {
            Derivatives::thumbnailUrl(libraryAsset()->forceFill(['size' => 900_000]));
        }

        Bus::assertDispatchedTimes(GenerateDerivative::class, 2);
    });

    it('serves a whole page of never-generated cards from one render', function (): void {
        Bus::fake();

        // Held clear of the page so what is measured is the render's own
        // allowance rather than the minute's.
        config()->set('media-library.derivatives.lazy_dispatch.per_minute', 1000);

        foreach (range(1, LibraryGrid::BATCH) as $i) {
            Derivatives::thumbnailUrl(libraryAsset()->forceFill(['size' => 900_000]));
        }

        Bus::assertDispatchedTimes(GenerateDerivative::class, LibraryGrid::BATCH);
    });

    it('ships a per-request allowance that covers a whole grid page', function (): void {
        // The two numbers are one decision: a render's allowance is a page of
        // cards, so a page size that grows past it would silently ration the
        // view again.
        expect(config('media-library.derivatives.lazy_dispatch.per_request'))
            ->toBeGreaterThanOrEqual(LibraryGrid::BATCH);
    });

    it('caps how much backfill a minute may queue', function (): void {
        Bus::fake();
        config()->set('media-library.derivatives.lazy_dispatch.per_minute', 1);

        foreach (range(1, 3) as $i) {
            Derivatives::thumbnailUrl(libraryAsset()->forceFill(['size' => 900_000]));
        }

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });
});

describe('a derivative row', function (): void {
    it('addresses a private parent through the Delivery route rather than the disk', function (): void {
        $asset = makeAsset();
        storeImage($asset);

        Derivatives::dispatchEagerly($asset, DerivativeVariant::Thumb);
        (new GenerateDerivative($asset->id, DerivativeVariant::Thumb))->handle();

        expect($asset->derivatives()->first()->url())
            ->toContain('/admin/media/'.$asset->ulid)
            ->toContain('variant=thumb')
            ->not->toContain($asset->derivatives()->first()->object_key);
    });

    it('follows the parent placement and visibility', function (): void {
        $asset = ingest(largeImage(), Placement::resolve());

        expect($asset->derivatives()->first()->disk)->toBe($asset->disk);
    });
});

describe('resolving a preview on demand', function (): void {
    it('queues nothing at upload', function (): void {
        Queue::fake();

        $asset = ingest(largeImage());

        expect($asset->derivatives()->where('variant', DerivativeVariant::Preview->value)->count())->toBe(0);
    });

    it('queues the preview on its first actual request', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);

        expect(Derivatives::previewUrl($asset))->toBeNull();

        Bus::assertDispatched(
            GenerateDerivative::class,
            fn (GenerateDerivative $job): bool => (fn (): bool => $this->variant === DerivativeVariant::Preview)->call($job),
        );
    });

    it('queues nothing on a second request while one is in flight', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);

        Derivatives::previewUrl($asset);
        Derivatives::previewUrl($asset->fresh());

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
    });

    it('paints a ready preview through the route', function (): void {
        $asset = makeAsset();
        storeImage($asset);

        Derivatives::dispatchLazily($asset, DerivativeVariant::Preview);
        (new GenerateDerivative($asset->id, DerivativeVariant::Preview))->handle();

        expect(Derivatives::previewUrl($asset->fresh()))->toContain('variant=preview');
    });

    it('paints an original that is already its own picture', function (): void {
        $asset = ingest(pngUpload());

        expect(Derivatives::previewUrl($asset))->toBe($asset->url());
    });

    it('is what the asset itself hands out', function (): void {
        $asset = makeAsset(['size' => 900_000]);

        expect($asset->previewUrl())->toBe(Derivatives::previewUrl($asset));
    });
});

describe('hashing an imported asset at render time', function (): void {
    it('asks for a hash the first time a card finds none, and never twice', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);

        expect(BlurHashing::hashFor($asset))->toBeNull();

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);

        // The pending status it left behind is what a second render meets.
        BlurHashing::hashFor($asset->fresh());

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Pending);
    });

    it('never asks again once a failure is recorded, however often the card is drawn', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000, 'blurhash_status' => BlurHashStatus::Failed]);

        foreach (range(1, 3) as $i) {
            expect(BlurHashing::hashFor($asset->fresh()))->toBeNull();
        }

        Bus::assertNotDispatched(ComputeBlurHash::class);
    });

    it('hands back a ready hash without asking for anything', function (): void {
        Bus::fake();

        $asset = makeAsset([
            'size' => 900_000,
            'blurhash' => 'LEHV6nWB2yk8',
            'blurhash_status' => BlurHashStatus::Ready,
        ]);

        expect(BlurHashing::hashFor($asset))->toBe('LEHV6nWB2yk8');

        Bus::assertNothingDispatched();
    });

    it('asks nothing of an asset that paints itself, or of a file that is not a picture', function (): void {
        Bus::fake();

        BlurHashing::hashFor(libraryAsset()->forceFill(['size' => 512]));
        BlurHashing::hashFor(libraryAsset()->forceFill(['mime_type' => 'image/svg+xml']));
        BlurHashing::hashFor(libraryAsset()->forceFill(['mime_type' => 'video/mp4', 'size' => 900_000]));

        Bus::assertNothingDispatched();
    });

    it('computes the hash from the stored object when the job runs', function (): void {
        $asset = makeAsset(['size' => 900_000]);
        storeImage($asset);

        BlurHashing::dispatchLazily($asset);
        (new ComputeBlurHash($asset->id))->handle();

        expect($asset->fresh()->blurhash)->toBeString()->not->toBeEmpty()
            ->and($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Ready);
    });

    it('records a failure for an object that will not decode, and stops', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);
        Storage::disk('media')->put($asset->object_key, 'not an image');

        BlurHashing::dispatchLazily($asset);
        (new ComputeBlurHash($asset->id))->handle();

        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed);

        BlurHashing::hashFor($asset->fresh());

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
    });

    it('retries a read that answered with nothing, and settles once the tries are gone', function (): void {
        $asset = makeAsset(['size' => 900_000]);

        BlurHashing::dispatchLazily($asset);
        $job = new ComputeBlurHash($asset->id);

        // Nothing was ever put on the disk, which is a read failing rather
        // than a file refusing to decode, so it is thrown for the retry.
        try {
            $job->handle();
        } catch (Throwable $e) {
            expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Pending);

            $job->failed($e);
        }

        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed);
    });

    it('does nothing where the asset is gone by the time the job runs', function (): void {
        $asset = makeAsset(['size' => 900_000]);
        $id = $asset->id;
        $asset->forceDelete();

        (new ComputeBlurHash($id))->handle();
    })->throwsNoExceptions();

    it('spends its own allowance rather than the derivative one', function (): void {
        Bus::fake();
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 1);
        config()->set('media-library.derivatives.lazy_dispatch.per_minute', 1000);

        $assets = collect(range(1, 3))->map(fn (): MediaAsset => libraryAsset()->forceFill(['size' => 900_000]));

        $assets->each(function (MediaAsset $asset): void {
            BlurHashing::hashFor($asset);
            Derivatives::thumbnailUrl($asset);
        });

        // The hash cap is spent after one; the derivative cap is untouched by
        // it, so every card still gets the picture it asked for.
        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
        Bus::assertDispatchedTimes(GenerateDerivative::class, 3);
    });

    it('spends no hash allowance on derivative work', function (): void {
        Bus::fake();
        config()->set('media-library.derivatives.lazy_dispatch.per_minute', 1);

        $first = libraryAsset()->forceFill(['size' => 900_000]);
        $second = libraryAsset()->forceFill(['size' => 900_000]);

        Derivatives::thumbnailUrl($first);
        Derivatives::thumbnailUrl($second);

        BlurHashing::hashFor($first);
        BlurHashing::hashFor($second);

        Bus::assertDispatchedTimes(GenerateDerivative::class, 1);
        Bus::assertDispatchedTimes(ComputeBlurHash::class, 2);
    });

    it('ships a hash allowance looser than the derivative one', function (): void {
        expect(config('media-library.blurhash.lazy_dispatch.per_minute'))
            ->toBeGreaterThan(config('media-library.derivatives.lazy_dispatch.per_minute'))
            ->and(config('media-library.blurhash.lazy_dispatch.per_request'))
            ->toBeGreaterThanOrEqual(LibraryGrid::BATCH);
    });

    it('caps how much hashing one minute may queue', function (): void {
        Bus::fake();
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 2);

        foreach (range(1, 4) as $i) {
            BlurHashing::hashFor(libraryAsset()->forceFill(['size' => 900_000]));
        }

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 2);
    });

    it('is what a card painting a placeholder asks', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000, 'visibility' => 'public']);

        $grid = LibraryGrid::make('gallery');
        $grid->blurhash($asset);

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
    });
});

describe('a hash left pending by a worker that died', function (): void {
    /**
     * An asset sitting at pending since the given time, which is what a worker
     * killed outright leaves behind: the status was taken and nothing ever
     * settled it.
     */
    function pendingAsset(?string $since): MediaAsset
    {
        $asset = libraryAsset()->forceFill(['size' => 900_000]);
        $asset->save();

        MediaAsset::withTrashed()->whereKey($asset->getKey())->update([
            'blurhash_status' => BlurHashStatus::Pending->value,
            'blurhash_pending_since' => $since,
        ]);

        return $asset->fresh();
    }

    it('asks again once the asset has been pending longer than the window', function (): void {
        Bus::fake();

        $asset = pendingAsset(now()->subHours(2)->toDateTimeString());

        BlurHashing::hashFor($asset);

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);

        // The retaken status carries a time of its own, so the next render
        // meets a fresh pending rather than the abandoned one.
        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Pending)
            ->and($asset->fresh()->blurhash_pending_since->isAfter(now()->subMinute()))->toBeTrue();
    });

    it('leaves a pending asset inside the window alone', function (): void {
        Bus::fake();

        BlurHashing::hashFor(pendingAsset(now()->subSeconds(30)->toDateTimeString()));

        Bus::assertNotDispatched(ComputeBlurHash::class);
    });

    it('reads a pending row that predates the column as nobody\'s work', function (): void {
        Bus::fake();

        // Precisely the rows this stranded: pending with no recorded time.
        BlurHashing::hashFor(pendingAsset(null));

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
    });

    it('takes the window from configuration', function (): void {
        Bus::fake();
        config()->set('media-library.blurhash.abandoned_after', 10);

        BlurHashing::hashFor(pendingAsset(now()->subSeconds(30)->toDateTimeString()));

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
    });

    it('queues one job between two renders meeting the same abandoned asset', function (): void {
        Bus::fake();

        $asset = pendingAsset(now()->subHours(2)->toDateTimeString());

        BlurHashing::hashFor($asset);
        $taken = $asset->fresh()->blurhash_pending_since;

        BlurHashing::hashFor($asset->fresh());

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);

        // The time the first render stamped is untouched, which is the
        // conditional update refusing the second: a match would restamp it.
        expect($asset->fresh()->blurhash_pending_since->equalTo($taken))->toBeTrue();
    });

    it('reopens neither a ready hash nor a recorded failure', function (): void {
        Bus::fake();

        $ready = libraryAsset()->forceFill([
            'size' => 900_000,
            'blurhash' => 'LEHV6nWB2yk8',
            'blurhash_status' => BlurHashStatus::Ready,
        ]);
        $ready->save();

        $failed = libraryAsset()->forceFill(['size' => 900_000, 'blurhash_status' => BlurHashStatus::Failed]);
        $failed->save();

        MediaAsset::withTrashed()->update(['blurhash_pending_since' => now()->subHours(2)]);

        BlurHashing::hashFor($ready->fresh());
        BlurHashing::hashFor($failed->fresh());

        Bus::assertNotDispatched(ComputeBlurHash::class);

        expect($ready->fresh()->blurhash)->toBe('LEHV6nWB2yk8')
            ->and($failed->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed);
    });

    it('computes the hash once the retaken job runs', function (): void {
        $asset = pendingAsset(now()->subHours(2)->toDateTimeString());
        storeImage($asset);

        BlurHashing::dispatchLazily($asset);
        (new ComputeBlurHash($asset->id))->handle();

        expect($asset->fresh()->blurhash)->toBeString()->not->toBeEmpty()
            ->and($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Ready);
    });

    it('offers an abandoned asset to a backfill on the same terms', function (): void {
        Bus::fake();

        $abandoned = pendingAsset(now()->subHours(2)->toDateTimeString());
        $inFlight = pendingAsset(now()->subSeconds(30)->toDateTimeString());

        $targets = collect(iterator_to_array(RegenerationTargets::hashes(), false))
            ->map(fn (array $target): int => $target[0]->id);

        expect($targets->all())->toBe([$abandoned->id]);

        BlurHashing::backfill($abandoned);
        BlurHashing::backfill($inFlight);

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
    });

    it('clears the pending time wherever the status settles', function (): void {
        $asset = makeAsset(['size' => 900_000]);
        storeImage($asset);

        BlurHashing::dispatchLazily($asset);

        expect($asset->fresh()->blurhash_pending_since)->not->toBeNull();

        (new ComputeBlurHash($asset->id))->handle();

        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Ready)
            ->and($asset->fresh()->blurhash_pending_since)->toBeNull();

        $failed = pendingAsset(now()->subHours(2)->toDateTimeString());
        BlurHashing::settleAsFailed($failed);

        expect($failed->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed)
            ->and($failed->fresh()->blurhash_pending_since)->toBeNull();
    });
});
