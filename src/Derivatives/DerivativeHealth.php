<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * What the management page says about the derivative pipeline without anybody
 * running a command.
 *
 * Every number names work an operator can act on, and they are all acted on
 * the same way: `media:regenerate-derivatives`. None is a fault the library
 * will fix on its own, which is the point of showing them: nothing sweeps, and
 * a stale rendering is still served, so a count is the only place a settings
 * change becomes visible.
 *
 * The counts and the readout's own regenerate action read the same selectors,
 * so the page cannot offer to fix a set it did not count.
 */
final readonly class DerivativeHealth
{
    /**
     * How much one press of the readout's regenerate action queues. A batch
     * rather than everything, since the run happens in a request.
     */
    public const int DEFAULT_BATCH = 100;

    /**
     * Renderings generated under settings the application has since changed.
     *
     * A row whose asset has been deleted is left out, because the command the
     * count sends an operator to skips it too: a number that never drops is
     * worse than no number.
     */
    public static function stale(): int
    {
        return MediaDerivative::query()->stale()->whereHas('asset')->count();
    }

    /**
     * Renderings that exhausted their retries and are no longer re-dispatched
     * by anything, so this is the count that only a command clears.
     *
     * The failed count is properly the management page's, not staleness's, but
     * it is the same query against the same scope and the same command clears
     * both, so it sits here rather than waiting to be written twice.
     */
    public static function failed(): int
    {
        return MediaDerivative::query()
            ->where('status', DerivativeStatus::Failed->value)
            ->whereHas('asset')
            ->count();
    }

    /**
     * Assets with no rendering of a variant at all: imports the pipeline never
     * saw, and previews nobody has opened.
     *
     * Counted as asset and variant pairs rather than as assets, because that
     * is the unit of work the regeneration queues.
     */
    public static function missing(): int
    {
        return iterator_count(RegenerationTargets::missing(DerivativeVariant::cases()));
    }

    /**
     * The whole readout in one call, so a page asks for the three numbers
     * together rather than naming each selector itself.
     *
     * @return array{failed: int, missing: int, stale: int}
     */
    public static function counts(): array
    {
        return [
            'failed' => self::failed(),
            'missing' => self::missing(),
            'stale' => self::stale(),
        ];
    }

    /**
     * Queue regeneration for everything the readout counted, up to a bounded
     * batch, and say how much was left.
     *
     * Bounded because this runs inside a request. The command trickles at the
     * configured per-minute cap by waiting, which a web request cannot do, so
     * the panel takes a batch and names the command for the rest rather than
     * queueing a library's worth of work in one click.
     *
     * @return array{queued: int, remaining: int}
     */
    public static function regenerate(int $batch = self::DEFAULT_BATCH): array
    {
        $queued = 0;
        $remaining = 0;

        $targets = RegenerationTargets::for(
            DerivativeVariant::cases(),
            failed: true,
            stale: true,
            missing: true,
        );

        foreach ($targets as [$asset, $variant]) {
            if ($queued >= $batch) {
                $remaining++;

                continue;
            }

            if (Derivatives::regenerate($asset, $variant)) {
                $queued++;
            }
        }

        return ['queued' => $queued, 'remaining' => $remaining];
    }
}
