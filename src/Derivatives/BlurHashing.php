<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The one place a BlurHash is written, and the contract between the two paths
 * that write it: ingest, which has the bytes in hand, and the thumb job, which
 * tops up an asset that arrived without one.
 *
 * The rules are the whole of that contract. A ready hash is never overwritten,
 * because two paths computing the same string must not fight over it. A failed
 * status is never turned ready by a different route, because a recorded
 * failure means the bytes could not be decoded and nothing should ask again.
 * Whoever gets there first wins. See ADR 18.
 */
final readonly class BlurHashing
{
    /**
     * Whether this asset is one a hash can be computed for at all: a picture
     * the package can decode, and not one that already paints itself.
     *
     * The question is answered by `Derivatives` rather than restated here, so
     * a card, the hash and a derivative cannot disagree about what an asset is.
     */
    public static function applies(MediaAsset $asset): bool
    {
        return Derivatives::generatable($asset) && ! Derivatives::paintsItself($asset);
    }

    /**
     * Whether anything is still owed a hash for this asset. A settled status,
     * ready or failed, closes the question; so does a hash already on the row,
     * which is what a library predating the status column looks like.
     */
    public static function wanted(MediaAsset $asset): bool
    {
        return self::applies($asset)
            && $asset->blurhash === null
            && $asset->blurhash_status?->isSettled() !== true;
    }

    /**
     * Compute and record the hash from bytes already in hand.
     *
     * Nothing here propagates. A hash is a nicety a card paints while it waits
     * for a picture, so bytes that will not decode are recorded as failed and
     * the caller carries on: an upload succeeds whether or not its file could
     * be hashed.
     */
    public static function fromBytes(MediaAsset $asset, string $bytes): void
    {
        if (! self::wanted($asset)) {
            return;
        }

        $raster = Raster::decode($bytes);

        self::write(
            $asset,
            $raster?->blurhash(),
            $raster === null ? BlurHashStatus::Failed : BlurHashStatus::Ready,
        );
    }

    /**
     * Record the hash from a raster another piece of work already decoded,
     * which is what makes the thumb job's top-up free.
     */
    public static function fromRaster(MediaAsset $asset, Raster $raster): void
    {
        if (! self::wanted($asset)) {
            return;
        }

        self::write($asset, $raster->blurhash(), BlurHashStatus::Ready);
    }

    /**
     * Written on the model in hand rather than through a query, so a caller
     * that goes on to use the asset sees what was recorded. It is a forced
     * fill because the status is the package's own bookkeeping and never
     * something a request body fills in.
     *
     * An unsaved asset is left for its own save to persist, which is what lets
     * ingest write the hash in the insert it was already making rather than in
     * a second write.
     */
    private static function write(MediaAsset $asset, ?string $hash, BlurHashStatus $status): void
    {
        $asset->forceFill(['blurhash' => $hash, 'blurhash_status' => $status]);

        if ($asset->exists) {
            $asset->save();
        }
    }
}
