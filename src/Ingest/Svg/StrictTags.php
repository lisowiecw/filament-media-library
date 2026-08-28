<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest\Svg;

use enshrined\svgSanitize\data\AllowedTags;
use enshrined\svgSanitize\data\TagInterface;

/**
 * The sanitizer's own tag allowlist with the three elements the Strict pass
 * drops removed: an embedded raster, a style block and a link.
 */
final class StrictTags implements TagInterface
{
    /** @var list<string> */
    private const array DROPPED = ['image', 'style', 'a'];

    /**
     * @return list<string>
     */
    public static function getTags(): array
    {
        /** @var list<string> $tags */
        $tags = AllowedTags::getTags();

        return array_values(array_diff($tags, self::DROPPED));
    }
}
