<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Lifecycle;

/**
 * How long an asset must have been unattached before anything offers it up
 * for review.
 *
 * It is one number read in two places, the report-only command and the
 * management page's cleanup filter, and the two have to agree: a bulk delete
 * restricted to "unattached for longer than the grace period" is trusted
 * because it selects exactly what the report would have listed.
 */
final readonly class GracePeriod
{
    public const int DEFAULT_DAYS = 30;

    /**
     * The configured period, floored at zero.
     *
     * A negative period would date into the future and select nothing, which
     * reads as "all clear" rather than as the misconfiguration it is.
     */
    public static function days(): int
    {
        /** @var int $configured */
        $configured = config('media-library.unattached_grace_days', self::DEFAULT_DAYS);

        return max(0, $configured);
    }
}
