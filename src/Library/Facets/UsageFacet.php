<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library\Facets;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Whether anything references the asset. The "not attached anywhere" side is
 * the picker's view of an Unattached asset, which makes finding an unused file
 * an ordinary browsing task rather than a report.
 *
 * It is evidence rather than proof, and nothing here deletes: the facet only
 * narrows the grid.
 */
final readonly class UsageFacet implements Facet
{
    public const string ATTACHED = 'attached';

    public const string UNATTACHED = 'unattached';

    public function key(): string
    {
        return 'usage';
    }

    /**
     * @param  Builder<MediaAsset>  $scope
     * @return list<string>
     */
    public function options(Builder $scope): array
    {
        return [self::ATTACHED, self::UNATTACHED];
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function constrain(Builder $query, string $option): void
    {
        $option === self::UNATTACHED
            ? $query->whereDoesntHave('attachments')
            : $query->whereHas('attachments');
    }
}
