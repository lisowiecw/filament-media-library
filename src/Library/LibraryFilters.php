<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Library\Facets\Facet;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * What the sidebar currently has ticked: a list of chosen options per
 * dimension.
 *
 * Options within a dimension widen each other and dimensions narrow each
 * other, which is what lets a count describe what clicking would yield. It is
 * also why a facet's own dimension is left out when its counts are taken: a
 * count of a dimension filtered by itself would only ever say "the number you
 * already have".
 */
final readonly class LibraryFilters
{
    /**
     * @param  array<string, list<string>>  $chosen  keyed by facet key
     */
    private function __construct(public array $chosen) {}

    /**
     * Read the sidebar's state, keeping only options the given facets actually
     * list. A stale tick left over from a field whose accepted types have
     * since narrowed is dropped rather than applied.
     *
     * @param  list<Facet>  $facets
     * @param  Builder<MediaAsset>  $scope
     */
    public static function from(mixed $state, array $facets, Builder $scope): self
    {
        $state = is_array($state) ? $state : [];
        $chosen = [];

        foreach ($facets as $facet) {
            $picked = $state[$facet->key()] ?? [];
            $picked = is_array($picked) ? $picked : [$picked];

            $valid = array_values(array_intersect(
                array_map(strval(...), array_values($picked)),
                $facet->options($scope),
            ));

            if ($valid !== []) {
                $chosen[$facet->key()] = $valid;
            }
        }

        return new self($chosen);
    }

    public function isEmpty(): bool
    {
        return $this->chosen === [];
    }

    /**
     * @return list<string>
     */
    public function chosenFor(Facet $facet): array
    {
        return $this->chosen[$facet->key()] ?? [];
    }

    public function has(Facet $facet, string $option): bool
    {
        return in_array($option, $this->chosenFor($facet), strict: true);
    }

    /**
     * The chosen options with this one toggled, in the shape the state holds.
     *
     * @return array<string, list<string>>
     */
    public function toggled(Facet $facet, string $option): array
    {
        $chosen = $this->chosen;
        $picked = $this->chosenFor($facet);

        $picked = $this->has($facet, $option)
            ? array_values(array_filter($picked, fn (string $each): bool => $each !== $option))
            : [...$picked, $option];

        $chosen[$facet->key()] = $picked;

        return array_filter($chosen, fn (array $picked): bool => $picked !== []);
    }

    /**
     * @param  Builder<MediaAsset>  $query
     * @param  list<Facet>  $facets
     */
    public function apply(Builder $query, array $facets, ?Facet $except = null): void
    {
        foreach ($facets as $facet) {
            if ($except !== null && $facet->key() === $except->key()) {
                continue;
            }

            $picked = $this->chosenFor($facet);

            if ($picked === []) {
                continue;
            }

            $query->where(function (Builder $query) use ($facet, $picked): void {
                foreach ($picked as $option) {
                    $query->orWhere(fn (Builder $query) => $facet->constrain($query, $option));
                }
            });
        }
    }
}
