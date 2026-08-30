<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Lifecycle;

use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Jobs\PurgeStoredObjects;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * Deleting and restoring a Media Asset, which is the one part of the package
 * that can lose somebody's file.
 *
 * The rules here are package-global and take no per-field configuration: a
 * field cannot be marked as the one whose deletes skip the usage check,
 * because the asset a field deleted is the asset every other field shares.
 *
 * Deleting is recoverable and cleaning up is not immediate: the record is
 * soft-deleted and the objects are queued for removal, so a mistake is
 * undoable for as long as the queue takes and the bucket is still emptied
 * afterwards without anybody sweeping it.
 */
class AssetLifecycle
{
    /**
     * Soft-delete an asset and queue its objects for removal, refusing while
     * anything still uses it.
     *
     * The block is the default rather than a warning, because the usage list
     * is evidence about pages that would break, and the only honest way to
     * present it is to stop and show it. Forcing is the same act performed by
     * somebody who has read the list.
     *
     * @throws DeleteBlocked
     */
    public function delete(MediaAsset $asset, bool $force = false): void
    {
        $usage = UsageList::for($asset);

        if ($usage !== [] && ! $force) {
            throw new DeleteBlocked($usage);
        }

        // Forcing is the same act, so it goes through here and nowhere
        // else: a second public entry point would be a way of deleting that
        // never sees the usage list.
        $force ? $asset->forceDelete() : $asset->delete();
    }

    /**
     * Bring a soft-deleted asset back.
     *
     * Nothing resurrects its renderings: they went with the delete, and the
     * pipeline already knows how to make a missing one on the next render.
     * Restoring therefore has no cleanup story of its own, which is the point:
     * a half-restored derivative pointing at an object the purge removed is
     * worse than no derivative at all.
     */
    public function restore(MediaAsset $asset): void
    {
        $asset->restore();
    }

    /**
     * Queue removal of everything an asset's delete leaves in the bucket, and
     * drop the derivative rows.
     *
     * Called from the model's own delete events rather than from `delete()`,
     * so a delete performed anywhere (a bulk action, a cascade, application
     * code holding the model) cleans up the same way.
     *
     * The keys are read while the rows are still there and the job is queued
     * only once the delete has actually happened, so a delete that fails
     * removes nothing.
     *
     * @return array<string, list<string>>
     */
    public static function objectsOf(MediaAsset $asset): array
    {
        $keys = [$asset->disk => [$asset->object_key]];

        foreach ($asset->derivatives()->get() as $derivative) {
            $keys[$derivative->disk][] = $derivative->object_key;
        }

        return $keys;
    }

    /**
     * @param  array<string, list<string>>  $objects
     */
    public static function purge(MediaAsset $asset, array $objects): void
    {
        MediaDerivative::query()->where('media_asset_id', $asset->id)->delete();

        foreach ($objects as $disk => $keys) {
            // After the commit, so a delete that is rolled back leaves the
            // bucket alone.
            PurgeStoredObjects::dispatch($disk, array_values(array_unique($keys)))->afterCommit();
        }
    }
}
