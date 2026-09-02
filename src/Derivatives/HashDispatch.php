<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

/**
 * The cap on how much hashing a page of cards is allowed to ask for.
 *
 * It is the same counter as `LazyDispatch` with a budget of its own, because
 * the two costs are not alike. A hash is a read and a decode: it scales
 * nothing, encodes nothing, and writes nothing back to the object store, so
 * rationing it at the rate of a WebP encode is what left an imported library
 * grey. It is still capped, because a read is still billed and a traversal
 * import over a large bucket would otherwise stampede.
 */
class HashDispatch extends LazyDispatch
{
    public const int DEFAULT_PER_MINUTE = 300;

    /**
     * A whole page of cards, as with generation: the page somebody is looking
     * at is the page that gets hashed.
     */
    public const int DEFAULT_PER_REQUEST = 48;

    protected function allowance(): string
    {
        return 'blurhash';
    }
}
