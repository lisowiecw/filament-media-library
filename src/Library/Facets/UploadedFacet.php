<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library\Facets;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * When the file arrived, in the spans a person actually remembers in: today,
 * this week, this month, this year.
 *
 * The spans are cumulative rather than exclusive, so picking two of them means
 * the wider one, which is what an editor unsure whether it was Tuesday or last
 * month expects.
 */
final readonly class UploadedFacet implements Facet
{
    /**
     * How far back each option reaches, in days.
     *
     * @var array<string, int>
     */
    private const array SPANS = [
        'today' => 1,
        'week' => 7,
        'month' => 30,
        'year' => 365,
    ];

    public function key(): string
    {
        return 'uploaded';
    }

    /**
     * @param  Builder<MediaAsset>  $scope
     * @return list<string>
     */
    public function options(Builder $scope): array
    {
        return array_keys(self::SPANS);
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function constrain(Builder $query, string $option): void
    {
        $days = self::SPANS[$option] ?? null;

        // An option the sidebar never listed matches nothing rather than
        // everything: a filter that fails open is worse than one that empties.
        if ($days === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('created_at', '>=', now()->subDays($days));
    }
}
