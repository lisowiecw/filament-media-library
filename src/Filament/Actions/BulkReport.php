<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Notifications\Notification;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * What a bulk action tells the operator afterwards, named row by row.
 *
 * A bulk action here is a loop over single acts, each of which can refuse
 * independently, so a run routinely does some of what was asked. A count on its
 * own is not enough to act on: "3 skipped, still in use" leaves the operator to
 * find which three by hand, and a partial result nobody can read reads as a
 * complete one.
 *
 * So skips are collected against the asset that was skipped and reported by
 * name, capped at a handful with the rest counted, because the point is to make
 * the result legible rather than to reprint the selection.
 */
final class BulkReport
{
    /**
     * How many names a reason prints before the rest are only counted. Long
     * enough to chase, short enough to read in a notification.
     */
    private const int NAMED = 5;

    private int $done = 0;

    /** @var array<string, list<string>> */
    private array $skipped = [];

    public function did(): void
    {
        $this->done++;
    }

    /**
     * Record a row that was left alone, under the reason it was left alone for.
     * The reason is a translation key suffix rather than a sentence, so the
     * wording stays in the lang file with everything else.
     */
    public function skipped(string $reason, MediaAsset $asset): void
    {
        $this->skipped[$reason][] = $asset->display_name;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    public function send(string $titleKey, array $replace = []): void
    {
        Notification::make()
            ->title((string) __(
                'media-library::messages.management.notifications.'.$titleKey,
                ['count' => $this->done],
            ))
            ->body($this->body($replace))
            ->status($this->skipped === [] ? 'success' : 'warning')
            ->send();
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function body(array $replace): ?string
    {
        $lines = [];

        foreach ($this->skipped as $reason => $names) {
            $lines[] = (string) __(
                'media-library::messages.management.notifications.skipped_'.$reason,
                ['count' => count($names), 'names' => self::names($names)] + $replace,
            );
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * @param  list<string>  $names
     */
    private static function names(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::NAMED));
        $rest = count($names) - self::NAMED;

        return $rest > 0
            ? $shown.' '.__('media-library::messages.management.notifications.and_more', ['count' => $rest])
            : $shown;
    }
}
