<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Text;
use Lisowiecw\MediaLibrary\Filament\Schemas\UsageReadout;
use Lisowiecw\MediaLibrary\Lifecycle\AssetLifecycle;
use Lisowiecw\MediaLibrary\Lifecycle\UsageList;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Deleting an asset that something still uses, and deleting it for good.
 *
 * The whole point of the action is the review, so the modal is the usage list
 * with an acknowledgement under it rather than a yes/no. Nobody should be able
 * to override the block without having been shown what the override costs, and
 * an override that is one click away from the ordinary delete is not an
 * override.
 *
 * It is offered on a trashed row too, where it is the way a soft-deleted asset
 * finally leaves the bucket.
 */
final readonly class ForceDeleteAsset
{
    public static function make(): Action
    {
        return Action::make('forceDelete')
            ->label(__('media-library::messages.management.actions.force_delete'))
            ->icon('heroicon-m-fire')
            ->color('danger')
            ->authorize('forceDelete')
            ->modalHeading(__('media-library::messages.management.modals.force_delete'))
            ->modalSubmitActionLabel(__('media-library::messages.management.actions.force_delete'))
            ->schema([
                Text::make(fn (MediaAsset $record): string => __(
                    'media-library::messages.management.usage.count',
                    ['count' => UsageReadout::count($record)],
                )),
                Text::make(fn (MediaAsset $record) => UsageReadout::html(UsageList::for($record))),
                Checkbox::make('reviewed')
                    ->label(__('media-library::messages.management.fields.reviewed'))
                    ->accepted()
                    ->required(),
            ])
            ->action(fn (MediaAsset $record) => app(AssetLifecycle::class)->delete($record, force: true))
            ->successNotificationTitle(__('media-library::messages.management.notifications.force_deleted'));
    }
}
