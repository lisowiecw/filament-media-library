<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use RuntimeException;

/**
 * A write the package will not perform on the ids it was handed.
 *
 * The grid only offers a tenant its own assets, but the grid is ergonomics
 * rather than a wall: a reconcile is reachable from application code that
 * never saw it. These are the refusals that make the boundary hold there too.
 *
 * No message names an asset id, so a caller that guessed one is never told
 * back which of its guesses was the problem. What a message may say about
 * existence differs by refusal and is argued at each one: the boundary ADR 7
 * draws is around what a *viewer* can learn, and the picker is the surface a
 * viewer has, which keeps one wording for everything.
 */
class AttachRefused extends RuntimeException
{
    /**
     * The reconcile asked for an id that names no live asset.
     *
     * Only the reconciler tells this apart from a tenant mismatch. A viewer
     * picking ids never arrives here: the picker's own rule asks reach during
     * validation and fails the save with its single wording before any
     * reconcile runs, so the pair of messages is reachable by a caller
     * writing its own reconcile, for whom an id resolving to nothing is a bug
     * in its own code rather than a probe of somebody else's tenant. That is
     * the reading of ADR 7 this rests on: the boundary is what a viewer can
     * learn, not what an application can learn about ids it already holds.
     */
    public static function unknownAsset(): self
    {
        return new self('No such media asset: an id was asked for that names nothing the library holds.');
    }

    public static function tenantMismatch(): self
    {
        return new self('An asset outside the current tenant cannot be attached.');
    }

    public static function tenantIsNotReassignable(): self
    {
        return new self('A media asset is stamped with its tenant once and is never moved between tenants. An unowned asset can be claimed instead.');
    }
}
