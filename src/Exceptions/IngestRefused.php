<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use RuntimeException;

/**
 * An upload the ingest floor refused. Callers surface it as a validation
 * failure on the field rather than as an error, because nothing was stored.
 *
 * Every message names the types involved and the readable name, and never the
 * object key: a refusal happens before a key exists, and a key is not a fact a
 * person filling in a form has any use for.
 */
class IngestRefused extends RuntimeException
{
    public static function tooLarge(string $name, int $sizeInBytes, int $maxInKilobytes): self
    {
        return new self(sprintf(
            'The file "%s" is %d KB, which is over the %d KB limit.',
            $name,
            (int) ceil($sizeInBytes / 1024),
            $maxInKilobytes,
        ));
    }

    public static function blockedType(string $name, ?string $declaredType, ?string $sniffedType): self
    {
        return new self(sprintf(
            'The file "%s" is of a blocked type. It was declared as %s and its contents are %s.',
            $name,
            self::describe($declaredType),
            self::describe($sniffedType),
        ));
    }

    public static function unacceptedType(string $name, ?string $declaredType, ?string $sniffedType): self
    {
        return new self(sprintf(
            'The file "%s" is not an accepted type. It was declared as %s and its contents are %s.',
            $name,
            self::describe($declaredType),
            self::describe($sniffedType),
        ));
    }

    public static function familyMismatch(string $name, string $declaredType, string $sniffedType): self
    {
        return new self(sprintf(
            'The file "%s" was declared as %s but its contents are %s, which is a different kind of file.',
            $name,
            $declaredType,
            $sniffedType,
        ));
    }

    public static function activeContentOnPublicPlacement(string $name, ?string $sniffedType): self
    {
        return new self(sprintf(
            'The file "%s" is %s, which the browser would execute, so it cannot be uploaded on a public placement.',
            $name,
            self::describe($sniffedType),
        ));
    }

    public static function unsanitizableSvg(string $name): self
    {
        return new self(sprintf(
            'The file "%s" could not be sanitized as an SVG, so it was not stored.',
            $name,
        ));
    }

    public static function strictSvgDropped(string $name, string $element): self
    {
        return new self(sprintf(
            'The file "%s" contains a <%s> element, which is not allowed on a public placement.',
            $name,
            $element,
        ));
    }

    private static function describe(?string $type): string
    {
        return $type ?? 'of an unknown type';
    }
}
