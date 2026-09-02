<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
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
 * of failed, stale, abandoned or missing, and reading them apart is what lets
 * a report say which.
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
    public static function for(
        array $variants,
        bool $failed,
        bool $stale,
        bool $missing,
        bool $abandoned,
    ): Generator {
        if ($failed) {
            yield from self::rows($variants, 'failed', fn (Builder $query): Builder => $query
                ->where('status', DerivativeStatus::Failed->value));
        }

        if ($stale) {
            yield from self::rows($variants, 'stale', fn (Builder $query): Builder => $query->stale());
        }

        if ($abandoned) {
            yield from self::rows($variants, 'abandoned', fn (Builder $query): Builder => $query->abandoned());
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
     * The candidate set both hunts start from: anything that could plausibly
     * be an image, narrowed in SQL so a library of documents is not walked
     * asset by asset to be told no.
     *
     * It is deliberately looser than the questions `Derivatives` asks, and
     * case-insensitive, because it is an optimisation rather than the
     * decision: a prefilter that dropped a row the pipeline wants would be a
     * bug rather than a saving.
     *
     * @return Builder<MediaAsset>
     */
    private static function images(): Builder
    {
        return MediaAsset::query()
            ->whereRaw("lower(mime_type) like 'image/%'")
            ->orderBy('id');
    }

    /**
     * Assets owed a BlurHash and not already claimed for one: a library that
     * predates hashing, and imports whose cards nobody has opened.
     *
     * The status is read in SQL as well as through `wanted()` because a
     * pending row is one the command would skip anyway, and a dry run has to
     * report the set a real run would queue rather than a larger one. The
     * narrowing is `BlurHashing::unclaimed()` itself rather than a second
     * spelling of it, so an asset a dead worker left pending is offered to a
     * backfill on the same terms a render meets it on. The mime narrowing is
     * the same loose prefilter as `missing()`, with `BlurHashing::wanted()`
     * making the actual decision.
     *
     * @return Generator<array{MediaAsset, string}>
     */
    public static function hashes(): Generator
    {
        $assets = BlurHashing::unclaimed(self::images());

        foreach ($assets->lazyById(self::CHUNK) as $asset) {
            if (BlurHashing::wanted($asset)) {
                // A row the selector reached at pending is one whose claim has
                // lapsed, since a live claim never survives `unclaimed()`.
                // Saying so is what lets a dry run report what a real run would
                // reopen apart from what it would ask for the first time, the
                // way the derivative selectors already name theirs.
                yield [$asset, $asset->blurhash_status === BlurHashStatus::Pending ? 'abandoned' : 'no hash'];
            }
        }
    }

    /**
     * Assets that could have a rendering of a variant and have no row for it
     * at all: imports the pipeline never saw, and previews nobody has opened.
     *
     * The question is `Derivatives::missing()` rather than `wanted()`, which
     * also answers yes for an abandoned row: that row belongs to the abandoned
     * selector, and a count an operator reads adds the selectors up rather
     * than meeting the same work twice.
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
        $assets = self::images()->with('derivatives');

        foreach ($assets->lazyById(self::CHUNK) as $asset) {
            foreach ($variants as $variant) {
                if (Derivatives::missing($asset, $variant)) {
                    yield [$asset, $variant, 'missing'];
                }
            }
        }
    }
}
