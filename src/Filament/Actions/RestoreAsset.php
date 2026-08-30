<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Bringing a soft-deleted asset back, which is the point of the delete being
 * soft at all.
 *
 * Nothing resurrects its renderings: they went with the delete, and the
 * pipeline already knows how to make a missing one. So a restore has no
 * cleanup story of its own and needs no confirmation beyond the ordinary one.
 */
final readonly class RestoreAsset
{
    public static function make(): Action
    {
        return Action::make('restore')
            ->label(__('media-library::messages.management.actions.restore'))
            ->icon('heroicon-m-arrow-uturn-left')
            ->authorize('restore')
            ->visible(fn (MediaAsset $record): bool => $record->trashed())
            ->requiresConfirmation()
            ->action(fn (MediaAsset $record) => app(AssetLifecycle::class)->restore($record))
            ->successNotificationTitle(__('media-library::messages.management.notifications.restored'));
    }
}
