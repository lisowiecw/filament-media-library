<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\Ingest\Placement;

/**
 * Adding files to the library from the management page rather than from a
 * form.
 *
 * It calls the same ingest service the picker's Upload tab calls, so a file
 * that arrives here is scrubbed, sniffed, re-gated, sanitized and stamped
 * exactly as one that arrives through a field. There is no second way into the
 * library: the importer is the only other one, and it is a command, because
 * adopting existing objects is a migration rather than an upload.
 *
 * The placement is the package's own configured default. The page belongs to
 * no field, so there is no field configuration to read, and inventing a
 * per-upload disk picker here would be a way of putting an asset somewhere no
 * field would ever have put it.
 */
final readonly class UploadAssets
{
    public static function make(): Action
    {
        return Action::make('upload')
            ->label(__('media-library::messages.management.actions.upload'))
            ->icon('heroicon-m-arrow-up-tray')
            ->visible(fn (): bool => app(MediaAuthorization::class)->allowsUpload(null, null))
            ->schema([
                FileUpload::make('files')
                    ->label(__('media-library::messages.management.fields.files'))
                    ->multiple()
                    ->required()
                    // Held rather than stored: the ingest service is what
                    // writes to a disk, and a file Filament had already put
                    // somewhere would be written twice and keyed by neither.
                    ->storeFiles(false)
                    ->maxSize(fn (): int => (int) config('media-library.max_upload_size')),
            ])
            ->action(function (array $data): void {
                /** @var list<UploadedFile> $files */
                $files = array_values($data['files'] ?? []);

                $ingest = app(IngestService::class);
                $placement = Placement::resolve();

                $uploaded = 0;
                $refused = [];

                foreach ($files as $file) {
                    try {
                        $ingest->ingest($file, $placement);
                        $uploaded++;
                    } catch (IngestRefused $refusal) {
                        $refused[] = $refusal->getMessage();
                    }
                }

                Notification::make()
                    ->title(__('media-library::messages.management.notifications.uploaded', ['count' => $uploaded]))
                    ->body($refused === [] ? null : implode(' ', $refused))
                    ->status($refused === [] ? 'success' : 'warning')
                    ->send();
            });
    }
}
