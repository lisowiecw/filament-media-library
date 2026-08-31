<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * The degraded fallback: a prefix on the disk is walked, with no host row and
 * no field context behind any of it.
 *
 * It exists for a legacy layout that has no column to read, and everything it
 * cannot know it leaves unrecorded rather than guessing. That includes the
 * attachments: there is no row to attach to, which is a property of this kind
 * of run rather than of any particular one.
 */
final readonly class TraversalDiscovery extends Discovery
{
    public function __construct(public string $prefix) {}

    public function importSource(): string
    {
        return 'disk:'.$this->prefix;
    }

    public function canAttach(): bool
    {
        return false;
    }

    public function cardinality(): Cardinality
    {
        return Cardinality::Single;
    }
}
