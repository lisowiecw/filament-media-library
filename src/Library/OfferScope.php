<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tenancy\Tenancy;

/**
 * The query that decides what a picker grid lists: an accepted-type match,
 * plus public or the field uploads private, minus the blocked types.
 *
 * Disk and directory never narrow it. They say where this field's own uploads
 * land, and an asset uploaded elsewhere is no less reusable for it.
 *
 * A field may hand the scope a narrowing of its own. It is applied inside a
 * nested group, so whatever the callback does it can only ever add a condition
 * to what the package already decided: an `orWhere` reaching for a row the
 * package excluded stays inside its own parentheses and widens nothing.
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
        public ?Closure $narrowing = null,
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

        // The tenant boundary is not the field's to widen or to narrow, so it
        // is applied before the field's own callback ever sees the query.
        Tenancy::scope($query);

        $this->constrainToAcceptedTypes($query);
        $this->applyNarrowing($query);

        return $query->orderByDesc('id');
    }

    /**
     * The field's own `->scopeLibrary()`, boxed into a nested group so it can
     * only narrow. The callback's return value is ignored on purpose: a
     * callback that returns a different builder would be a way out of the box.
     *
     * @param  Builder<MediaAsset>  $query
     */
    private function applyNarrowing(Builder $query): void
    {
        if ($this->narrowing === null) {
            return;
        }

        $query->where(function (Builder $query): void {
            ($this->narrowing)($query);
        });
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    private function constrainToAcceptedTypes(Builder $query): void
    {
        TypeMatch::any($query, $this->rules->acceptedTypes ?? []);
    }
}
