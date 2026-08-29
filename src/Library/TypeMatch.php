<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lisowiecw\MediaLibrary\Ingest\TypeFamily;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Symfony\Component\Mime\MimeTypes;

/**
 * One reading of an accepted-type token against a stored row, shared by the
 * Offer scope and the Type facet so that a facet can never offer a narrowing
 * the scope would not have honoured.
 *
 * A row is matched when either side of it, the stored mime or the stored
 * extension, is a type the token names: the same pair the ingest floor reads.
 */
final readonly class TypeMatch
{
    /**
     * Narrow the query to rows matching any one of the tokens. An empty list
     * narrows nothing, since a field that named no types accepts everything
     * the denylist leaves.
     *
     * @param  Builder<MediaAsset>  $query
     * @param  list<string>  $types
     */
    public static function any(Builder $query, array $types): void
    {
        if ($types === []) {
            return;
        }

        $query->where(function (Builder $query) use ($types): void {
            foreach ($types as $type) {
                self::one($query, $type);
            }
        });
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    private static function one(Builder $query, string $type): void
    {
        $type = mb_strtolower(trim($type));

        if (str_ends_with($type, '/*')) {
            $query->orWhereRaw('lower(mime_type) like ?', [substr($type, 0, -1).'%']);

            return;
        }

        if (str_contains($type, '/')) {
            $query->orWhere(function (Builder $query) use ($type): void {
                $query->whereRaw('lower(mime_type) = ?', [$type])
                    ->orWhereIn(DB::raw('lower(extension)'), MimeTypes::getDefault()->getExtensions($type));
            });

            return;
        }

        $query->orWhere(function (Builder $query) use ($type): void {
            $query->whereRaw('lower(extension) = ?', [$type])
                ->orWhereIn(DB::raw('lower(mime_type)'), TypeFamily::typesFor($type));
        });
    }
}
