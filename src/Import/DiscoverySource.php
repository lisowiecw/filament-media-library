<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * The accepted values of `--source`, and nothing below the command line reads
 * this.
 *
 * It exists to name the strings an operator may type and to refuse the ones
 * they may not. What each kind of run can actually do is the Discovery's to
 * say, and asking this enum instead would be branching on a label rather than
 * on the thing it labels. See ADR 15.
 */
enum DiscoverySource: string
{
    /** A declared host model and column hold the legacy paths. */
    case Column = 'column';

    /** A prefix on the disk is walked, with no host and no field context. */
    case Disk = 'disk';
}
