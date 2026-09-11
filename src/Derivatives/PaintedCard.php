<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

/**
 * What one card shows for one asset, decided in a single pass.
 *
 * The four values are answered together rather than asked for one at a time,
 * because they are one decision: the hash the tile is painted from is the hash
 * emitted beside it, and both depend on the same View answer that decides
 * whether there is a thumbnail at all. Asking separately is what let a surface
 * re-ask for a hash it had just been handed.
 */
final readonly class PaintedCard
{
    /**
     * @param  string|null  $thumbnail  The preview image, or null where there is nothing to paint yet or ever.
     * @param  string|null  $blurhash  The asset's BlurHash, for a consumer that decodes it properly.
     * @param  string|null  $paint  The coarse painting of that hash, as an inline style.
     * @param  bool  $resolved  Whether nothing this card shows can still change on its own.
     */
    public function __construct(
        public ?string $thumbnail = null,
        public ?string $blurhash = null,
        public ?string $paint = null,
        public bool $resolved = true,
    ) {}
}
