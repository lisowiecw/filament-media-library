<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Derivatives;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * How long a claim may be held before nobody is holding it, for the two halves
 * of the pipeline that can be left pending by a worker that died.
 *
 * Both halves ask the same question of a different clock: a hash against
 * `media_assets.blurhash_pending_since`, a rendering against
 * `media_derivatives.updated_at`, which is the asymmetry ADR 19 decides and
 * this deliberately does not flatten. What they were also doing separately is
 * reading a window out of configuration and subtracting it from now, and two
 * copies of a clock is how one half quietly stops honouring an operator's
 * setting while the other still does.
 *
 * The window says when a claim lapsed and nothing else. Whether a missing time
 * counts as lapsed belongs to the half that owns the column, since the two
 * answer it differently and for good reasons: a hash with no recorded time is
 * a row the crash stranded before the column existed, while a derivative row
 * always has an `updated_at` and a null there would be a fact about nothing.
 */
final readonly class AbandonedWindow
{
    private function __construct(
        private string $key,
        private int $default,
    ) {}

    /**
     * The window a BlurHash computation is read against.
     */
    public static function hash(): self
    {
        return new self('media-library.blurhash.abandoned_after', BlurHashing::DEFAULT_ABANDONED_AFTER);
    }

    /**
     * The window a derivative generation is read against.
     */
    public static function rendering(): self
    {
        return new self('media-library.derivatives.abandoned_after', MediaDerivative::DEFAULT_ABANDONED_AFTER);
    }

    /**
     * The instant a claim has to predate to count as abandoned, which is what
     * a query compares a column against.
     */
    public function before(): CarbonImmutable
    {
        /** @var int $window */
        $window = config($this->key, $this->default);

        return CarbonImmutable::now()->subSeconds((int) $window);
    }

    /**
     * Whether a claim taken at this time has been held longer than the work it
     * stood for could honestly take, which is the same question of a row in
     * hand.
     */
    public function lapsed(DateTimeInterface $taken): bool
    {
        return $taken < $this->before();
    }
}
