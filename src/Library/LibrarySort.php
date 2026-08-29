<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The order the grid browses in. Kept apart from the facets because sorting
 * changes what an editor sees first rather than what they can see at all: a
 * sort never removes a card, so, unlike a filter change, it leaves the
 * selection alone.
 */
enum LibrarySort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Name = 'name';
    case MostUsed = 'most_used';

    public static function of(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Newest) : self::Newest;
    }

    /**
     * Replace whatever order the offer scope arrived with. Every ordering ends
     * on the id so that rows the sort cannot separate, two files uploaded in
     * the same second or sharing a name, still come back in a stable order
     * rather than shuffling between batches of an infinite scroll.
     *
     * @param  Builder<MediaAsset>  $query
     */
    public function apply(Builder $query): void
    {
        $query->reorder();

        match ($this) {
            self::Newest => $query->orderByDesc('id'),
            self::Oldest => $query->orderBy('id'),
            self::Name => $query->orderBy('display_name')->orderByDesc('id'),
            self::MostUsed => $query->withCount('attachments')
                ->orderByDesc('attachments_count')
                ->orderByDesc('id'),
        };
    }
}
