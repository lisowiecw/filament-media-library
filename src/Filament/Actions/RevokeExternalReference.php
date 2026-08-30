<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Lisowiecw\MediaLibrary\Lifecycle\UsageEntry;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Withdraw one External reference from the usage panel, for the case the
 * panel exists to serve: the campaign has been sent, the export is gone, and
 * the only record of it left is the row blocking a delete.
 *
 * It is offered on external rows alone. A host row is the host's own record of
 * what it uses, and removing it here would edit that host behind its back, so
 * detaching stays where the host is edited.
 */
final readonly class RevokeExternalReference
{
    public static function make(UsageEntry $entry): Action
    {
        return Action::make('revoke-'.$entry->attachmentId)
            ->label(__('media-library::messages.management.actions.revoke'))
            ->icon('heroicon-m-x-mark')
            ->color('danger')
            ->link()
            ->visible($entry->isExternal)
            ->authorize('detach')
            ->requiresConfirmation()
            ->modalDescription($entry->label)
            ->action(fn (MediaAsset $record) => $record->attachments()->revokeExternalRow($entry->attachmentId))
            ->successNotificationTitle(__('media-library::messages.management.notifications.revoked'));
    }
}
