<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Derivatives\LazyDispatch;
use Lisowiecw\MediaLibrary\Derivatives\SmallOriginal;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * The only way a derivative is ever regenerated in bulk, and always because
 * somebody asked.
 *
 * Nothing sweeps and nothing regenerates on render, so a settings change costs
 * exactly one command rather than spreading itself across whatever traffic
 * happens to arrive next. The run obeys the same per-minute cap as lazy
 * backfill, because the object store does not care which of the two is
 * pointing at it.
 */
class RegenerateDerivatives extends Command
{
    /**
     * How many assets are read at once while hunting for missing renderings.
     */
    private const int CHUNK = 200;

    protected $signature = 'media:regenerate-derivatives
        {--missing : Assets with no rendering of a variant at all}
        {--failed : Renderings that exhausted their retries}
        {--stale : Renderings generated under settings that have since changed}
        {--variant= : Limit to one variant, thumb or preview}
        {--dry-run : Report what would be queued and queue nothing}';

    protected $description = 'Queue derivative generation for missing, failed or stale renderings.';

    public function handle(LazyDispatch $cap): int
    {
        $variants = $this->variants();

        if ($variants === null) {
            return self::FAILURE;
        }

        if (! $this->option('missing') && ! $this->option('failed') && ! $this->option('stale')) {
            $this->components->error('Name at least one of --missing, --failed or --stale.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $queued = 0;

        foreach ($this->targets($variants) as [$asset, $variant, $reason]) {
            $this->components->twoColumnDetail($asset->ulid.' '.$variant->value, $reason);

            if ($dryRun) {
                $queued++;

                continue;
            }

            // The cap is spent before the dispatch rather than after, so a run
            // over a large library trickles at the configured rate instead of
            // filling the queue and discovering the rate afterwards.
            $cap->await();

            if (Derivatives::regenerate($asset, $variant)) {
                $queued++;
            }
        }

        $this->components->info($dryRun
            ? $queued.' rendering(s) would be queued.'
            : $queued.' rendering(s) queued.');

        return self::SUCCESS;
    }

    /**
     * The variants this run covers, or null when the selector names something
     * the package does not have.
     *
     * @return list<DerivativeVariant>|null
     */
    private function variants(): ?array
    {
        /** @var string|null $named */
        $named = $this->option('variant');

        if ($named === null) {
            return DerivativeVariant::cases();
        }

        $variant = DerivativeVariant::tryFrom($named);

        if ($variant === null) {
            $this->components->error('Unknown variant "'.$named.'". Known variants: '
                .implode(', ', array_column(DerivativeVariant::cases(), 'value')).'.');

            return null;
        }

        return [$variant];
    }

    /**
     * Everything this run would queue, as asset, variant and the reason it was
     * selected.
     *
     * Selectors are read in turn rather than unioned, since a row can only be
     * one of missing, failed or stale, and reading them apart is what lets the
     * report say which.
     *
     * @param  list<DerivativeVariant>  $variants
     * @return iterable<array{MediaAsset, DerivativeVariant, string}>
     */
    private function targets(array $variants): iterable
    {
        if ($this->option('failed')) {
            yield from $this->rows($variants, 'failed', fn (Builder $query): Builder => $query
                ->where('status', DerivativeStatus::Failed->value));
        }

        if ($this->option('stale')) {
            yield from $this->rows($variants, 'stale', fn (Builder $query): Builder => $query->stale());
        }

        if ($this->option('missing')) {
            yield from $this->missing($variants);
        }
    }

    /**
     * Existing rows narrowed by whichever selector asked for them, reported
     * under that selector's name. A row whose asset has been deleted is
     * skipped: the object is queued for removal, and regenerating it would
     * write a rendering of something nobody can reach.
     *
     * @param  list<DerivativeVariant>  $variants
     * @param  callable(Builder<MediaDerivative>): Builder<MediaDerivative>  $narrow
     * @return iterable<array{MediaAsset, DerivativeVariant, string}>
     */
    private function rows(array $variants, string $reason, callable $narrow): iterable
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
     * The small-original rule is asked in its byte-only half here, exactly as
     * a card asks it, because the pixels are known only where the object has
     * been read and this walks rows rather than objects.
     *
     * @param  list<DerivativeVariant>  $variants
     * @return iterable<array{MediaAsset, DerivativeVariant, string}>
     */
    private function missing(array $variants): iterable
    {
        $assets = MediaAsset::query()->with('derivatives')->orderBy('id');

        foreach ($assets->lazyById(self::CHUNK) as $asset) {
            if (! Derivatives::generatable($asset) || SmallOriginal::paintsOriginal($asset)) {
                continue;
            }

            /** @var Collection<int, MediaDerivative> $existing */
            $existing = $asset->derivatives;

            foreach ($variants as $variant) {
                if ($existing->firstWhere('variant', $variant) === null) {
                    yield [$asset, $variant, 'missing'];
                }
            }
        }
    }
}
