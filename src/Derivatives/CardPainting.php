<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Closure;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Ingest\TypeFamily;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * How a field paints one asset as a card, and whether the surface showing
 * those cards asks again.
 *
 * It is constructed per field, because the field's Thumbnail rule is half the
 * answer, and it is asked per asset, because a card is a decision about one
 * asset rather than about a page. The library grid and the picker's inline
 * items both go through it, so the same asset can never be a thumbnail on one
 * surface and a glyph tile on the other, and neither surface assembles a card
 * out of the pieces itself.
 *
 * View is asked here rather than by the surface, which makes structural what
 * the glossary already states: a Thumbnail rule is never handed an asset the
 * viewer may not be delivered.
 */
final readonly class CardPainting
{
    /**
     * @param  (Closure(MediaAsset): ?string)|null  $rule  The field's Thumbnail rule, already bound to whatever
     *                                                     evaluates it, or null where the field never named one.
     */
    public function __construct(private ?Closure $rule = null) {}

    /**
     * Everything one card shows, resolved in one pass, since resolving is what
     * queues a missing thumbnail and a missing hash.
     *
     * A card with nothing to preview is a glyph tile: no rule is asked for it,
     * no hash is queued, and nothing waits on it.
     */
    public function paint(MediaAsset $asset): PaintedCard
    {
        if (! $this->previewable($asset)) {
            return new PaintedCard;
        }

        $hash = BlurHashing::hashFor($asset);

        return new PaintedCard(
            thumbnail: $this->rule === null ? Derivatives::thumbnailUrl($asset) : ($this->rule)($asset),
            blurhash: $hash,
            paint: $hash === null ? null : BlurHashPaint::css($hash),
            resolved: $this->resolved($asset),
        );
    }

    /**
     * Whether a surface showing these assets has anything left to wait for.
     *
     * It answers without painting, because the decision is taken before the
     * cards render and the page the surface holds is what decides: once every
     * card on it is ready or failed the surface stops asking, so an idle grid
     * over a fully generated library costs nothing.
     *
     * @param  iterable<MediaAsset>  $assets
     */
    public function pending(iterable $assets): bool
    {
        if (! $this->paintsThroughPipeline()) {
            return false;
        }

        foreach ($assets as $asset) {
            if ($this->previewable($asset) && ! $this->resolved($asset)) {
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
    public function interval(): string
    {
        /** @var string $interval */
        $interval = config('media-library.poll_interval', '3s');

        return $interval;
    }

    /**
     * Whether the package's own pipeline is what paints this field's cards. A
     * field that resolves its own thumbnails is waiting on nothing the package
     * can settle, so neither surface asks again for it.
     */
    public function paintsThroughPipeline(): bool
    {
        return $this->rule === null;
    }

    /**
     * Whether this card may paint the asset's own content at all. View is
     * asked for every card, in the order the cards paint; the per-request
     * cache in MediaAuthorization is what keeps a grid of 48 to 48 evaluations
     * however often it re-renders.
     *
     * Listing is never gated on the answer: an asset the viewer may not be
     * delivered is still offered and still selectable, since offering shows
     * metadata rather than content.
     */
    private function previewable(MediaAsset $asset): bool
    {
        return is_string($asset->mime_type)
            && TypeFamily::of($asset->mime_type) === 'image'
            && app(MediaAuthorization::class)->allowsView($asset);
    }

    /**
     * Whether nothing this card shows can still change on its own.
     *
     * A card is two pieces of work rather than one: the hash it paints colour
     * from, and the rendering that replaces it. Both have to have finished,
     * and failure counts as finished for both, because a file that will never
     * decode must stop a page asking rather than keep it going forever.
     *
     * A card whose work the dispatch allowance declined counts as unresolved,
     * which is right: the next ask is what queues it, so a page that could not
     * be served in one render is served over the next few rather than never.
     */
    private function resolved(MediaAsset $asset): bool
    {
        return ! $this->paintsThroughPipeline()
            || (Derivatives::settled($asset, DerivativeVariant::Thumb) && ! BlurHashing::wanted($asset));
    }
}
