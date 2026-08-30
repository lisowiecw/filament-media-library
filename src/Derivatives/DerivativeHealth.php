<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * What the management page says about the derivative pipeline without anybody
 * running a command.
 *
 * Both numbers name work an operator can act on, and both act on it the same
 * way: `media:regenerate-derivatives`. Neither is a fault the library will fix
 * on its own, which is the point of showing them: nothing sweeps, and a stale
 * rendering is still served, so a count is the only place a settings change
 * becomes visible.
 */
final readonly class DerivativeHealth
{
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
     */
    public static function failed(): int
    {
        return MediaDerivative::query()
            ->where('status', DerivativeStatus::Failed->value)
            ->whereHas('asset')
            ->count();
    }
}
