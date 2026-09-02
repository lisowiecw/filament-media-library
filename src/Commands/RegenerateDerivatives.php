<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Generator;
use Illuminate\Console\Command;
use Lisowiecw\MediaLibrary\Derivatives\BlurHashing;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Derivatives\HashDispatch;
use Lisowiecw\MediaLibrary\Derivatives\LazyDispatch;
use Lisowiecw\MediaLibrary\Derivatives\RegenerationTargets;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The only way a derivative is regenerated, or a BlurHash backfilled, in bulk,
 * and always because somebody asked.
 *
 * Nothing sweeps and nothing regenerates on render, so a settings change costs
 * exactly one command rather than spreading itself across whatever traffic
 * happens to arrive next. The run obeys the same per-minute cap as lazy
 * backfill, because the object store does not care which of the two is
 * pointing at it.
 *
 * Hashing rides here rather than in a command of its own because the selector,
 * the dry run and the pacing are the three things a backfill needs and all
 * three are already here; what differs is which allowance the run waits on.
 */
class RegenerateDerivatives extends Command
{
    protected $signature = 'media:regenerate-derivatives
        {--missing : Assets with no rendering of a variant at all}
        {--failed : Renderings that exhausted their retries}
        {--stale : Renderings generated under settings that have since changed}
        {--hashes : Assets with no BlurHash, instead of any derivative work}
        {--variant= : Limit to one variant, thumb or preview}
        {--dry-run : Report what would be queued and queue nothing}';

    protected $description = 'Queue derivative generation for missing, failed or stale renderings, or BlurHashes for a library that has none.';

    public function handle(LazyDispatch $cap): int
    {
        if ($this->option('hashes')) {
            return $this->hashes();
        }

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
     * The backfill an operator runs over a library that predates hashing.
     *
     * It rides this command rather than one of its own because the selector,
     * the dry run and the pacing are the three things a backfill needs and all
     * three are already here. What differs is the allowance: a hash is a read
     * and a decode rather than an encode and a write, so the run waits on the
     * hash budget, and a library sized run finishes rather than stopping a
     * minute in.
     */
    private function hashes(): int
    {
        if ($this->option('missing') || $this->option('failed') || $this->option('stale') || $this->option('variant')) {
            $this->components->error('--hashes queues no derivative work, so it cannot be combined with the derivative selectors.');

            return self::FAILURE;
        }

        $cap = app(HashDispatch::class);
        $dryRun = (bool) $this->option('dry-run');
        $queued = 0;

        foreach (RegenerationTargets::hashes() as [$asset, $reason]) {
            $this->components->twoColumnDetail($asset->ulid, $reason);

            if ($dryRun) {
                $queued++;

                continue;
            }

            $cap->await();

            if (BlurHashing::backfill($asset)) {
                $queued++;
            }
        }

        $this->components->info($dryRun
            ? $queued.' hash(es) would be queued, '.$this->pace($queued).'.'
            : $queued.' hash(es) queued.');

        return self::SUCCESS;
    }

    /**
     * How long a real run of this size would take, said in words rather than
     * left as a division for the operator.
     *
     * A run's wall time is the work over the per-minute cap, because that is
     * what the run waits on, and knowing it before starting is the whole point
     * of the dry run over a large library.
     */
    private function pace(int $queued): string
    {
        /** @var int $perMinute */
        $perMinute = config('media-library.blurhash.lazy_dispatch.per_minute', HashDispatch::DEFAULT_PER_MINUTE);

        return 'about '.(int) ceil($queued / max(1, $perMinute)).' minute(s) at '.$perMinute.' a minute';
    }

    /**
     * Everything this run would queue, read from the one place that knows.
     *
     * @param  list<DerivativeVariant>  $variants
     * @return Generator<array{MediaAsset, DerivativeVariant, string}>
     */
    private function targets(array $variants): Generator
    {
        return RegenerationTargets::for(
            $variants,
            failed: (bool) $this->option('failed'),
            stale: (bool) $this->option('stale'),
            missing: (bool) $this->option('missing'),
        );
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
}
