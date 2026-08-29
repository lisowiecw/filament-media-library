<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Ingest\TypeFamily;
use Lisowiecw\MediaLibrary\Library\LibrarySearch;
use Lisowiecw\MediaLibrary\Library\OfferScope;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The Library tab's body: one search box over what the Offer scope lists, an
 * infinitely lengthening grid of cards, and the live ordered selection.
 *
 * Everything the tab needs lives in this one field's state, as
 * `search`, `loaded` and `selection`, rather than in three sibling fields
 * reaching across each other. That is what lets a search change reset the
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->dehydrated();
        $this->default(self::blankState());

        $this->afterStateUpdated(static function (LibraryGrid $component, mixed $state, mixed $old): void {
            $component->state($component->reactToSearch($state, $old));
        });
    }

    /**
     * @return array{search: string, loaded: int, selection: list<int>, reset: bool}
     */
    public static function blankState(): array
    {
        return ['search' => '', 'loaded' => self::BATCH, 'selection' => [], 'reset' => false];
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
     * A changed search resets the selection rather than carrying it into a
     * result set it is no longer visible in. The reset is remembered on the
     * state so the view can say it happened, and only when something was
     * actually dropped: announcing a reset of nothing is noise.
     *
     * @return array{search: string, loaded: int, selection: list<int>, reset: bool}
     */
    public function reactToSearch(mixed $state, mixed $old): array
    {
        $state = $this->normaliseState($state);
        $old = $this->normaliseState($old);

        if ($state['search'] === $old['search']) {
            $state['reset'] = false;

            return $state;
        }

        return [
            'search' => $state['search'],
            'loaded' => self::BATCH,
            'selection' => [],
            'reset' => $old['selection'] !== [],
        ];
    }

    /**
     * @return array{search: string, loaded: int, selection: list<int>, reset: bool}
     */
    public function normaliseState(mixed $state): array
    {
        $state = is_array($state) ? $state : [];

        $selection = is_array($state['selection'] ?? null) ? $state['selection'] : [];

        return [
            'search' => is_string($state['search'] ?? null) ? $state['search'] : '',
            'loaded' => max(self::BATCH, (int) ($state['loaded'] ?? self::BATCH)),
            'selection' => array_values(array_unique(array_map(intval(...), $selection))),
            'reset' => (bool) ($state['reset'] ?? false),
        ];
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
        $query = $this->getOfferScope()->query();

        $this->getSearch()->apply($query);

        return $query->limit($this->getLoaded())->get();
    }

    public function getTotal(): int
    {
        $query = $this->getOfferScope()->query();

        $this->getSearch()->apply($query);

        return $query->count();
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
