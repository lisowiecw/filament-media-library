<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

/**
 * A type the browser would execute or script when rendered. Active content is
 * stored like any other file but never served inline, and never accepted onto
 * a public placement, where the Delivery route is not in the request path.
 *
 * The answer is read from the type rather than stored on the row, so a rule
 * tightened today covers everything already in the library.
 *
 * SVG is deliberately absent: it is the one exception, handled by sanitizing
 * it at upload rather than by refusing it.
 */
final readonly class ActiveContent
{
    /** @var list<string> */
    private const array TYPES = [
        'text/html',
        'text/xml',
        'application/xml',
        'application/xhtml+xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'application/ecmascript',
        'text/ecmascript',
        'application/x-msdownload',
        'application/x-dosexec',
        'application/vnd.microsoft.portable-executable',
        'application/x-sh',
        'application/x-httpd-php',
    ];

    /**
     * A type declaring itself executable counts too, whatever it is called.
     */
    public static function matches(?string $mimeType): bool
    {
        if ($mimeType === null) {
            return false;
        }

        $mimeType = strtolower(trim($mimeType));

        return in_array($mimeType, self::TYPES, strict: true)
            || str_contains($mimeType, 'executable');
    }
}
