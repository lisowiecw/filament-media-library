<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

use Symfony\Component\Mime\MimeTypes;

/**
 * The top-level family of a mime type, and the one comparison the floor makes
 * with it: bytes that land in a different family than the filename claims are
 * deception rather than the routine disagreement a re-gate absorbs.
 */
final readonly class TypeFamily
{
    /**
     * The sniff for bytes nothing recognises. It names no family, so comparing
     * it to an extension would refuse every unremarkable binary.
     */
    private const string UNRECOGNISED = 'application/octet-stream';

    /**
     * True when both sides name a family and the families differ. An extension
     * mapping to several types agrees as long as one of them agrees.
     */
    public static function mismatched(?string $extension, ?string $sniffedType): bool
    {
        if ($sniffedType === null || $sniffedType === self::UNRECOGNISED) {
            return false;
        }

        $sniffed = self::of($sniffedType);
        $declared = self::typesFor($extension);

        foreach ($declared as $type) {
            if (self::of($type) === $sniffed) {
                return false;
            }
        }

        return $declared !== [];
    }

    /**
     * Every mime type the extension is known by, in the library's own order.
     *
     * @return list<string>
     */
    public static function typesFor(?string $extension): array
    {
        return $extension === null ? [] : array_values(MimeTypes::getDefault()->getMimeTypes(strtolower($extension)));
    }

    public static function of(string $mimeType): string
    {
        return strtolower(explode('/', $mimeType, 2)[0]);
    }
}
