<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Editing the two pieces of a Media Asset a person wrote: its Display name and
 * its alt text.
 *
 * Renaming is the whole of "edit" on this page, which is why the resource has
 * no edit form behind it. Everything else on a row is either storage identity,
 * which never changes, or provenance, which is a record of what happened
 * rather than a field.
 *
 * It touches nothing in the bucket. A rename that moved an object would break
 * every live URL to it, and the readable name was never the object's name in
 * the first place.
 */
final readonly class RenameAsset
{
    public static function make(): Action
    {
        return Action::make('rename')
            ->label(__('media-library::messages.management.actions.rename'))
            ->icon('heroicon-m-pencil-square')
            ->authorize('update')
            ->schema([
                TextInput::make('display_name')
                    ->label(__('media-library::messages.management.fields.display_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('alt')
                    ->label(__('media-library::messages.management.fields.alt'))
                    ->maxLength(255),
            ])
            ->fillForm(fn (MediaAsset $record): array => [
                'display_name' => $record->display_name,
                'alt' => $record->alt,
            ])
            ->action(function (MediaAsset $record, array $data): void {
                /** @var string $name */
                $name = $data['display_name'];
                /** @var string|null $alt */
                $alt = $data['alt'] ?? null;

                $record->forceFill(['display_name' => $name, 'alt' => $alt])->save();
            })
            ->successNotificationTitle(__('media-library::messages.management.notifications.renamed'));
    }
}
