<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use Lisowiecw\MediaLibrary\Lifecycle\UsageEntry;
use RuntimeException;

/**
 * A delete refused because something still uses the asset.
 *
 * The usage list travels with the refusal rather than being fetched again by
 * whatever caught it: the person is about to be shown the list and then asked
 * whether to force the delete, and the list they review has to be the list the
 * block was made on.
 */
class DeleteBlocked extends RuntimeException
{
    /**
     * @param  list<UsageEntry>  $usage
     */
    public function __construct(public readonly array $usage)
    {
        parent::__construct(sprintf(
            'The asset is still used in %d place(s), so it was not deleted. Force delete to override.',
            count($usage),
        ));
    }
}
