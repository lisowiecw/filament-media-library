<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Whether a card has anything left to wait for, and how often a surface asks
 * again while it has.
 *
 * A card is two pieces of work rather than one: the hash it paints colour
 * from, and the rendering that replaces it. Both have to have finished for the
 * card to be done, and failure counts as finished for both, because a file
 * that will never decode must stop a page requesting rather than keep it
 * going forever.
 *
 * The question lives here rather than in each surface for the same reason
 * `Derivatives::wanted()` does: the grid and the picker's inline items poll on
 * the same terms, and two answers would have one of them polling over a card
 * the other calls done.
 */
final readonly class CardResolution
{
    /**
     * Whether nothing this card shows can still change on its own.
     */
    public static function resolved(MediaAsset $asset): bool
    {
        return Derivatives::settled($asset, DerivativeVariant::Thumb)
            && ! BlurHashing::wanted($asset);
    }

    /**
     * Whether a page of cards has anything left to wait for.
     *
     * A card whose work the dispatch allowance declined counts as waiting,
     * which is right: the next ask is what queues it, so the page that could
     * not be served in one render is served over the next few rather than
     * never.
     *
     * @param  iterable<MediaAsset>  $assets
     */
    public static function pending(iterable $assets): bool
    {
        foreach ($assets as $asset) {
            if (! self::resolved($asset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How long a surface waits between asks, as Livewire's own duration
     * string. Configurable, because how quickly a card should heal is a
     * property of how quickly the deployment's queue runs.
     */
    public static function interval(): string
    {
        /** @var string $interval */
        $interval = config('media-library.poll_interval', '3s');

        return $interval;
    }
}
