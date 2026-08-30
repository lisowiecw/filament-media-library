<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Deleting a selection, one asset at a time and under the same block a single
 * delete obeys.
 *
 * It goes row by row rather than as one query, because the usage check is the
 * safety story and a bulk delete that skipped it would be a way of deleting
 * used assets by checkbox. A row that is still used, or that this person may
 * not delete, is left alone and counted.
 *
 * The skips are reported rather than swallowed. A partial result nobody is
 * told about reads as a complete one, and the operator would have no way of
 * knowing which half happened.
 *
 * There is deliberately no bulk force delete. Forcing means reviewing a usage
 * list, and there is no way to review fifty of them at once, so the override
 * stays a per-asset act.
 */
final readonly class DeleteAssetsInBulk
{
    public static function make(): BulkAction
    {
        return BulkAction::make('delete')
            ->label(__('media-library::messages.management.actions.delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->authorize('deleteAny')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $lifecycle = app(AssetLifecycle::class);

                $deleted = 0;
                $blocked = 0;
                $forbidden = 0;

                /** @var MediaAsset $record */
                foreach ($records as $record) {
                    if ($record->trashed()) {
                        continue;
                    }

                    if (! Gate::allows('delete', $record)) {
                        $forbidden++;

                        continue;
                    }

                    try {
                        $lifecycle->delete($record);
                        $deleted++;
                    } catch (DeleteBlocked) {
                        $blocked++;
                    }
                }

                Notification::make()
                    ->title(__('media-library::messages.management.notifications.bulk_deleted', ['count' => $deleted]))
                    ->body(static::skips($blocked, $forbidden))
                    ->status($blocked + $forbidden === 0 ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * The two reasons a row can be skipped, said apart: "still in use" is
     * something the operator can act on, and "not yours to delete" is not.
     */
    private static function skips(int $blocked, int $forbidden): ?string
    {
        $lines = [];

        if ($blocked > 0) {
            $lines[] = __('media-library::messages.management.notifications.skipped_in_use', ['count' => $blocked]);
        }

        if ($forbidden > 0) {
            $lines[] = __('media-library::messages.management.notifications.skipped_forbidden', ['count' => $forbidden]);
        }

        return $lines === [] ? null : implode(' ', $lines);
    }
}
