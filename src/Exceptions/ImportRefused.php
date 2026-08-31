<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use RuntimeException;

/**
 * The run could not start. Everything an import declines row by row is an
 * omission in its report; this is for the two things that make the whole run
 * meaningless, both of which are declarations rather than data.
 */
class ImportRefused extends RuntimeException
{
    public static function unknownDisk(string $disk): self
    {
        return new self('The disk "'.$disk.'" is not configured. Name the disk the legacy paths '
            .'resolve against: an import never guesses one, because the same path is meaningful on several.');
    }

    public static function unknownModel(string $model): self
    {
        return new self('"'.$model.'" is not an Eloquent model. Name the host model whose column holds the legacy paths.');
    }

    public static function unknownVisibility(string $named): self
    {
        return new self('Visibility is public or private, not "'.$named.'".');
    }

    public static function unknownColumn(string $model, string $column): self
    {
        return new self('"'.$model.'" has no "'.$column.'" column to read legacy paths from.');
    }
}
