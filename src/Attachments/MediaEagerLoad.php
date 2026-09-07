<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Attachments;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Lisowiecw\MediaLibrary\Models\MediaAttachment;

/**
 * Reading one field context for many hosts at once, in a fixed number of
 * queries and named by the field rather than by the package's own schema.
 *
 * It is one helper because the three ways in, the `withMedia` scope, a
 * collection and a single host, differ only in what they have in hand. The
 * eager load is constrained to the named fields, so every path must also
 * record the field set: a relation holding thumbnail rows alone must not
 * answer for a gallery it never loaded.
 *
 * A host is recognised by the read methods the trait gives it, so a model
 * that does not read media, in a mixed collection or on its own, passes
 * through untouched rather than failing.
 */
final class MediaEagerLoad
{
    /**
     * Load the named fields onto hosts already in memory.
     *
     * The relation is dropped first, because Eloquent's own `load` replaces it
     * wholesale: keeping the old field set beside the new rows would name
     * fields the relation no longer holds.
     *
     * @param  Model|Collection<int, Model>  $target
     * @param  list<string>  $fields
     */
    public static function into(Model|Collection $target, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        /** @var Collection<int, Model> $hosts */
        $hosts = new Collection;

        foreach (self::models($target) as $model) {
            if (! $model instanceof Model || ! method_exists($model, 'forgetMedia')) {
                continue;
            }

            $model->forgetMedia();

            $hosts->push($model);
        }

        $hosts->load(self::constraint($fields));

        self::stamp($hosts, $fields);
    }

    /**
     * The eager load itself, constrained to the named fields and ordered the
     * way the read path reads it.
     *
     * @param  list<string>  $fields
     * @return array<int|string, Closure|string>
     */
    public static function constraint(array $fields): array
    {
        return [
            'mediaAttachments' => fn (MorphMany $query) => self::onlyFields($query, $fields),
            'mediaAttachments.asset',
        ];
    }

    /**
     * @param  MorphMany<MediaAttachment, Model>  $query
     * @param  list<string>  $fields
     */
    private static function onlyFields(MorphMany $query, array $fields): void
    {
        $query->whereIn('field_name', $fields)->orderBy('order');
    }

    /**
     * Record on every host that the loaded relation covers these fields.
     *
     * It takes whatever a query returned, one model or many, and in whatever
     * shape: a result that holds no hosts is nothing to record.
     *
     * @param  list<string>  $fields
     */
    public static function stamp(mixed $result, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        foreach (self::models($result) as $model) {
            if ($model instanceof Model && method_exists($model, 'mediaFieldsLoaded')) {
                $model->mediaFieldsLoaded(...$fields);
            }
        }
    }

    /**
     * The models a target holds, whether it is one model, a collection, or the
     * lazy collection a `cursor()` read returns. It is walked once, because a
     * lazy collection walked twice is a second query at best.
     *
     * @return iterable<mixed, mixed>
     */
    private static function models(mixed $target): iterable
    {
        if ($target instanceof Model) {
            return [$target];
        }

        return is_iterable($target) ? $target : [];
    }
}
