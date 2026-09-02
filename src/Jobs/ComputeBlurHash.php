<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashing;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use RuntimeException;
use Throwable;

/**
 * Computes the BlurHash of an asset that arrived without one, off the request
 * cycle.
 *
 * This is the import path's half of the lifecycle. An upload has its bytes in
 * hand and hashes inline; an adopted object does not, so the first render that
 * wants a hash asks for one and the asset costs a single extra read.
 *
 * Like `GenerateDerivative` it is neither scoped nor policy-checked, and takes
 * an id rather than the model: tenancy governs who is offered an asset, not
 * whether the library may describe it, and a payload that outlives a hard
 * delete resolves to nothing.
 */
class ComputeBlurHash implements ShouldQueue
{
    use Queueable;

    /**
     * Bytes that will not decode are not a transient failure and are recorded
     * rather than retried; the tries here are for the read, which is the half
     * of this that a flaky bucket can lose and a second attempt can win.
     */
    public int $tries = 3;

    /**
     * A job that runs out of time has not decided anything about the bytes, so
     * it is routed through `failed()` rather than left holding the claim: the
     * pending status is the asset's only record that somebody is computing,
     * and a worker that never returns would otherwise keep it for good.
     */
    public bool $failOnTimeout = true;

    public function __construct(private readonly int $assetId) {}

    public function handle(): void
    {
        $asset = $this->asset();

        if ($asset === null) {
            return;
        }

        $bytes = Storage::disk($asset->disk)->get($asset->object_key);

        // A read that answers with nothing is the disk failing rather than the
        // file refusing to decode, so it is thrown and retried: settling it as
        // failed here would let one flaky read cost the asset its hash for
        // good.
        if ($bytes === null) {
            throw new RuntimeException('The stored object could not be read.');
        }

        BlurHashing::fromBytes($asset, $bytes);
    }

    /**
     * Once the retries are exhausted the status sticks at failed, so an object
     * this worker cannot read stops being asked for by every render that meets
     * its card.
     */
    public function failed(?Throwable $exception): void
    {
        $asset = $this->asset();

        if ($asset !== null) {
            BlurHashing::settleAsFailed($asset);
        }
    }

    /**
     * Trashed rows are resolved too, because the claim that queued this job
     * was made over the same set: a soft delete between the two would
     * otherwise strand the asset pending, still claimed and never computed,
     * and restoring it would not bring its colour back.
     */
    private function asset(): ?MediaAsset
    {
        return MediaAsset::withTrashed()->find($this->assetId);
    }
}
