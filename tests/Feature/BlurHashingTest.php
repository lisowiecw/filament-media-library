<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashing;
use Lisowiecw\MediaLibrary\Derivatives\CardPainting;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Derivatives\RegenerationTargets;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Forms\Components\LibraryGrid;
use Lisowiecw\MediaLibrary\Jobs\ComputeBlurHash;
use Lisowiecw\MediaLibrary\Jobs\GenerateDerivative;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

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

        // Recovered is settled like any other ready hash: the next render
        // paints the one string it found and asks for nothing.
        Bus::fake();

        expect(BlurHashing::hashFor($asset->fresh()))->toBe($asset->fresh()->blurhash);

        Bus::assertNotDispatched(ComputeBlurHash::class);
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
        // The allowance belongs to the wall-clock minute it is spent in, so a
        // boundary crossed mid-test would hand the next call a fresh one.
        $this->freezeTime();

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
        $this->freezeTime();

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
        $this->freezeTime();

        Bus::fake();
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 2);

        foreach (range(1, 4) as $i) {
            BlurHashing::hashFor(libraryAsset()->forceFill(['size' => 900_000]));
        }

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 2);
    });

    it('records a computed hash with the minute\'s allowance already spent', function (): void {
        $this->freezeTime();

        $asset = makeAsset(['size' => 900_000]);
        storeImage($asset);

        BlurHashing::dispatchLazily($asset);

        // Everything the minute had to give goes on other work while the job
        // is out reading the object, which is what a busy library looks like.
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 1);

        (new ComputeBlurHash($asset->id, $asset->fresh()->blurhash_pending_since->toDateTimeString()))->handle();

        // The read and the decode are already paid for, so the allowance has
        // nothing left to say: a hash dropped here would leave the row pending
        // until the abandoned window lapsed and buy the same work again.
        expect($asset->fresh()->blurhash)->toBeString()->not->toBeEmpty()
            ->and($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Ready);
    });

    it('settles a failure with the minute\'s allowance already spent', function (): void {
        $this->freezeTime();

        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);

        BlurHashing::dispatchLazily($asset);

        $claim = $asset->fresh()->blurhash_pending_since;

        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 1);

        (new ComputeBlurHash($asset->id, $claim->toDateTimeString()))
            ->failed(new RuntimeException('read failed'));

        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed);
    });

    it('spends nothing of the allowance on settling what it already computed', function (): void {
        $this->freezeTime();

        Bus::fake();
        config()->set('media-library.blurhash.lazy_dispatch.per_minute', 2);

        $first = libraryAsset()->forceFill(['size' => 900_000]);
        $first->save();
        storeImage($first);

        BlurHashing::dispatchLazily($first);
        (new ComputeBlurHash($first->id))->handle();

        // One hash asked for is one slot spent. Were settling to spend a
        // second, the minute would have nothing left for the card below.
        BlurHashing::hashFor(libraryAsset()->forceFill(['size' => 900_000]));

        Bus::assertDispatchedTimes(ComputeBlurHash::class, 2);
    });

    it('is what a card painting a placeholder asks', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000, 'visibility' => 'public']);

        (new CardPainting)->paint($asset);

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

    it('lets the dead worker\'s failure settle nothing once the claim has moved on', function (): void {
        Bus::fake();

        $asset = pendingAsset(now()->subHours(2)->toDateTimeString());

        // The render that found the claim lapsed takes it and queues a job of
        // its own; the worker that held it before is still on its last retry.
        BlurHashing::dispatchLazily($asset);

        $retaken = $asset->fresh()->blurhash_pending_since;

        (new ComputeBlurHash($asset->id, now()->subHours(2)->toDateTimeString()))
            ->failed(new RuntimeException('read failed'));

        // The failure belongs to a claim nobody holds any more, so it settles
        // nothing: the asset is still owed the hash the live job will write.
        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Pending)
            ->and($asset->fresh()->blurhash_pending_since->equalTo($retaken))->toBeTrue();
    });

    it('lets the worker holding the claim settle it as failed', function (): void {
        Bus::fake();

        $asset = makeAsset(['size' => 900_000]);

        BlurHashing::dispatchLazily($asset);

        $claim = $asset->fresh()->blurhash_pending_since;

        (new ComputeBlurHash($asset->id, $claim->toDateTimeString()))
            ->failed(new RuntimeException('read failed'));

        expect($asset->fresh()->blurhash_status)->toBe(BlurHashStatus::Failed)
            ->and($asset->fresh()->blurhash_pending_since)->toBeNull();
    });

    it('offers an abandoned asset to a backfill on the same terms', function (): void {
        Bus::fake();

        $abandoned = pendingAsset(now()->subHours(2)->toDateTimeString());
        $inFlight = pendingAsset(now()->subSeconds(30)->toDateTimeString());

        $targets = collect(iterator_to_array(RegenerationTargets::hashes(), false));

        // Reported as a lapsed claim rather than as an asset never asked, so a
        // dry run says which of the two a real run would be doing.
        expect($targets->map(fn (array $target): int => $target[0]->id)->all())->toBe([$abandoned->id])
            ->and($targets->map(fn (array $target): string => $target[1])->all())->toBe(['abandoned']);

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
