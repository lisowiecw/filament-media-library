<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Lisowiecw\MediaLibrary\Lifecycle\GracePeriod;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Lists the assets nothing references, and does nothing else.
 *
 * The command reports and never deletes, and it is not scheduled by the
 * package: installing the plugin schedules nothing, so cleanup stays something
 * an operator decides rather than something that happens to them overnight.
 *
 * Being unattached is evidence rather than proof. A URL can live in a sent
 * email, an export or a system the plugin cannot see, which is why the grace
 * period exists and why the last line of the report is a count rather than an
 * offer to act on it.
 */
class ReportUnattachedAssets extends Command
{
    /**
     * How many rows are read at once, so a large library is walked rather than
     * loaded.
     */
    private const int CHUNK = 200;

    protected $signature = 'media:unattached-assets
        {--days= : Override the configured grace period, in days}';

    protected $description = 'Report Media Assets that nothing has referenced for longer than the grace period.';

    public function handle(): int
    {
        $days = $this->graceDays();

        if ($days === null) {
            return self::FAILURE;
        }

        $found = 0;

        foreach (MediaAsset::query()->unattachedFor($days)->orderBy('id')->lazyById(self::CHUNK) as $asset) {
            $found++;

            $this->components->twoColumnDetail(
                $asset->ulid.' '.$asset->display_name,
                $asset->disk.':'.$asset->object_key.' '.$asset->unattachedSince()?->diffForHumans(),
            );
        }

        $this->components->info($found.' asset(s) unattached for more than '.$days.' day(s). Nothing was deleted.');

        return self::SUCCESS;
    }

    /**
     * The grace period this run uses, or null when the option is not a whole
     * number of days. A negative or unreadable period is refused rather than
     * coerced: the number decides what a person is about to consider deleting.
     */
    private function graceDays(): ?int
    {
        /** @var string|null $named */
        $named = $this->option('days');

        if ($named === null) {
            return GracePeriod::days();
        }

        if (! ctype_digit($named)) {
            $this->components->error('The grace period must be a whole number of days, not "'.$named.'".');

            return null;
        }

        return (int) $named;
    }
}
