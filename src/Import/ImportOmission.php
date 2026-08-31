<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * Why a legacy path was declined rather than adopted. An import report names
 * omissions rather than successes, so this is the whole vocabulary it prints.
 *
 * None of these is a failure: a run that declines rows is still a successful
 * run, and the reason is what a later run is diffed against.
 */
enum ImportOmission: string
{
    /** The column held nothing, or held only whitespace. */
    case EmptyValue = 'empty-value';

    /** The disk has no object at that key, so no row may claim there is one. */
    case MissingObject = 'missing-object';

    /** A type the package refuses everywhere, reported by path. */
    case BlockedType = 'blocked-type';

    /** The disk would not say how large the object is. */
    case UnreadableMetadata = 'unreadable-metadata';

    /** Copy mode found something already at the destination key. */
    case DestinationOccupied = 'destination-occupied';

    /** An element of a multi-value column held nothing, or only whitespace. */
    case EmptyElement = 'empty-element';

    /** An element was a number or a flag rather than text, so it names no key. */
    case NonTextElement = 'non-text-element';

    /** The same key appeared twice in one column, so one field context holds it once. */
    case DuplicateElement = 'duplicate-element';

    public function label(): string
    {
        return match ($this) {
            self::NonTextElement => 'the element was not text, so it names no key',
            self::EmptyValue => 'the column held nothing',
            self::MissingObject => 'no object at that key',
            self::BlockedType => 'a blocked type',
            self::UnreadableMetadata => 'the disk would not report its size',
            self::DestinationOccupied => 'the destination key is occupied',
            self::EmptyElement => 'the element held nothing',
            self::DuplicateElement => 'the same key twice in one column',
        };
    }
}
