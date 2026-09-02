<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * Where a Derivative is in its one and only lifecycle: queued, written, or
 * given up on.
 *
 * Pending and failed both render as the same quiet tile, because what a person
 * browsing a grid can do about either is the same thing, which is nothing. The
 * difference matters to dispatch instead: a pending row is a job in flight and
 * is left alone, while a failed one has exhausted its retries and is never
 * re-dispatched on render.
 */
enum DerivativeStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Whether this status settles the question for good. A settled rendering
     * is what a surface stops waiting on: a ready one is the picture, and a
     * failed one has exhausted its retries and is re-dispatched by nothing
     * short of a command.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
