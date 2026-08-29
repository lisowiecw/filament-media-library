<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashPaint;
use Lisowiecw\MediaLibrary\Ingest\TypeFamily;
use Lisowiecw\MediaLibrary\Library\Facets\Facet;
use Lisowiecw\MediaLibrary\Library\FacetSidebar;
use Lisowiecw\MediaLibrary\Library\LibrarySearch;
use Lisowiecw\MediaLibrary\Library\LibrarySort;
use Lisowiecw\MediaLibrary\Library\OfferScope;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The Library tab's body: a facet sidebar and a search box over what the Offer
 * scope lists, a sort select, an infinitely lengthening grid of cards, and the
 * live ordered selection.
 *
 * Everything the tab needs lives in this one field's state, as `search`,
 * `filters`, `sort`, `loaded` and `selection`, rather than in sibling fields
 * reaching across each other. That is what lets a narrowing reset the
 * selection in a single hook, with the old state still in hand.
 *
 * Selection is a list of ids in the order they were clicked, and it is the
 * order the confirm attaches them in.
 */
class LibraryGrid extends Field
{
    /**
     * How many more cards a scroll to the bottom, or the button beside it,
     * asks for. Fixed by the package: the grid has no page-size control and no
     * numbered pages.
     */
    public const int BATCH = 48;

    protected string $view = 'media-library::forms.components.library-grid';

    protected Closure|OfferScope|null $offerScope = null;

    protected Closure|int|null $selectionLimit = null;

    protected ?Closure $thumbnailUsing = null;

    protected Closure|string|null $dropTargetKey = null;

    protected Closure|string|null $dropStatePath = null;

    /**
     * The sidebar for the state it was built from, so one render's counts,
     * facet list and results all come from a single set of queries, and a
     * later state gets a fresh one rather than the stale one.
     *
     * @var array{string, FacetSidebar}|null
     */
    private ?array $sidebar = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->dehydrated();
        $this->default(self::blankState());

        $this->afterStateUpdated(static function (LibraryGrid $component, mixed $state, mixed $old): void {
            $component->state($component->reactToNarrowing($state, $old));
        });
    }

    /**
     * @return array{search: string, filters: array<string, list<string>>, sort: string, loaded: int, selection: list<int>, reset: bool}
     */
    public static function blankState(): array
    {
        return [
            'search' => '',
            'filters' => [],
            'sort' => LibrarySort::Newest->value,
            'loaded' => self::BATCH,
            'selection' => [],
            'reset' => false,
        ];
    }

    public function offerScope(Closure|OfferScope|null $scope): static
    {
        $this->offerScope = $scope;

        return $this;
    }

    /**
     * How many assets the field can hold. The grid honours it while picking,
     * so what the footer shows is what the field ends up with rather than a
     * list the picker quietly truncates on confirm.
     */
    public function selectionLimit(Closure|int|null $limit): static
    {
        $this->selectionLimit = $limit;

        return $this;
    }

    /**
     * How the field this grid belongs to resolves a card's preview image.
     */
    public function thumbnailUsing(?Closure $callback): static
    {
        $this->thumbnailUsing = $callback;

        return $this;
    }

    /**
     * The picker component a drop on this tab body belongs to, and where the
     * browser stages that drop. Null while the owning field is not droppable,
     * which is what leaves the tab body an offer to browse and nothing else.
     */
    public function dropTargetKey(Closure|string|null $key): static
    {
        $this->dropTargetKey = $key;

        return $this;
    }

    public function dropStatePath(Closure|string|null $path): static
    {
        $this->dropStatePath = $path;

        return $this;
    }

    public function getDropTargetKey(): ?string
    {
        /** @var string|null $key */
        $key = $this->evaluate($this->dropTargetKey);

        return $key;
    }

    public function getDropStatePath(): ?string
    {
        /** @var string|null $path */
        $path = $this->evaluate($this->dropStatePath);

        return $path;
    }

    /**
     * The preview URL for a card that may paint one. It is asked for only
     * after canPreview() has said yes, so a field's own callback is never
     * handed an asset the viewer may not be delivered. The owning field always
     * supplies the rule, so there is no second answer to the same question
     * here.
     */
    public function thumbnailUrl(MediaAsset $asset): ?string
    {
        /** @var string|null $url */
        $url = $this->evaluate($this->thumbnailUsing, ['asset' => $asset], [MediaAsset::class => $asset]);

        return $url;
    }

    public function getSelectionLimit(): ?int
    {
        /** @var int|null $limit */
        $limit = $this->evaluate($this->selectionLimit);

        return $limit;
    }

    public function getOfferScope(): OfferScope
    {
        /** @var OfferScope $scope */
        $scope = $this->evaluate($this->offerScope);

        return $scope;
    }

    /**
     * A narrowing resets the selection rather than carrying it into a result
     * set it is no longer visible in. A changed search and a changed facet are
     * the same act to the grid, so they share one hook; a changed sort is not,
     * since reordering never takes a card away.
     *
     * The reset is remembered on the state so the view can say it happened,
     * and only when something was actually dropped: announcing a reset of
     * nothing is noise.
     *
     * @return array{search: string, filters: array<string, list<string>>, sort: string, loaded: int, selection: list<int>, reset: bool}
     */
    public function reactToNarrowing(mixed $state, mixed $old): array
    {
        $state = $this->normaliseState($state);
        $old = $this->normaliseState($old);

        $narrowed = $state['search'] !== $old['search'] || $state['filters'] !== $old['filters'];

        if (! $narrowed) {
            $state['reset'] = false;

            return $state;
        }

        return [
            ...$state,
            'loaded' => self::BATCH,
            'selection' => [],
            'reset' => $old['selection'] !== [],
        ];
    }

    /**
     * @return array{search: string, filters: array<string, list<string>>, sort: string, loaded: int, selection: list<int>, reset: bool}
     */
    public function normaliseState(mixed $state): array
    {
        $state = is_array($state) ? $state : [];

        $selection = is_array($state['selection'] ?? null) ? $state['selection'] : [];

        return [
            'search' => is_string($state['search'] ?? null) ? $state['search'] : '',
            'filters' => self::normaliseFilters($state['filters'] ?? null),
            'sort' => LibrarySort::of($state['sort'] ?? null)->value,
            'loaded' => max(self::BATCH, (int) ($state['loaded'] ?? self::BATCH)),
            'selection' => array_values(array_unique(array_map(intval(...), $selection))),
            'reset' => (bool) ($state['reset'] ?? false),
        ];
    }

    /**
     * The ticked options as the state holds them, before the sidebar decides
     * which of them it actually lists.
     *
     * @return array<string, list<string>>
     */
    private static function normaliseFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $normalised = [];

        foreach ($filters as $key => $options) {
            $options = is_array($options) ? $options : [$options];
            $options = array_values(array_unique(array_map(strval(...), $options)));

            if (is_string($key) && $options !== []) {
                $normalised[$key] = $options;
            }
        }

        return $normalised;
    }

    /**
     * The sidebar is built once per render and handed to the view, so the
     * counts and the results it shows come from the same round trip rather
     * than from a second pass that may see a different library.
     */
    public function getSidebar(): FacetSidebar
    {
        $state = $this->normaliseState($this->getState());
        $signature = json_encode([$state['search'], $state['filters']]);

        if ($this->sidebar !== null && $this->sidebar[0] === $signature) {
            return $this->sidebar[1];
        }

        $sidebar = new FacetSidebar($this->getOfferScope(), $this->getSearch(), $state['filters']);

        $this->sidebar = [(string) $signature, $sidebar];

        return $sidebar;
    }

    /**
     * @return list<Facet>
     */
    public function getFacets(): array
    {
        return $this->getSidebar()->facets();
    }

    public function isFacetSelected(Facet $facet, string $option): bool
    {
        return $this->getSidebar()->filters()->has($facet, $option);
    }

    public function facetCount(Facet $facet, string $option): ?int
    {
        return $this->getSidebar()->count($facet, $option);
    }

    /**
     * What the filters become when this option is clicked.
     *
     * @return array<string, list<string>>
     */
    public function toggleFacet(Facet $facet, string $option): array
    {
        return $this->getSidebar()->filters()->toggled($facet, $option);
    }

    public function facetLabel(Facet $facet): string
    {
        return (string) __('media-library::messages.picker.facets.'.$facet->key());
    }

    /**
     * An option's own words where the package has them, and the raw option
     * otherwise: an uploader's name and a field's own accepted type are values
     * from the application, which no lang file can enumerate.
     */
    public function facetOptionLabel(Facet $facet, string $option): string
    {
        $key = 'media-library::messages.picker.facet_options.'.$facet->key().'.'.$option;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $option;
    }

    public function sortLabel(LibrarySort $sort): string
    {
        return (string) __('media-library::messages.picker.sort.'.$sort->value);
    }

    public function getSort(): LibrarySort
    {
        return LibrarySort::of($this->normaliseState($this->getState())['sort']);
    }

    /**
     * @return list<LibrarySort>
     */
    public function getSortOptions(): array
    {
        return LibrarySort::cases();
    }

    public function getSearch(): LibrarySearch
    {
        return LibrarySearch::of($this->normaliseState($this->getState())['search']);
    }

    public function getSearchQuery(): string
    {
        return $this->normaliseState($this->getState())['search'];
    }

    public function getLoaded(): int
    {
        return $this->normaliseState($this->getState())['loaded'];
    }

    public function getNextBatch(): int
    {
        return $this->getLoaded() + self::BATCH;
    }

    public function wasReset(): bool
    {
        return $this->normaliseState($this->getState())['reset'];
    }

    /**
     * @return list<int>
     */
    public function getSelection(): array
    {
        return $this->normaliseState($this->getState())['selection'];
    }

    public function isSelected(MediaAsset $asset): bool
    {
        return in_array($asset->id, $this->getSelection(), strict: true);
    }

    /**
     * What the selection becomes when this card is clicked. A newly picked
     * asset goes on the end, so the list stays in the order they were picked,
     * and the oldest pick drops off once the field is full.
     *
     * @return list<int>
     */
    public function toggle(MediaAsset $asset): array
    {
        $selection = $this->getSelection();

        if (in_array($asset->id, $selection, strict: true)) {
            return array_values(array_filter($selection, fn (int $id): bool => $id !== $asset->id));
        }

        $selection = [...$selection, $asset->id];
        $limit = $this->getSelectionLimit();

        if ($limit !== null && count($selection) > $limit) {
            $selection = array_slice($selection, -$limit);
        }

        return $selection;
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    public function getAssets(): Collection
    {
        $query = $this->getSidebar()->results();

        $this->getSort()->apply($query);

        // Eager-loaded because every card asks its asset for a thumbnail, and
        // a grid of 48 lazy loads is 48 queries.
        return $query->with('derivatives')->limit($this->getLoaded())->get();
    }

    public function getTotal(): int
    {
        return $this->getSidebar()->results()->reorder()->count();
    }

    public function hasMore(): bool
    {
        return $this->getTotal() > $this->getLoaded();
    }

    /**
     * The selection as assets, in the order they were picked, for the footer
     * that always shows it.
     *
     * @return Collection<int, MediaAsset>
     */
    public function getSelectedAssets(): Collection
    {
        return MediaAsset::inNamedOrder($this->getSelection());
    }

    /**
     * Whether this card may paint the asset's own bytes. View is asked for
     * every card, in the order the cards paint, because the answer is what
     * separates a preview from a glyph tile; the per-request cache in
     * MediaAuthorization is what keeps a grid of 48 to 48 evaluations however
     * often it re-renders.
     *
     * Listing is never gated on the answer. An asset the viewer may not be
     * delivered is still offered and still selectable, since offering shows
     * metadata rather than content.
     */
    public function canPreview(MediaAsset $asset): bool
    {
        $allowed = app(MediaAuthorization::class)->allowsView($asset);

        return $allowed
            && is_string($asset->mime_type)
            && TypeFamily::of($asset->mime_type) === 'image';
    }

    /**
     * What this card paints, resolved once because resolving is what queues a
     * missing thumb. Null covers everything with nothing to paint: a card that
     * may not preview at all, and one whose thumb is still in flight or whose
     * generation gave up. Pending and failed paint the same quiet tile,
     * because a person waiting on a thumbnail and a person who will never get
     * one both want the grid to sit still rather than spin.
     */
    public function cardThumbnail(MediaAsset $asset): ?string
    {
        return $this->canPreview($asset) ? $this->thumbnailUrl($asset) : null;
    }

    /**
     * A video always gets a glyph tile and a play badge, never a poster frame:
     * a frame would mean an optional binary, which the package does not ask an
     * operator to install for a card.
     */
    public function hasPlayBadge(MediaAsset $asset): bool
    {
        return $this->glyphFamily($asset) === 'video';
    }

    /**
     * The BlurHash the card paints under an in-flight thumbnail, handed to the
     * view as part of the grid payload and decoded by the consumer. Null where
     * there is none, and the dimmed tile stands alone.
     */
    public function blurhash(MediaAsset $asset): ?string
    {
        return $this->canPreview($asset) ? $asset->blurhash : null;
    }

    /**
     * The pending tile's own painting of the BlurHash, as an inline style, or
     * null where there is no hash or the stored value is not one. Coarse by
     * design; `data-blurhash` carries the hash itself for a consumer who wants
     * a real decode over the top.
     */
    public function blurhashPaint(MediaAsset $asset): ?string
    {
        $hash = $this->blurhash($asset);

        return $hash === null ? null : BlurHashPaint::css($hash);
    }

    /**
     * The family a glyph tile is tinted and labelled by, for everything with
     * nothing to preview.
     */
    public function glyphFamily(MediaAsset $asset): string
    {
        return is_string($asset->mime_type) ? TypeFamily::of($asset->mime_type) : 'unknown';
    }

    public function glyph(MediaAsset $asset): string
    {
        return mb_strtoupper($asset->extension ?? mb_substr($this->glyphFamily($asset), 0, 3));
    }

    public function highlight(?string $text): HtmlString
    {
        return $this->getSearch()->highlight($text);
    }

    public function getSearchDebounce(): int
    {
        /** @var int $debounce */
        $debounce = config('media-library.search_debounce', 400);

        return $debounce;
    }
}
