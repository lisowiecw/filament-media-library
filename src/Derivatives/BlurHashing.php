<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Jobs\ComputeBlurHash;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The one place a BlurHash is written, and the contract between the paths that
 * write it: ingest, which has the bytes in hand, the thumb job, which tops up
 * an asset that arrived without one, and the read the first card or an
 * operator's backfill asks for.
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
     * How long an asset may sit at pending before the next render treats the
     * computation as nobody's and asks again, in seconds.
     *
     * Fifteen minutes is far longer than a read plus a decode, which is the
     * direction this has to err in: taking a hash still being computed costs a
     * second read of the same object, while waiting too long only leaves a
     * card grey a while longer after a crash that was going to leave it grey
     * for good.
     */
    public const int DEFAULT_ABANDONED_AFTER = 900;

    /**
     * The hash a card paints from, or null while there is none, asking for one
     * where the asset is owed it.
     *
     * This is the import path's way in, and the mirror of what
     * `Derivatives::thumbnailUrl()` does for a picture: the first render that
     * finds nothing to paint is what queues the work, so nothing sweeps and an
     * adopted object costs its one extra read only once somebody looks at it.
     */
    public static function hashFor(MediaAsset $asset): ?string
    {
        if ($asset->blurhash !== null) {
            return $asset->blurhash;
        }

        self::dispatchLazily($asset);

        return null;
    }

    /**
     * Ask for a hash, behind the hash allowance, marking the asset pending
     * first.
     *
     * The status is claimed in the database rather than on the model, and the
     * job is queued only where the claim was won, because that claim is the
     * whole of what stops two renders of the same card queueing the same read
     * twice. A render that loses it paints the quiet tile and asks nothing.
     *
     * The allowance is spent before the claim, so a render that loses the race
     * has spent one out of a budget it queued nothing against. That is a cap
     * erring on the low side by a card, which is the direction it should err
     * in, and the alternative is a claim that has to be rolled back.
     */
    public static function dispatchLazily(MediaAsset $asset): void
    {
        if (! self::claimable($asset)) {
            return;
        }

        if (! app(HashDispatch::class)->allows()) {
            return;
        }

        self::claimAndDispatch($asset);
    }

    /**
     * Ask for a hash on behalf of an operator backfilling a library, which is
     * the same claim without the render's allowance.
     *
     * The budget is not skipped, it is spent elsewhere: a backfill run waits
     * out the per-minute cap before it gets here, and the per-request half is
     * sized to a page of cards and has nothing to say about a run with no
     * page. Everything that makes the claim safe stays where it was, so a
     * backfill and a render arriving at the same asset still cannot both queue
     * it.
     */
    public static function backfill(MediaAsset $asset): bool
    {
        if (! self::claimable($asset)) {
            return false;
        }

        return self::claimAndDispatch($asset);
    }

    /**
     * Whether it is worth trying to claim this asset: one that is owed a hash
     * with nobody already computing it. A pending row is somebody else's claim
     * and is left alone, which is what keeps a backfill off the assets a
     * render has already asked for.
     *
     * Somebody else's, that is, while the claim is young enough to be anybody
     * at all. A worker killed outright settles nothing, so a pending status
     * old enough to have outlived the computation it stood for is treated as
     * abandoned and may be taken again.
     */
    private static function claimable(MediaAsset $asset): bool
    {
        if (! self::wanted($asset)) {
            return false;
        }

        return $asset->blurhash_status === null
            || ($asset->blurhash_status === BlurHashStatus::Pending
                && self::abandoned($asset->blurhash_pending_since));
    }

    /**
     * Whether a pending status has been held longer than a computation could
     * honestly take.
     *
     * A pending row with no time at all is abandoned, not fresh: those are the
     * rows written before the column existed, stranded by exactly the crash
     * this releases, and reading them as fresh would strand them for good.
     */
    private static function abandoned(?DateTimeInterface $pendingSince): bool
    {
        return $pendingSince === null || AbandonedWindow::hash()->lapsed($pendingSince);
    }

    /**
     * Narrow a query to the assets a hash may be asked for: owed one, with
     * nobody computing it that is still anybody.
     *
     * This is the SQL half of `claimable()`, and the two are kept beside each
     * other because they answer the same question of a row and of a model in
     * hand. The claim carries it into an update, where it is what settles the
     * race; a backfill's selector carries it into a read, so a dry run reports
     * the set a real run would queue.
     *
     * @param  Builder<MediaAsset>  $query
     * @return Builder<MediaAsset>
     */
    public static function unclaimed(Builder $query): Builder
    {
        return $query
            ->whereNull('blurhash')
            ->where(fn (Builder $status) => $status
                ->whereNull('blurhash_status')
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('blurhash_status', BlurHashStatus::Pending->value)
                    ->where(fn (Builder $stale) => $stale
                        ->whereNull('blurhash_pending_since')
                        ->orWhere('blurhash_pending_since', '<', AbandonedWindow::hash()->before()))));
    }

    /**
     * Take the pending status in the database and queue the read where the
     * claim was won, which is the half both ways in share.
     *
     * The condition matches a status nobody has taken, or one taken so long
     * ago that whoever took it is gone. Both are settled in the update rather
     * than beforehand, so two renders meeting the same abandoned asset still
     * queue one job between them: the second finds a pending status stamped a
     * moment ago and matches nothing.
     */
    private static function claimAndDispatch(MediaAsset $asset): bool
    {
        $now = CarbonImmutable::now();

        $claimed = self::unclaimed(MediaAsset::withTrashed()->whereKey($asset->getKey()))
            ->update([
                'blurhash_status' => BlurHashStatus::Pending->value,
                'blurhash_pending_since' => $now,
            ]);

        if ($claimed === 0) {
            return false;
        }

        $asset->forceFill([
            'blurhash_status' => BlurHashStatus::Pending,
            'blurhash_pending_since' => $now,
        ])->syncOriginal();

        ComputeBlurHash::dispatch($asset->getKey(), $now->toDateTimeString());

        return true;
    }

    /**
     * Give up on an asset whose object could not even be read, so no later
     * render asks for it again. A hash that landed by another path in the
     * meantime keeps the row, as everywhere else here.
     *
     * Where the caller names the claim it was working under, the row is only
     * settled while that claim is still the one recorded: a worker whose claim
     * lapsed and was retaken has nothing left to say about the asset, and
     * saying it would close the question against the job that replaced it.
     */
    public static function settleAsFailed(MediaAsset $asset, ?string $claimedAt = null): void
    {
        self::write($asset, null, BlurHashStatus::Failed, $claimedAt);
    }

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
     * Where first-writer-wins is decided for a computed hash, as claiming the
     * pending status is for the right to compute one at all.
     *
     * The condition is carried into the update rather than asked beforehand,
     * because the model in hand was read before the decode and a decode takes
     * long enough for the other path to have finished in the meantime. Left to
     * a read and then a save, the slower of two workers would overwrite a
     * ready hash with its own, which is the one thing the two paths must never
     * do to each other. Here the database refuses the second writer, and the
     * model is only moved where the row was.
     *
     * A settling status clears the pending time with it, so the column never
     * describes a hash that is ready or failed and a settled row can never be
     * read as an abandoned computation.
     *
     * An unsaved asset has no row to race for, so it is filled and left for
     * its own save: that is what lets ingest write the hash in the insert it
     * was already making rather than in a second write. The fill is forced
     * because this is bookkeeping the package writes about its own work, and
     * it should not depend on the column staying mass-assignable.
     */
    private static function write(MediaAsset $asset, ?string $hash, BlurHashStatus $status, ?string $claimedAt = null): void
    {
        $written = ['blurhash' => $hash, 'blurhash_status' => $status, 'blurhash_pending_since' => null];

        if (! $asset->exists) {
            $asset->forceFill($written);

            return;
        }

        if (! app(HashDispatch::class)->allows()) {
            return;
        }

        $claimed = MediaAsset::withTrashed()
            ->whereKey($asset->getKey())
            ->whereNull('blurhash')
            ->where(fn (Builder $query) => $query
                ->whereNull('blurhash_status')
                ->orWhere('blurhash_status', BlurHashStatus::Pending->value))
            ->when($claimedAt !== null, fn (Builder $query) => $query
                ->where('blurhash_pending_since', $claimedAt))
            ->update([
                'blurhash' => $hash,
                'blurhash_status' => $status->value,
                'blurhash_pending_since' => null,
            ]);

        if ($claimed > 0) {
            $asset->forceFill($written)->syncOriginal();
        }
    }
}
