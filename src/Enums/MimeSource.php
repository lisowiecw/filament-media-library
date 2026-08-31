<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Enums;

/**
 * Which rung of the resolution ladder produced a Media Asset's mime type.
 */
enum MimeSource: string
{
    case Header = 'header';
    case Sniffed = 'sniffed';
    case Extension = 'extension';
    case Unknown = 'unknown';

    /**
     * Whether this rung is a stronger claim about the type than another one.
     *
     * The order is confidence rather than the ladder's own visiting order: a
     * sniff measured the bytes, a stored header only repeats what whoever
     * wrote the object claimed, a filename asserts less again, and nothing
     * asserts nothing. It is asked wherever a re-resolution decides to write,
     * so a second pass can only ever raise what a row claims, never lower it,
     * and a measured type is never overwritten by a claim.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Sniffed => 3,
            self::Header => 2,
            self::Extension => 1,
            self::Unknown => 0,
        };
    }
}
