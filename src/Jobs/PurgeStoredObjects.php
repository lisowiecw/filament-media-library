<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the objects a deleted asset leaves behind: its own bytes and every
 * rendering made from them.
 *
 * It carries keys rather than an asset id, because the row it would resolve is
 * exactly the row that has just gone, and because a force delete leaves
 * nothing at all to read the keys back from.
 *
 * There is no failure handling of its own on purpose. Retries are the queue's,
 * and an exhausted job lands in `failed_jobs` like any other, so an operator
 * retries a bucket outage with `queue:retry` rather than learning a bespoke
 * table of the package's own.
 */
class PurgeStoredObjects implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public readonly string $disk,
        public readonly array $keys,
    ) {}

    public function handle(): void
    {
        $disk = Storage::disk($this->disk);

        foreach ($this->keys as $key) {
            $disk->delete($key);
        }
    }
}
