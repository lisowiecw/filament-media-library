<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Ingest\Placement;
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
