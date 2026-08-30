<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Bringing a selection back, skipping the rows that were never deleted and the
 * ones this person may not restore.
 *
 * It reports its skips for the same reason the bulk delete does: a selection
 * made under the trashed filter can still contain a row somebody else restored
 * a moment ago, and a silent partial result would read as a complete one.
 */
final readonly class RestoreAssetsInBulk
{
    public static function make(): BulkAction
    {
        return BulkAction::make('restore')
            ->label(__('media-library::messages.management.actions.restore'))
            ->icon('heroicon-m-arrow-uturn-left')
            ->authorize('restoreAny')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $lifecycle = app(AssetLifecycle::class);
                $report = new BulkReport;

                /** @var MediaAsset $record */
                foreach ($records as $record) {
                    if (! $record->trashed()) {
                        continue;
                    }

                    if (! Gate::allows('restore', $record)) {
                        $report->skipped('forbidden', $record);

                        continue;
                    }

                    $lifecycle->restore($record);
                    $report->did();
                }

                $report->send('bulk_restored');
            });
    }
}
