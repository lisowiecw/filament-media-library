<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * Where an asset's BlurHash is in its lifecycle: asked for, computed, or given
 * up on.
 *
 * The absence of a status is a fourth state and the one most worth naming: it
 * means nobody has ever asked, which is what tells a render there is work to
 * do. Pending means a computation is in flight and must not be started again;
 * failed means the bytes could not be decoded, and is never asked again by any
 * path.
 *
 * It mirrors DerivativeStatus without being it, because a hash is a fact about
 * the asset rather than a stored object: it has no key, no variant and no
 * staleness. See ADR 18.
 */
enum BlurHashStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Whether this status settles the question for good. A settled hash is
     * never recomputed, whichever path arrives next: a ready one is never
     * overwritten, and a failed one is never quietly turned ready.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }
}
