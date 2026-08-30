<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Jobs\GenerateDerivative;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * The one way in and out of the derivative pipeline: what a card asks for a
 * picture, and what ingest asks to have one made.
 *
 * Resolving is the only self-healing path there is, and both variants take
 * it: a missing rendering at render time queues the job and paints the pending
 * tile, which is what covers imports the pipeline never saw, jobs that were
 * lost, and dimensions the operator changed yesterday. Nothing sweeps, and
 * nothing generates inline in a web request.
 */
final readonly class Derivatives
{
    /**
     * What a card paints for this asset, or null when there is nothing to
     * paint yet and the quiet tile is the answer.
     */
    public static function thumbnailUrl(MediaAsset $asset): ?string
    {
        return self::resolve($asset, DerivativeVariant::Thumb);
    }

    /**
     * What a full-size view paints for this asset, or null when there is
     * nothing to paint yet.
     *
     * The preview is the one variant nothing queues at upload: an asset nobody
     * ever opens full size costs no generation, no object and no write. This
     * call is the first actual request, and it is what makes one.
     */
    public static function previewUrl(MediaAsset $asset): ?string
    {
        return self::resolve($asset, DerivativeVariant::Preview);
    }

    /**
     * The ready rendering of one variant, or null while there is none. What
     * the Delivery route answers a variant request from, so a request and a
     * render agree on what exists without either owning the query.
     */
    public static function ready(MediaAsset $asset, DerivativeVariant $variant): ?MediaDerivative
    {
        $derivative = self::existing($asset, $variant);

        return $derivative?->status->isReady() === true ? $derivative : null;
    }

    /**
     * The one resolution both variants take.
     *
     * Order matters. A rendering that exists wins, however stale, because a
     * settings change must never blank the grid. Failing that, an original
     * small enough to be its own picture is painted directly, so an icon costs
     * nothing. Only then is a job asked for.
     */
    private static function resolve(MediaAsset $asset, DerivativeVariant $variant): ?string
    {
        $derivative = self::existing($asset, $variant);

        if ($derivative?->status->isReady() === true) {
            return $derivative->url();
        }

        if (self::paintsItself($asset)) {
            return $asset->url();
        }

        // A pending row is a job already in flight, and a failed one has
        // exhausted its retries: neither is re-dispatched, so a broken file is
        // not retried forever and a busy grid does not pile jobs on itself.
        if ($derivative === null) {
            self::dispatchLazily($asset, $variant);
        }

        return null;
    }

    /**
     * The eager path, called once per upload from ingest. Always queued, never
     * inline, and never rate-capped: this is one job for one deliberate act.
     */
    public static function dispatchEagerly(MediaAsset $asset, DerivativeVariant $variant): void
    {
        if (! self::generatable($asset) || self::existing($asset, $variant) !== null) {
            return;
        }

        self::dispatch($asset, $variant);
    }

    /**
     * The backfill path, behind the rate cap, called from a render that found
     * nothing to paint.
     */
    public static function dispatchLazily(MediaAsset $asset, DerivativeVariant $variant): void
    {
        if (! self::generatable($asset) || ! app(LazyDispatch::class)->allows()) {
            return;
        }

        self::dispatch($asset, $variant);
    }

    /**
     * Whether a rendering of this asset is even a coherent thing to ask for.
     * A video is a glyph tile plus a play badge and always was, because the
     * alternative is a poster frame, and that means a binary.
     */
    public static function generatable(MediaAsset $asset): bool
    {
        return $asset->mime_type !== null
            && str_starts_with(strtolower($asset->mime_type), 'image/')
            && ! self::isSvg($asset);
    }

    /**
     * Whether the asset is already the picture, at any variant. A sanitized SVG
     * is its own rendering, at any size, which is what keeps a rasterizer out
     * of the pipeline; and a raster under the small-original ceiling earns no
     * derivative rows at all, so it is painted directly rather than rendered a
     * second time.
     */
    private static function paintsItself(MediaAsset $asset): bool
    {
        return self::isSvg($asset) || SmallOriginal::paintsOriginal($asset);
    }

    private static function isSvg(MediaAsset $asset): bool
    {
        return $asset->mime_type !== null && str_contains(strtolower($asset->mime_type), 'svg');
    }

    /**
     * The row that says a job is in flight, written before the job is queued
     * so a second render finds it rather than queueing the same work again.
     */
    private static function dispatch(MediaAsset $asset, DerivativeVariant $variant): void
    {
        $derivative = MediaDerivative::query()->updateOrCreate(
            ['media_asset_id' => $asset->id, 'variant' => $variant->value],
            [
                'disk' => $asset->disk,
                'object_key' => MediaDerivative::keyFor($asset, $variant),
                'status' => DerivativeStatus::Pending->value,
                'failure_reason' => null,
            ],
        );

        // The loaded relation is what the next resolve reads, and a second
        // resolve of the same card within one render would otherwise not see
        // the row this one just wrote, and would queue the work twice.
        if ($asset->relationLoaded('derivatives')) {
            $asset->setRelation('derivatives', $asset->derivatives->reject(
                fn (MediaDerivative $loaded): bool => $loaded->variant === $variant,
            )->push($derivative)->values());
        }

        GenerateDerivative::dispatch($asset->id, $variant);
    }

    private static function existing(MediaAsset $asset, DerivativeVariant $variant): ?MediaDerivative
    {
        return $asset->derivatives->firstWhere('variant', $variant);
    }
}
