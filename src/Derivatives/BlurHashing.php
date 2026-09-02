<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Illuminate\Database\Eloquent\Builder;
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
     * Whether anything is still owed a hash for this asset: one `Derivatives`
     * says is hashable at all, with nothing settled recorded against it. A
     * ready or failed status closes the question, and so does a hash already
     * on the row, which is what a library predating the status column looks
     * like.
     *
     * Read from the model in hand, so it answers for the render that asked.
     * It is not the guard that makes the write safe: two workers can both read
     * true here, which is what `write()` settles in the database.
     */
    public static function wanted(MediaAsset $asset): bool
    {
        return Derivatives::hashable($asset)
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
     * Where first-writer-wins is actually decided.
     *
     * The condition is carried into the update rather than asked beforehand,
     * because the model in hand was read before the decode and a decode takes
     * long enough for the other path to have finished in the meantime. Left to
     * a read and then a save, the slower of two workers would overwrite a
     * ready hash with its own, which is the one thing the two paths must never
     * do to each other. Here the database refuses the second writer, and the
     * model is only moved where the row was.
     *
     * An unsaved asset has no row to race for, so it is filled and left for
     * its own save: that is what lets ingest write the hash in the insert it
     * was already making rather than in a second write. The fill is forced
     * because this is bookkeeping the package writes about its own work, and
     * it should not depend on the column staying mass-assignable.
     */
    private static function write(MediaAsset $asset, ?string $hash, BlurHashStatus $status): void
    {
        $written = ['blurhash' => $hash, 'blurhash_status' => $status];

        if (! $asset->exists) {
            $asset->forceFill($written);

            return;
        }

        $claimed = MediaAsset::withTrashed()
            ->whereKey($asset->getKey())
            ->whereNull('blurhash')
            ->where(fn (Builder $query) => $query
                ->whereNull('blurhash_status')
                ->orWhere('blurhash_status', BlurHashStatus::Pending->value))
            ->update(['blurhash' => $hash, 'blurhash_status' => $status->value]);

        if ($claimed > 0) {
            $asset->forceFill($written)->syncOriginal();
        }
    }
}
