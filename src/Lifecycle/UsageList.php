<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;

/**
 * The one resolver that answers "what uses this asset".
 *
 * Every surface that asks reads this: the count on a table row, the panel on
 * the asset itself, and the list a blocked delete shows. Asking it twice in
 * two places is how a usage count and a delete block end up disagreeing, and
 * a person is being asked to authorise a delete on the strength of the list,
 * so the two have to be the same list.
 *
 * A row whose host model has since been deleted still reads as a use. The
 * attachment is the record of the reference; the plugin cannot tell a host it
 * cannot load from a host it may not load, and quietly dropping either would
 * turn a blocked delete into an allowed one.
 */
final readonly class UsageList
{
    /**
     * @return list<UsageEntry>
     */
    public static function for(MediaAsset $asset): array
    {
        return array_values($asset->attachments()
            ->orderBy('id')
            ->get()
            ->map(self::entry(...))
            ->all());
    }

    private static function entry(MediaAttachment $attachment): UsageEntry
    {
        $external = $attachment->host_type === null;

        return new UsageEntry(
            attachmentId: $attachment->id,
            label: $external ? self::externalLabel($attachment) : self::hostLabel($attachment),
            field: $attachment->field_name,
            hostType: $attachment->host_type,
            hostId: $attachment->host_id,
            isExternal: $external,
        );
    }

    /**
     * An external reference reads as whatever the application called it, and
     * falls back to the identifier it registered itself under, which is the
     * only other thing the plugin knows about it.
     */
    private static function externalLabel(MediaAttachment $attachment): string
    {
        return $attachment->reference_label
            ?? $attachment->reference_identifier
            ?? 'External reference';
    }

    /**
     * A host row reads through the host model's own label where the model
     * offers one, since only the application knows whether a post is called by
     * its title or its slug. Where it does not, or where the row can no longer
     * be loaded, the model name and key are still a handle an operator can act
     * on.
     */
    private static function hostLabel(MediaAttachment $attachment): string
    {
        /** @var class-string<Model>|string $type */
        $type = (string) $attachment->host_type;
        $fallback = class_basename(Model::getActualClassNameForMorph($type)).' #'.$attachment->host_id;

        $host = self::host($attachment);

        if ($host === null || ! method_exists($host, 'mediaUsageLabel')) {
            return $fallback;
        }

        /** @var mixed $label */
        $label = $host->mediaUsageLabel();

        return is_string($label) && $label !== '' ? $label : $fallback;
    }

    private static function host(MediaAttachment $attachment): ?Model
    {
        $class = Model::getActualClassNameForMorph((string) $attachment->host_type);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($attachment->host_id);
    }
}
