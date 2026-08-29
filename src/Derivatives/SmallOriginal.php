<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The rule that keeps logos and icons free: an original a browser can already
 * render, small enough in both bytes and pixels, gets no derivative rows at
 * all and is painted as itself.
 *
 * The two halves are asked separately on purpose. Pixels are known only where
 * the bytes are in hand, which is at generation time; a card resolving a
 * thumbnail has the row and nothing else, and the byte ceiling alone is what
 * makes painting the original safe there.
 */
final readonly class SmallOriginal
{
    /**
     * The types a browser renders without help. A tiff or a heic is small
     * enough to be cheap and still unpaintable, so it is not on the list.
     *
     * @var list<string>
     */
    public const array RENDERABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const int DEFAULT_BYTES = 32 * 1024;

    public const int DEFAULT_EDGE = 800;

    /**
     * Whether a card may point straight at this asset's own object rather than
     * wait for a rendering of it.
     */
    public static function paintsOriginal(MediaAsset $asset): bool
    {
        return self::renderable($asset->mime_type)
            && $asset->size !== null
            && $asset->size <= self::bytesCeiling();
    }

    /**
     * Whether this asset earns no derivatives at all. Asked where the pixels
     * are known, since an image can be tiny in bytes and enormous on screen.
     */
    public static function needsNoDerivatives(MediaAsset $asset, int $longestEdge): bool
    {
        return self::paintsOriginal($asset) && $longestEdge < self::edgeCeiling();
    }

    public static function renderable(?string $mimeType): bool
    {
        return $mimeType !== null && in_array(strtolower($mimeType), self::RENDERABLE, strict: true);
    }

    public static function bytesCeiling(): int
    {
        /** @var int $bytes */
        $bytes = config('media-library.derivatives.small_original.bytes', self::DEFAULT_BYTES);

        return $bytes;
    }

    public static function edgeCeiling(): int
    {
        /** @var int $edge */
        $edge = config('media-library.derivatives.small_original.edge', self::DEFAULT_EDGE);

        return $edge;
    }
}
