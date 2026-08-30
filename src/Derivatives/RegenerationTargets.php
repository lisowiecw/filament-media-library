<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * Everything a regeneration run would queue, said once.
 *
 * The command and the management page's health readout both ask this, because
 * a count an operator is shown and the work the same operator then starts have
 * to be the same set. Asking twice in two places is how a page ends up
 * offering to fix three renderings and queueing four.
 *
 * Selectors are read in turn rather than unioned, since a row can only be one
 * of failed, stale or missing, and reading them apart is what lets a report
 * say which.
 */
final readonly class RegenerationTargets
{
    /**
     * How many assets are read at once while hunting for missing renderings.
     */
    private const int CHUNK = 200;

    /**
     * @param  list<DerivativeVariant>  $variants
     * @return Generator<array{MediaAsset, DerivativeVariant, string}>
     */
    public static function for(array $variants, bool $failed, bool $stale, bool $missing): Generator
    {
        if ($failed) {
            yield from self::rows($variants, 'failed', fn (Builder $query): Builder => $query
                ->where('status', DerivativeStatus::Failed->value));
        }

        if ($stale) {
            yield from self::rows($variants, 'stale', fn (Builder $query): Builder => $query->stale());
        }

        if ($missing) {
            yield from self::missing($variants);
        }
    }

    /**
     * Existing rows narrowed by whichever selector asked for them, reported
     * under that selector's name. A row whose asset has been deleted is
     * skipped: the object is queued for removal, and regenerating it would
     * write a rendering of something nobody can reach.
     *
     * The reason travels as a plain string rather than an enum because it is
     * report wording and nothing branches on it: a caller prints it beside a
     * count and never asks which one it got.
     *
     * @param  list<DerivativeVariant>  $variants
     * @param  callable(Builder<MediaDerivative>): Builder<MediaDerivative>  $narrow
     * @return Generator<array{MediaAsset, DerivativeVariant, string}>
     */
    private static function rows(array $variants, string $reason, callable $narrow): Generator
    {
        $query = MediaDerivative::query()
            ->with('asset')
            ->whereIn('variant', array_column($variants, 'value'))
            ->orderBy('id');

        foreach ($narrow($query)->lazy() as $derivative) {
            if ($derivative->asset !== null) {
                yield [$derivative->asset, $derivative->variant, $reason];
            }
        }
    }

    /**
     * Assets that could have a rendering of a variant and have no row for it
     * at all: imports the pipeline never saw, and previews nobody has opened.
     *
     * The candidate set is narrowed in SQL to what could possibly want one, so
     * a library of documents is not walked asset by asset to be told no. The
     * narrowing is deliberately looser than `Derivatives::generatable()` and
     * case-insensitive, since it is an optimisation rather than the decision:
     * `wanted()` still asks properly for every row that survives it, and a
     * prefilter that dropped a row the pipeline wants would be a bug rather
     * than a saving.
     *
     * @param  list<DerivativeVariant>  $variants
     * @return Generator<array{MediaAsset, DerivativeVariant, string}>
     */
    public static function missing(array $variants): Generator
    {
        $assets = MediaAsset::query()
            ->with('derivatives')
            ->whereRaw("lower(mime_type) like 'image/%'")
            ->orderBy('id');

        foreach ($assets->lazyById(self::CHUNK) as $asset) {
            foreach ($variants as $variant) {
                if (Derivatives::wanted($asset, $variant)) {
                    yield [$asset, $variant, 'missing'];
                }
            }
        }
    }
}
