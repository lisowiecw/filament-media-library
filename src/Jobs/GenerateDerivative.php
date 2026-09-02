<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashing;
use Lisowiecw\MediaLibrary\Derivatives\Raster;
use Lisowiecw\MediaLibrary\Derivatives\SmallOriginal;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;
use Throwable;

/**
 * Generates one variant of one asset, off the request cycle.
 *
 * The job is deliberately neither scoped nor policy-checked. Tenancy is a
 * boundary on who is offered and delivered an asset, not on whether the
 * library is allowed to make a picture of it, and a job that consulted a
 * policy would silently stop generating for tenanted assets the moment it ran
 * without a request behind it.
 *
 * It takes an id rather than the model so a payload that outlives a delete
 * resolves to nothing instead of resurrecting a row.
 */
class GenerateDerivative implements ShouldQueue
{
    use Queueable;

    /**
     * A decode failure is not transient, and is not retried; the tries here
     * are for the disk between this worker and the object.
     */
    public int $tries = 3;

    public function __construct(
        private readonly int $assetId,
        private readonly DerivativeVariant $variant,
    ) {}

    public function handle(): void
    {
        $asset = MediaAsset::query()->find($this->assetId);

        if ($asset === null) {
            return;
        }

        $raster = Raster::decode($this->original($asset) ?? '');

        if ($raster === null) {
            $this->fail($asset, 'The stored object could not be decoded as an image.');

            return;
        }

        // An image small on both counts earns no rows at all, so a job that
        // was queued before that was knowable leaves nothing behind rather
        // than writing an object nobody will ever be pointed at.
        if (SmallOriginal::needsNoDerivatives($asset, $raster->longestEdge())) {
            $this->blurhash($asset, $raster);
            $asset->derivatives()->where('variant', $this->variant->value)->delete();

            return;
        }

        $scaled = $raster->scaledToEdge($this->variant->edge());
        $bytes = $scaled->webp($this->variant->quality());

        $key = MediaDerivative::keyFor($asset, $this->variant);

        // Written before the row moves, and never preceded by a delete of what
        // is already there: a regeneration that fails leaves the old rendering
        // working and the old digest describing it truthfully.
        Storage::disk($asset->disk)->put($key, $bytes, [
            'visibility' => $asset->visibility->value,
            'ContentType' => MediaDerivative::MIME_TYPE,
            'CacheControl' => IngestService::CACHE_CONTROL,
        ]);

        $this->record($asset, [
            'disk' => $asset->disk,
            'object_key' => $key,
            'width' => $scaled->width(),
            'height' => $scaled->height(),
            'bytes' => strlen($bytes),
            'status' => DerivativeStatus::Ready->value,
            'failure_reason' => null,
            'config_digest' => $this->variant->digest(),
        ]);

        $this->blurhash($asset, $scaled);
    }

    /**
     * Once the retries are exhausted the row sticks at failed with a reason
     * and stops being re-dispatched, so a file the runtime cannot read is not
     * retried forever by every render that meets it.
     */
    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::query()->find($this->assetId);

        if ($asset !== null) {
            $this->fail($asset, $exception?->getMessage() ?? 'The derivative could not be generated.');
        }
    }

    /**
     * The hash rides on the thumb job's own decode rather than costing a read
     * of its own, and only the thumb's: the preview would compute the same
     * string from the same picture.
     *
     * It is a top-up rather than a write. An asset uploaded through ingest
     * already has its hash before this job runs, and one that was recorded as
     * undecodable is not asked again here; only an asset that arrived without
     * a hash, an import above all, is given one. `BlurHashing` is what holds
     * that rule, so the job cannot disagree with ingest about it.
     */
    private function blurhash(MediaAsset $asset, Raster $raster): void
    {
        if ($this->variant === DerivativeVariant::Thumb) {
            BlurHashing::fromRaster($asset, $raster);
        }
    }

    private function original(MediaAsset $asset): ?string
    {
        return Storage::disk($asset->disk)->get($asset->object_key);
    }

    private function fail(MediaAsset $asset, string $reason): void
    {
        $this->record($asset, [
            'disk' => $asset->disk,
            'object_key' => MediaDerivative::keyFor($asset, $this->variant),
            'status' => DerivativeStatus::Failed->value,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(MediaAsset $asset, array $attributes): void
    {
        MediaDerivative::query()->updateOrCreate(
            ['media_asset_id' => $asset->id, 'variant' => $this->variant->value],
            $attributes,
        );
    }
}
