<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Library\Facets\Facet;
use Lisowiecw\MediaLibrary\Library\Facets\TypeFacet;
use Lisowiecw\MediaLibrary\Library\Facets\UploadedFacet;
use Lisowiecw\MediaLibrary\Library\Facets\UploaderFacet;
use Lisowiecw\MediaLibrary\Library\Facets\UsageFacet;
use Lisowiecw\MediaLibrary\Library\Facets\VisibilityFacet;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The sidebar beside the grid: which dimensions it lists, and what each option
 * would yield if it were clicked.
 *
 * A count is taken against every active filter except the facet's own
 * dimension, so it reads as "clicking this gives you N" rather than as a
 * census of the library. Every count for the whole sidebar is taken in the
 * same round trip as the results, because a sidebar that arrives a moment
 * after the grid is a sidebar an editor has already clicked past.
 *
 * On a library too large for that to be cheap the counts are dropped whole:
 * the facets stay listed and clickable without numbers. Degrading is the
 * point. A sidebar that lags is worse than one that says less.
 */
final class FacetSidebar
{
    public const int DEFAULT_THRESHOLD = 50_000;

    /**
     * @var list<Facet>|null
     */
    private ?array $facets = null;

    /**
     * @var array<string, array<string, int>>|null
     */
    private ?array $counts = null;

    private ?bool $counting = null;

    private ?LibraryFilters $filters = null;

    /**
     * @var array<string, list<string>>
     */
    private array $options = [];

    public function __construct(
        private readonly OfferScope $scope,
        private readonly LibrarySearch $search,
        private readonly mixed $filterState = null,
    ) {}

    /**
     * The dimensions worth showing. A dimension the field has narrowed to a
     * single option is dropped: it would filter nothing.
     *
     * @return list<Facet>
     */
    public function facets(): array
    {
        if ($this->facets !== null) {
            return $this->facets;
        }

        $facets = [];

        foreach ($this->dimensions($this->scope->rules) as $facet) {
            $this->options[$facet->key()] = $facet->options($this->scope->query());

            if (count($this->options[$facet->key()]) > 1) {
                $facets[] = $facet;
            }
        }

        return $this->facets = $facets;
    }

    public function filters(): LibraryFilters
    {
        return $this->filters ??= LibraryFilters::from(
            $this->filterState,
            $this->facets(),
            $this->scope->query(),
        );
    }

    /**
     * @return list<string>
     */
    public function options(Facet $facet): array
    {
        return $this->options[$facet->key()] ??= $facet->options($this->scope->query());
    }

    /**
     * Whether this library is small enough for counts at all, measured on the
     * field-scoped set before search and facets narrow it, so the answer does
     * not flicker as the editor types.
     */
    public function isCounting(): bool
    {
        if ($this->counting !== null) {
            return $this->counting;
        }

        $threshold = $this->threshold();

        // Counting one row past the threshold is enough to know which side of
        // it the library sits on, and it spares the full scan the threshold
        // exists to avoid.
        $capped = DB::query()->fromSub(
            $this->scope->query()
                ->reorder()
                ->select('id')
                ->limit($threshold + 1)
                ->toBase(),
            'capped',
        );

        return $this->counting = $capped->count() <= $threshold;
    }

    /**
     * What clicking this option would yield, or null on a library that has
     * outgrown counting.
     */
    public function count(Facet $facet, string $option): ?int
    {
        if (! $this->isCounting()) {
            return null;
        }

        return $this->allCounts()[$facet->key()][$option] ?? 0;
    }

    /**
     * The results query the grid and the sidebar agree on: the offer scope,
     * the search, and every chosen facet.
     *
     * @return Builder<MediaAsset>
     */
    public function results(): Builder
    {
        $query = $this->scope->query();

        $this->search->apply($query);
        $this->filters()->apply($query, $this->facets());

        return $query;
    }

    /**
     * One round trip per dimension, counting all of that dimension's options
     * at once: each option rides along as its own counting subquery in the
     * select list.
     *
     * The subquery is built from the very predicate the click will apply, so a
     * count and its click cannot drift apart: there is only one of them.
     *
     * @return array<string, array<string, int>>
     */
    private function allCounts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $counts = [];

        foreach ($this->facets() as $facet) {
            $counts[$facet->key()] = $this->countDimension($facet);
        }

        return $this->counts = $counts;
    }

    /**
     * @return array<string, int>
     */
    private function countDimension(Facet $facet): array
    {
        $options = $this->options($facet);

        if ($options === []) {
            return [];
        }

        // A select list with nothing to select from: the row exists to carry
        // the subqueries, and there is exactly one of it.
        $counted = DB::query();

        foreach ($options as $index => $option) {
            $counted->selectSub(
                $this->withoutDimension($facet)
                    ->where(fn (Builder $query) => $facet->constrain($query, $option))
                    ->toBase()
                    ->selectRaw('count(*)'),
                'facet_'.$index,
            );
        }

        /** @var array<string, mixed> $row */
        $row = (array) $counted->first();

        $counts = [];

        foreach ($options as $index => $option) {
            $counts[$option] = (int) ($row['facet_'.$index] ?? 0);
        }

        return $counts;
    }

    /**
     * Everything currently narrowing the grid except this facet's own
     * dimension, which is what makes a count read as "clicking this gives you
     * N" rather than as "the number you already have".
     *
     * @return Builder<MediaAsset>
     */
    private function withoutDimension(Facet $facet): Builder
    {
        $query = $this->scope->query()->reorder();

        $this->search->apply($query);
        $this->filters()->apply($query, $this->facets(), except: $facet);

        return $query;
    }

    /**
     * The sidebar's dimensions, in the order they are read. Provenance is not
     * among them and never will be: see CONTEXT.md on the Media Picker.
     *
     * @return list<Facet>
     */
    private function dimensions(IngestRules $rules): array
    {
        return [
            new TypeFacet($rules),
            new VisibilityFacet,
            new UsageFacet,
            new UploaderFacet,
            new UploadedFacet,
        ];
    }

    private function threshold(): int
    {
        /** @var int $threshold */
        $threshold = config('media-library.facet_count_threshold', self::DEFAULT_THRESHOLD);

        return $threshold;
    }
}
