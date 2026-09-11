<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Attachments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lisowiecw\MediaLibrary\Exceptions\AttachRefused;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;
use Lisowiecw\MediaLibrary\Tenancy\TenantReach;

/**
 * Brings a host model's attachments in one field context into line with an
 * ordered list of asset ids.
 *
 * The list is the whole truth: what is missing from it is detached, what is
 * new in it is attached, and the position an id sits at is the order it is
 * attached at. The work is a diff rather than a delete-and-reinsert, so a row
 * that stays keeps its identity and its `created_at`, and a list that already
 * matches writes nothing at all.
 */
class AttachmentReconciler
{
    /**
     * @param  list<int|string>  $assetIds
     */
    public function reconcile(Model $host, string $field, array $assetIds): void
    {
        $desired = $this->normalise($assetIds);

        DB::transaction(function () use ($host, $field, $desired): void {
            $existing = MediaAttachment::query()
                ->forField($host, $field)
                ->orderBy('order')
                ->get()
                ->keyBy('media_asset_id');

            foreach ($existing as $assetId => $attachment) {
                if (! in_array($assetId, $desired, true)) {
                    $attachment->delete();
                }
            }

            $this->refuseCrossTenant($desired, array_values($existing->keys()->all()));

            foreach ($desired as $order => $assetId) {
                $attachment = $existing->get($assetId);

                if ($attachment === null) {
                    MediaAttachment::query()->create([
                        'media_asset_id' => $assetId,
                        'host_type' => $host->getMorphClass(),
                        'host_id' => $host->getKey(),
                        'field_name' => $field,
                        'order' => $order,
                    ]);

                    continue;
                }

                if ($attachment->order !== $order) {
                    $attachment->update(['order' => $order]);
                }
            }
        });

        $this->forgetHostCache($host);
    }

    /**
     * The write just made the host's own read cache stale, so clear it on the
     * instance the reconcile was handed. Any other instance stays stale, the
     * same as it would for any Eloquent relation.
     */
    private function forgetHostCache(Model $host): void
    {
        if (method_exists($host, 'forgetMedia')) {
            $host->forgetMedia();
        }
    }

    /**
     * Refuse a reconcile that would attach an asset the caller cannot reach,
     * which is what stops a programmatic attach sailing past the scope the
     * grid was offering under.
     *
     * The rule itself is TenantReach, shared with the picker's validation
     * rule; only the refusal is this call site's own.
     *
     * @param  list<int>  $desired
     * @param  list<int|string>  $attached
     */
    private function refuseCrossTenant(array $desired, array $attached): void
    {
        if (! TenantReach::reaches($desired, $attached)) {
            throw AttachRefused::tenantMismatch();
        }
    }

    /**
     * The list as the database will hold it: integer ids, each appearing once,
     * numbered from zero. A repeated id is the same attachment asked for
     * twice, and one field context holds it once.
     *
     * Ids arrive as strings from a form's state, so they are converted rather
     * than trusted, and anything that is not an id is refused here: casting it
     * would attach asset zero or fail at the foreign key, both far from the
     * caller that got it wrong.
     *
     * @param  list<int|string>  $assetIds
     * @return list<int>
     */
    private function normalise(array $assetIds): array
    {
        $ids = array_map(function (int|string $id): int {
            if (! is_int($id) && ! ctype_digit($id)) {
                throw new InvalidArgumentException("Not a media asset id: [{$id}].");
            }

            return (int) $id;
        }, $assetIds);

        return array_values(array_unique($ids));
    }
}
