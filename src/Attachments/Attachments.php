<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Attachments;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;

/**
 * A Media Asset's attachment rows, with the two writes that belong to nothing
 * else: registering and revoking an External reference.
 *
 * They sit on the relation rather than on a service because an external
 * reference is an attachment and nothing more. Giving it its own service
 * would be the second usage mechanism the package is careful not to have: the
 * row it writes is the row a usage list reads and a blocked delete counts,
 * with no branch anywhere that knows the difference.
 *
 * @template TRelatedModel of MediaAttachment
 * @template TDeclaringModel of MediaAsset
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class Attachments extends HasMany
{
    /**
     * Record that something outside any host model uses this asset.
     *
     * The identifier is the application's own handle on the thing making the
     * reference, and is what a later revoke selects on; the label is what a
     * person reviewing a delete reads. Writing the same identifier twice is
     * the same reference stated again rather than a second use, so it lands
     * on the one row and refreshes its label: the code that registers a
     * reference is usually the code that reruns.
     *
     * @return TRelatedModel
     */
    public function createExternal(string $identifier, ?string $label = null): MediaAttachment
    {
        $attachment = $this->firstOrNew([
            'reference_identifier' => $identifier,
            'host_type' => null,
        ]);

        // A label is only written when one is given, so a rerun that names the
        // identifier alone leaves the wording a delete reviewer reads where it
        // was rather than blanking it.
        if ($label !== null) {
            $attachment->reference_label = $label;
        }

        $this->save($attachment);

        return $attachment;
    }

    /**
     * Withdraw an external reference, naming it as it was registered. This is
     * what code calls when the campaign that made the reference is gone.
     *
     * @return int the number of rows withdrawn
     */
    public function revokeExternal(string $identifier): int
    {
        return $this->withdraw(
            $this->getQuery()->where('reference_identifier', $identifier),
        );
    }

    /**
     * Withdraw one external reference by the attachment row it is, which is
     * what the usage panel has to hand once a person has picked a line of it.
     *
     * @return int the number of rows withdrawn
     */
    public function revokeExternalRow(int $attachmentId): int
    {
        return $this->withdraw(
            $this->getQuery()->whereKey($attachmentId),
        );
    }

    /**
     * Both revokes narrow to a null host here rather than at their own call
     * site, so a host model's row can never be withdrawn through either of
     * them: detaching is the host's own act, on the host's own record.
     *
     * The rows go one model at a time so the attachment's own events fire and
     * the asset's unattached clock is maintained, as it is for every other
     * detach.
     *
     * @param  Builder<TRelatedModel>  $rows
     */
    private function withdraw(Builder $rows): int
    {
        return $rows->whereNull('host_type')
            ->get()
            ->each(fn (MediaAttachment $attachment) => $attachment->delete())
            ->count();
    }
}
