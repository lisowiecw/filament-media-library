<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library\Facets;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Public or private, the one thing an editor most needs to know before
 * publishing a record.
 */
final readonly class VisibilityFacet implements Facet
{
    public function key(): string
    {
        return 'visibility';
    }

    /**
     * Both cases, always. An option the scope happens to hold none of today
     * reads as a count of zero, which is honest, where a dimension that comes
     * and goes with the data would only be confusing.
     *
     * @param  Builder<MediaAsset>  $scope
     * @return list<string>
     */
    public function options(Builder $scope): array
    {
        return array_column(Visibility::cases(), 'value');
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function constrain(Builder $query, string $option): void
    {
        $query->where('visibility', $option);
    }
}
