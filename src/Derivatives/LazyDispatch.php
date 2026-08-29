<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Illuminate\Support\Facades\Cache;

/**
 * The cap on how much generation a page of cards is allowed to ask for.
 *
 * The first person to browse a freshly imported library meets a grid where
 * nothing has a rendering yet. Without a cap, that one scroll queues a job per
 * card, and every read and write in it is billed to the operator. So the
 * backfill trickles: a few per render, a bounded number per minute, and the
 * rest heal on a later visit.
 *
 * Eager generation at upload does not come through here. That is one job for
 * one deliberate act, and delaying it would leave a fresh upload without the
 * card the person who just made it is looking at.
 */
class LazyDispatch
{
    public const int DEFAULT_PER_MINUTE = 60;

    public const int DEFAULT_PER_REQUEST = 5;

    /**
     * Scoped to one request, so a render's own allowance cannot be spent twice
     * by a component that rebuilds itself mid-request.
     */
    private int $spentThisRequest = 0;

    /**
     * Whether one more lazy job may be dispatched, spending the allowance when
     * the answer is yes.
     *
     * The per-minute half is a counter rather than a lock: two workers racing
     * it can overshoot by one, which is a cap doing its job rather than a
     * queue admission control that has to be exact.
     */
    public function allows(): bool
    {
        if ($this->spentThisRequest >= $this->perRequest()) {
            return false;
        }

        $key = 'media-library:lazy-dispatch:'.now()->format('YmdHi');

        /** @var int $spent */
        $spent = Cache::get($key, 0);

        if ($spent >= $this->perMinute()) {
            return false;
        }

        // Two minutes, so the key outlives the minute it counts and is never
        // read back after the window it belongs to has passed.
        Cache::put($key, $spent + 1, 120);

        $this->spentThisRequest++;

        return true;
    }

    private function perMinute(): int
    {
        /** @var int $limit */
        $limit = config('media-library.derivatives.lazy_dispatch.per_minute', self::DEFAULT_PER_MINUTE);

        return $limit;
    }

    private function perRequest(): int
    {
        /** @var int $limit */
        $limit = config('media-library.derivatives.lazy_dispatch.per_request', self::DEFAULT_PER_REQUEST);

        return $limit;
    }
}
