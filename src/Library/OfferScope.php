<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\TypeFamily;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Symfony\Component\Mime\MimeTypes;

/**
 * The query that decides what a picker grid lists: an accepted-type match,
 * plus public or the field uploads private, minus the blocked types.
 *
 * Disk and directory never narrow it. They say where this field's own uploads
 * land, and an asset uploaded elsewhere is no less reusable for it.
 *
 * Offering is not authorization. The scope is ergonomics, so that a field only
 * shows what it could accept; View decides what may be delivered, and is asked
 * per card rather than per row of this query. See CONTEXT.md on Offer.
 */
final readonly class OfferScope
{
    public function __construct(
        public IngestRules $rules,
        public Visibility $uploadVisibility,
    ) {}

    /**
     * @return Builder<MediaAsset>
     */
    public function query(): Builder
    {
        $query = MediaAsset::query()->excludingBlockedTypes($this->rules);

        // A field that uploads private is trusted with private content
        // already, so the whole library is reusable from it. A field that
        // uploads public may only reach what is already publicly addressable,
        // since attaching never re-places an asset.
        if ($this->uploadVisibility->isPublic()) {
            $query->where('visibility', Visibility::Public->value);
        }

        $this->constrainToAcceptedTypes($query);

        return $query->orderByDesc('id');
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    private function constrainToAcceptedTypes(Builder $query): void
    {
        $accepted = $this->rules->acceptedTypes;

        if ($accepted === null) {
            return;
        }

        $query->where(function (Builder $query) use ($accepted): void {
            foreach ($accepted as $type) {
                $this->matchType($query, $type);
            }
        });
    }

    /**
     * Read the stored extension alongside the stored mime, the way the ingest
     * floor reads them: a row is offered when either side of it is a type the
     * field named.
     *
     * @param  Builder<MediaAsset>  $query
     */
    private function matchType(Builder $query, string $type): void
    {
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
