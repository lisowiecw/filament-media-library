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
     * The operator's path, called from `media:regenerate-derivatives` once the
     * command's own rate cap has let it through.
     *
     * A rendering that is already ready keeps its row, its object and its old
     * digest while the job runs: the job overwrites in place and moves the
     * digest only once the write succeeded, so a refresh that fails leaves a
     * working card rather than an empty one. Only a variant with nothing
     * behind it is marked pending, where there is no card to blank.
     *
     * The return says whether anything was queued. A caller that has already
     * asked `wanted()` will only ever see true, but a caller holding an
     * arbitrary asset, which is what a row-driven selector hands over, will
     * not.
     */
    public static function regenerate(MediaAsset $asset, DerivativeVariant $variant): bool
    {
        if (! self::generatable($asset)) {
            return false;
        }

        if (self::existing($asset, $variant)?->status->isReady() === true) {
            GenerateDerivative::dispatch($asset->id, $variant);

            return true;
        }

        self::dispatch($asset, $variant);

        return true;
    }

    /**
     * Whether this asset wants a rendering of this variant that it does not
     * have: generatable, not already its own picture, and with no row of any
     * status behind it.
     *
     * The question lives here rather than in whatever is asking, because it is
     * the same question `resolve()` asks on a render, and an answer that drifts
     * between the two would have the command queueing work a card would not,
     * or skipping work a card would.
     *
     * The small-original rule is reached in its byte-only half, exactly as a
     * card reaches it, because the pixels are known only where the object has
     * been read and a caller may be walking rows rather than objects.
     */
    public static function wanted(MediaAsset $asset, DerivativeVariant $variant): bool
    {
        return self::generatable($asset)
            && ! self::paintsItself($asset)
            && self::existing($asset, $variant) === null;
    }

    /**
     * Whether a BlurHash is a coherent thing to ask for: a picture the package
     * can decode, and not one that is already its own.
     *
     * The question lives here for the same reason `wanted()` does. An asset
     * that paints itself never reaches a placeholder, so hashing it would be
     * work for a card that will never ask, and an answer that drifted from the
     * one `resolve()` uses would have the two disagreeing about what an asset
     * even is.
     */
    public static function hashable(MediaAsset $asset): bool
    {
        return self::generatable($asset) && ! self::paintsItself($asset);
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
