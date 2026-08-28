<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     * Reads through the attachment rows rather than joining past them, so the
     * field context is expressed once, in `forField`. A soft-deleted asset
     * resolves to nothing and drops out here.
     *
     * @return Collection<int, MediaAsset>
     */
    public function media(string $field): Collection
    {
        $assets = $this->mediaAttachments()
            ->forField($this, $field)
            ->orderBy('order')
            ->with('asset')
            ->get()
            ->map(fn (MediaAttachment $attachment): ?MediaAsset => $attachment->asset)
            ->filter()
            ->values()
            ->all();

        /** @var Collection<int, MediaAsset> */
        return new Collection($assets);
    }

    public function firstMedia(string $field): ?MediaAsset
    {
        return $this->media($field)->first();
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
