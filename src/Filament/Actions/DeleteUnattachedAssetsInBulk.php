<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Filament\Tables\UnattachedFilter;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Lifecycle\GracePeriod;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The sweep the report-only command describes, performed on purpose.
 *
 * It deletes only what `unattachedFor` selects, so an operator who ticked a
 * whole page cannot take a referenced asset with them: eligibility is
 * recomputed from the database at the moment of the delete rather than trusted
 * from the filter the rows were listed under, since a selection can outlive the
 * filter that produced it.
 *
 * The grace period is the whole point. Being unattached is evidence and not
 * proof, so an asset detached this morning is never eligible however it was
 * selected, and everything skipped is counted back to the operator.
 */
final readonly class DeleteUnattachedAssetsInBulk
{
    public static function make(): BulkAction
    {
        return BulkAction::make('deleteUnattached')
            ->label(__('media-library::messages.management.actions.delete_unattached', ['days' => GracePeriod::days()]))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->authorize('deleteAny')
            ->requiresConfirmation()
            ->modalDescription(__('media-library::messages.management.modals.delete_unattached', ['days' => GracePeriod::days()]))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $lifecycle = app(AssetLifecycle::class);
                $report = new BulkReport;

                /** @var list<int> $ids */
                $ids = $records->pluck('id')->all();
                $eligible = UnattachedFilter::eligible($ids);

                /** @var MediaAsset $record */
                foreach ($records as $record) {
                    if ($record->trashed()) {
                        continue;
                    }

                    if (! in_array($record->id, $eligible, strict: true)) {
                        $report->skipped('attached', $record);

                        continue;
                    }

                    if (! Gate::allows('delete', $record)) {
                        $report->skipped('forbidden', $record);

                        continue;
                    }

                    $lifecycle->delete($record);
                    $report->did();
                }

                $report->send('bulk_deleted', ['days' => GracePeriod::days()]);
            });
    }
}
