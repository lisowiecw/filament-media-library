<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library\Facets;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Who uploaded the file. The options are whoever the library actually holds
 * uploads from rather than the application's user table, because an uploader
 * whose account is gone still has files in here.
 *
 * The list is capped: past a couple of dozen names a sidebar is a directory
 * rather than a filter, and the search box already reads the uploader column.
 */
final readonly class UploaderFacet implements Facet
{
    public const int LIMIT = 24;

    /**
     * Uploads made by nobody, which is what an unauthenticated upload records.
     */
    public const string NOBODY = 'none';

    public function key(): string
    {
        return 'uploader';
    }

    /**
     * @param  Builder<MediaAsset>  $scope
     * @return list<string>
     */
    public function options(Builder $scope): array
    {
        /** @var list<string> $uploaders */
        $uploaders = $scope->clone()
            ->whereNotNull('uploaded_by')
            ->distinct()
            ->orderBy('uploaded_by')
            ->limit(self::LIMIT)
            ->reorder('uploaded_by')
            ->pluck('uploaded_by')
            ->all();

        return [...$uploaders, self::NOBODY];
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function constrain(Builder $query, string $option): void
    {
        $option === self::NOBODY
            ? $query->whereNull('uploaded_by')
            : $query->where('uploaded_by', $option);
    }
}
