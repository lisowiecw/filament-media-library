<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashing;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
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
 * whether the library may describe it, and a payload that outlives a delete
 * resolves to nothing.
 */
class ComputeBlurHash implements ShouldQueue
{
    use Queueable;

    /**
     * Bytes that will not decode are not a transient failure and are recorded
     * rather than retried; the tries here are for the read.
     */
    public int $tries = 3;

    public function __construct(private readonly int $assetId) {}

    public function handle(): void
    {
        $asset = MediaAsset::query()->find($this->assetId);

        if ($asset === null) {
            return;
        }

        BlurHashing::fromBytes($asset, (string) Storage::disk($asset->disk)->get($asset->object_key));
    }

    /**
     * Once the retries are exhausted the status sticks at failed, so an object
     * this worker cannot read stops being asked for by every render that meets
     * its card.
     */
    public function failed(?Throwable $exception): void
    {
        $asset = MediaAsset::query()->find($this->assetId);

        if ($asset !== null) {
            BlurHashing::settleAsFailed($asset);
        }
    }
}
