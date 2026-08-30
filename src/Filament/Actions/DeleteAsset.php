<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Lisowiecw\MediaLibrary\Exceptions\DeleteBlocked;
use Lisowiecw\MediaLibrary\Filament\Schemas\UsageReadout;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The ordinary delete: soft-delete the record, queue the objects for removal,
 * and refuse while anything still uses the asset.
 *
 * The refusal is not a warning that can be clicked past. It arrives as the
 * usage list itself, because that list is evidence about pages that would
 * break, and the honest way to present it is to stop and show it. Going ahead
 * anyway is a different action with a different ability behind it.
 */
final readonly class DeleteAsset
{
    public static function make(): Action
    {
        return Action::make('delete')
            ->label(__('media-library::messages.management.actions.delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->authorize('delete')
            ->visible(fn (MediaAsset $record): bool => ! $record->trashed())
            ->requiresConfirmation()
            ->action(function (MediaAsset $record, Action $action): void {
                try {
                    app(AssetLifecycle::class)->delete($record);
                } catch (DeleteBlocked $blocked) {
                    Notification::make()
                        ->title(__('media-library::messages.management.notifications.delete_blocked'))
                        ->body((string) UsageReadout::html($blocked->usage))
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();
                }
            })
            ->successNotificationTitle(__('media-library::messages.management.notifications.deleted'));
    }
}
