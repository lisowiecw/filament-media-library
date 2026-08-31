<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * Where a run discovers the objects it adopts.
 *
 * The column is the real one: the row holding the path is the row that knows
 * who owned it and which field it filled, so it is the only source that can
 * produce attachments at all. Traversal is an explicitly degraded fallback for
 * a legacy layout that has no column to read, and everything it cannot know it
 * leaves unrecorded rather than guessing.
 */
enum DiscoverySource: string
{
    /** A declared host model and column hold the legacy paths. */
    case Column = 'column';

    /** A prefix on the disk is walked, with no host and no field context. */
    case Disk = 'disk';
}
