<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Lisowiecw\MediaLibrary\Attachments\MediaEagerLoad;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;

/**
 * Lets a host model read its own media back without knowing the attachment
 * table exists.
 *
 * The read is deliberately plain: it applies no tenant scope and no policy
 * check, because a host model reading its own field is not a request for
 * content. Soft-deleted assets are excluded, since their objects are queued
 * for removal and a URL to one is already broken.
 *
 * @mixin Model
 */
trait HasMedia
{
    /**
     * Which fields the loaded `mediaAttachments` relation actually covers.
     *
     * Advisory only: it is consulted when the relation is loaded and cleared
     * whenever it is not, so the two can never disagree into a wrong answer.
     * Empty means unconstrained, which is what a hand-written
     * `with('mediaAttachments')` leaves behind.
     *
     * @var list<string>
     */
    protected array $mediaLoadedFields = [];

    /**
     * Reads through the attachment rows rather than joining past them, so the
     * field context is expressed once, in `forField`. A soft-deleted asset
     * resolves to nothing and drops out here.
     *
     * The loaded relation is the only cache: a read that the relation can
     * answer costs nothing, and a read that queries leaves the rows behind so
     * the next one does not.
     *
     * @return Collection<int, MediaAsset>
     */
    public function media(string $field): Collection
    {
        $assets = $this->fieldAttachments($field)
            ->map(fn (MediaAttachment $attachment): ?MediaAsset => $this->liveAsset($attachment))
            ->filter()
            ->values()
            ->all();

        /** @var Collection<int, MediaAsset> */
        return new Collection($assets);
    }

    /**
     * Load the named fields for every host the query returns, mirroring
     * Laravel's own `with`: one query for the attachment rows and one for
     * their assets, however many hosts come back.
     *
     * The field set is recorded in `afterQuery`, which fires once for the
     * whole result set, so a host read afterwards knows which fields its
     * loaded relation can answer for and which still cost a query.
     *
     * Naming no field loads nothing, rather than loading an empty relation
     * that would then answer empty for every field.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithMedia(Builder $query, string ...$fields): void
    {
        if ($fields === []) {
            return;
        }

        $query->with(MediaEagerLoad::constraint(array_values($fields)))
            ->afterQuery(function (mixed $result) use ($fields): void {
                MediaEagerLoad::recordFieldSet($result, array_values($fields));
            });
    }

    /**
     * The same load for a host already in memory, mirroring Laravel's `load`.
     * The collection form is the macro of the same name.
     */
    public function loadMedia(string ...$fields): static
    {
        MediaEagerLoad::into($this, array_values($fields));

        return $this;
    }

    /**
     * Record that the loaded relation covers these fields, which is how a
     * constrained eager load stops the relation answering for a field it never
     * held rows for.
     *
     * Public only because the batch read calls it from outside the host, and
     * not part of what the package promises: a host application has no reason
     * to say what its relation holds, and every reason to let a read say it.
     *
     * @internal
     */
    public function mediaFieldsLoaded(string ...$fields): void
    {
        $this->mediaLoadedFields = array_values(array_unique([...$this->mediaLoadedFields, ...$fields]));
    }

    /**
     * Forget the cache on this instance, after a write that made it stale.
     *
     * Only the instance the write was handed is cleared. Another instance of
     * the same host stays stale, exactly as it does for any other Eloquent
     * relation.
     */
    public function forgetMedia(): void
    {
        $this->unsetRelation('mediaAttachments');

        $this->mediaLoadedFields = [];
    }

    /**
     * The field's attachment rows, from the cache when it can answer and from
     * a query that fills it when it cannot. Every read of a field comes
     * through here, which is what keeps the cheap reads and the whole-field
     * read on one cache.
     *
     * @return Collection<int, MediaAttachment>
     */
    private function fieldAttachments(string $field): Collection
    {
        if (! $this->relationLoaded('mediaAttachments')) {
            $this->mediaLoadedFields = [];
        }

        return $this->cachedMediaAttachments($field)
            ?? $this->fillMediaAttachments($field);
    }

    /**
     * The field's attachments out of the loaded relation, or null when the
     * relation cannot honestly answer for the field.
     *
     * An attachment whose asset is not loaded sends the whole field to the
     * query, because lazy-loading one asset at a time would cost more than the
     * query it replaced.
     *
     * @return Collection<int, MediaAttachment>|null
     */
    private function cachedMediaAttachments(string $field): ?Collection
    {
        if (! $this->relationLoaded('mediaAttachments')) {
            return null;
        }

        if ($this->mediaLoadedFields !== [] && ! in_array($field, $this->mediaLoadedFields, true)) {
            return null;
        }

        /** @var Collection<int, MediaAttachment> $loaded */
        $loaded = $this->getRelation('mediaAttachments');

        $attachments = $loaded->filter(
            fn (MediaAttachment $attachment): bool => $attachment->matchesField($this, $field),
        );

        foreach ($attachments as $attachment) {
            if (! $attachment->relationLoaded('asset')) {
                return null;
            }
        }

        /** @var Collection<int, MediaAttachment> */
        return $attachments->sortBy('order')->values();
    }

    /**
     * Query the field and leave the rows in the loaded relation.
     *
     * A relation that is the union of two field loads is a legal state, so the
     * field's own rows replace any it already held, and the field joins the
     * set: a fill is what makes the relation trustworthy for it.
     *
     * @return Collection<int, MediaAttachment>
     */
    private function fillMediaAttachments(string $field): Collection
    {
        /** @var Collection<int, MediaAttachment> $attachments */
        $attachments = $this->mediaAttachments()
            ->forField($this, $field)
            ->orderBy('order')
            ->with('asset')
            ->get();

        /** @var Collection<int, MediaAttachment> $held */
        $held = $this->relationLoaded('mediaAttachments')
            ? $this->getRelation('mediaAttachments')->reject(
                fn (MediaAttachment $attachment): bool => $attachment->matchesField($this, $field),
            )
            : new Collection;

        $this->setRelation('mediaAttachments', $held->concat($attachments)->values());

        $this->mediaFieldsLoaded($field);

        return $attachments;
    }

    /**
     * The field's first asset, or null when it has none.
     *
     * It reads the same rows `media()` does, through the same cache and in the
     * same order, so the two cannot disagree about which asset is first and a
     * `firstMedia()` leaves the field cached exactly as a `media()` would.
     *
     * A bounded query of its own was considered and dropped: a window smaller
     * than the field cannot fill the cache honestly, and a following `media()`
     * re-querying is the asymmetry this read path exists to remove. So the
     * saving here is only the collection of assets it would have thrown away.
     */
    public function firstMedia(string $field): ?MediaAsset
    {
        foreach ($this->fieldAttachments($field) as $attachment) {
            $asset = $this->liveAsset($attachment);

            if ($asset !== null) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * The asset an attachment resolves to, or null when it resolves to nothing
     * a reader should be handed. Both reads go through it, so the rule that a
     * soft-deleted asset drops out is written once.
     */
    private function liveAsset(MediaAttachment $attachment): ?MediaAsset
    {
        $asset = $attachment->asset;

        return $asset !== null && ! $asset->trashed() ? $asset : null;
    }

    /**
     * Remove one asset from one field context on this host.
     *
     * Detach touches the attachment row and nothing else: the asset keeps its
     * record, its object and its renderings, and stays wherever else it is
     * attached. Deleting a file is a separate, explicit act on the library.
     *
     * The rows go one model at a time rather than in one statement, so the
     * attachment's own events fire and the asset's unattached clock is
     * maintained here as it is everywhere else.
     */
    public function detachMedia(string $field, MediaAsset $asset): void
    {
        $this->mediaAttachments()
            ->forField($this, $field)
            ->where('media_asset_id', $asset->getKey())
            ->get()
            ->each(fn (MediaAttachment $attachment) => $attachment->delete());

        $this->forgetMedia();
    }

    /**
     * Every attachment this host holds, whatever the field context.
     *
     * @return MorphMany<MediaAttachment, $this>
     */
    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'host', 'host_type', 'host_id');
    }
}
