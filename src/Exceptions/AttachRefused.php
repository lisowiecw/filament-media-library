<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use RuntimeException;

/**
 * A write across the tenant boundary that the package will not perform.
 *
 * The grid only offers a tenant its own assets, but the grid is ergonomics
 * rather than a wall: a reconcile is reachable from application code that
 * never saw it. These are the refusals that make the boundary hold there too.
 *
 * No message names an asset id. A caller that guessed one learns whether it
 * was refused, never whether it exists.
 */
class AttachRefused extends RuntimeException
{
    public static function tenantMismatch(): self
    {
        return new self('An asset outside the current tenant cannot be attached.');
    }

    public static function tenantIsNotReassignable(): self
    {
        return new self('A media asset is stamped with its tenant once and is never moved between tenants. An unowned asset can be claimed instead.');
    }
}
