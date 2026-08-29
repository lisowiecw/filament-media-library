<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library\Facets;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * One dimension of the facet sidebar: the options it lists, and what clicking
 * one of them narrows the query to.
 *
 * A dimension answers both questions, so a count can never describe a
 * different set than the click that follows it: the count is taken by running
 * this same predicate over the filtered set.
 *
 * Provenance is deliberately not a dimension here. It belongs to library
 * management; the picker's surface is about choosing a file.
 */
interface Facet
{
    /**
     * The dimension's key in the grid's state and in the lang file.
     */
    public function key(): string;

    /**
     * The options to list, in display order. A dimension whose options depend
     * on what the library holds reads them off the field-scoped set it is
     * given; one whose options are fixed ignores it.
     *
     * A dimension returning fewer than two options is dropped from the
     * sidebar: a filter with one choice narrows nothing.
     *
     * @param  Builder<MediaAsset>  $scope
     * @return list<string>
     */
    public function options(Builder $scope): array;

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function constrain(Builder $query, string $option): void;
}
