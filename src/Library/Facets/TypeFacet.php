<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library\Facets;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Library\TypeMatch;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The type narrowing, listed as the field's own accepted types rather than as
 * a fixed menu: a field that only takes images never offers a video filter.
 *
 * A field that named no types accepts everything the denylist leaves, so it
 * falls back to the top-level families, which is the coarsest useful cut of
 * "everything".
 */
final readonly class TypeFacet implements Facet
{
    /**
     * @var list<string>
     */
    private const array FAMILIES = ['image/*', 'video/*', 'audio/*', 'text/*', 'application/*'];

    public function __construct(private IngestRules $rules) {}

    public function key(): string
    {
        return 'type';
    }

    /**
     * @param  Builder<MediaAsset>  $scope
     * @return list<string>
     */
    public function options(Builder $scope): array
    {
        return $this->rules->acceptedTypes ?? self::FAMILIES;
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function constrain(Builder $query, string $option): void
    {
        TypeMatch::any($query, [$option]);
    }
}
