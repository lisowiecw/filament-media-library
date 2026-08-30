<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Filament\Actions\DeleteAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\DeleteAssetsInBulk;
use Lisowiecw\MediaLibrary\Filament\Actions\DeleteUnattachedAssetsInBulk;
use Lisowiecw\MediaLibrary\Filament\Actions\DerivativeHealthReadout;
use Lisowiecw\MediaLibrary\Filament\Actions\DownloadAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\ForceDeleteAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\RenameAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\RestoreAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\RestoreAssetsInBulk;
use Lisowiecw\MediaLibrary\Filament\Actions\UploadAssets;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages\ViewMediaAsset;
use Lisowiecw\MediaLibrary\Filament\Schemas\UsageReadout;
use Lisowiecw\MediaLibrary\Filament\Tables\UnattachedFilter;
use Lisowiecw\MediaLibrary\Library\LibrarySearch;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The librarian's view of the library, which is a different job from the
 * picker's.
 *
 * The picker offers what an editor may attach: it hides blocked types and it
 * shows a grid, because picking is a visual act. This is the opposite. It is a
 * table, it lists everything including the private and the already-trashed, and
 * it exists to answer questions about assets rather than to choose one.
 *
 * What it deliberately cannot do is as much of the design as what it can.
 * There is no replace in place, no visibility change and no move between disks
 * or directories, because a URL that has been published is a promise: every one
 * of those edits would change what an existing address serves, and the library
 * has no way to find who is holding that address. Renaming is offered precisely
 * because it touches nothing in the bucket.
 *
 * The importer stays a command. It reads a directory the operator names, on the
 * server's own filesystem, and that is not a decision to hand to whoever can
 * open a panel.
 *
 * The page is opt-in per panel through the plugin, and gated on `viewAny` even
 * once opted in.
 */
class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    public static function getModelLabel(): string
    {
        return __('media-library::messages.management.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('media-library::messages.management.model_plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('media-library::messages.management.navigation');
    }

    /**
     * Management sees the whole library, trashed rows included, because the
     * questions asked here (what happened to that file, what is safe to remove)
     * are mostly about the rows a picker would hide. The soft-delete filter
     * puts the choice in the operator's hands instead.
     *
     * The usage count is loaded with the page rather than per row: it is a
     * column on every line, and a count per line is a query per line.
     *
     * The return type is the parent's rather than narrowed to this resource's
     * model, because the narrowing would be a claim about a query the base
     * class builds from a static property, which is not something a reader or
     * an analyser can check here.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->withCount('attachments');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('media-library::messages.management.fields.display_name'))
                    // The search box is the library's own, so an object key
                    // pasted out of a log or a bucket listing finds its asset
                    // here exactly as it does in the picker.
                    ->searchable(query: function (Builder $query, string $search): void {
                        LibrarySearch::of($search)->apply($query);
                    })
                    ->sortable()
                    ->description(fn (MediaAsset $record): ?string => $record->original_client_filename),
                TextColumn::make('mime_type')
                    ->label(__('media-library::messages.management.fields.mime_type'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('size')
                    ->label(__('media-library::messages.management.fields.size'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : static::humanSize($state))
                    ->sortable(),
                TextColumn::make('visibility')
                    ->label(__('media-library::messages.management.fields.visibility'))
                    ->badge(),
                TextColumn::make('source')
                    ->label(__('media-library::messages.management.fields.source'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('attachments_count')
                    ->label(__('media-library::messages.management.fields.usage'))
                    ->state(fn (MediaAsset $record): int => UsageReadout::count($record))
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'success')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('media-library::messages.management.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
                SelectFilter::make('source')
                    ->label(__('media-library::messages.management.fields.source'))
                    ->options(static::enumOptions(MediaSource::cases())),
                SelectFilter::make('mime_source')
                    ->label(__('media-library::messages.management.fields.mime_source'))
                    ->options(static::enumOptions(MimeSource::cases())),
                UnattachedFilter::make(),
            ])
            ->headerActions([
                UploadAssets::make(),
                DerivativeHealthReadout::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                RenameAsset::make(),
                DownloadAsset::make(),
                DeleteAsset::make(),
                RestoreAsset::make(),
                ForceDeleteAsset::make(),
            ])
            ->toolbarActions([
                DeleteAssetsInBulk::make(),
                RestoreAssetsInBulk::make(),
                DeleteUnattachedAssetsInBulk::make(),
            ]);
    }

    /**
     * What an operator reads before deciding anything: where the bytes are, how
     * the type was arrived at, where the asset came from, and who is using it.
     *
     * The disk and object key are copyable rather than editable. They are the
     * identity a published URL is built from, so they are here to be pasted
     * into a bucket console or a log search, never to be changed.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            // The rendering rather than the original: opening an asset here is
            // a look, not a download, and asking for the preview is what
            // generates it. A row with nothing to paint hides the entry rather
            // than showing a broken frame, which is also every non-image.
            ImageEntry::make('preview')
                ->hiddenLabel()
                ->state(fn (MediaAsset $record): ?string => $record->previewUrl())
                ->visible(fn (MediaAsset $record): bool => $record->previewUrl() !== null)
                ->extraImgAttributes(['class' => 'max-w-full rounded-lg']),
            Section::make(__('media-library::messages.management.sections.asset'))
                ->schema([
                    TextEntry::make('display_name')
                        ->label(__('media-library::messages.management.fields.display_name')),
                    TextEntry::make('alt')
                        ->label(__('media-library::messages.management.fields.alt'))
                        ->placeholder('-'),
                    TextEntry::make('mime_type')
                        ->label(__('media-library::messages.management.fields.mime_type'))
                        ->placeholder('-'),
                    TextEntry::make('mime_source')
                        ->label(__('media-library::messages.management.fields.mime_source'))
                        ->badge(),
                    TextEntry::make('source')
                        ->label(__('media-library::messages.management.fields.source'))
                        ->badge(),
                    TextEntry::make('import_source')
                        ->label(__('media-library::messages.management.fields.import_source'))
                        ->placeholder('-'),
                ])
                ->columns(2),
            Section::make(__('media-library::messages.management.sections.storage'))
                ->description(__('media-library::messages.management.sections.storage_hint'))
                ->schema([
                    TextEntry::make('disk')
                        ->label(__('media-library::messages.management.fields.disk'))
                        ->copyable(),
                    TextEntry::make('object_key')
                        ->label(__('media-library::messages.management.fields.object_key'))
                        ->copyable(),
                    TextEntry::make('visibility')
                        ->label(__('media-library::messages.management.fields.visibility'))
                        ->badge(),
                    TextEntry::make('size')
                        ->label(__('media-library::messages.management.fields.size'))
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : static::humanSize($state)),
                ])
                ->columns(2),
            Section::make(__('media-library::messages.management.sections.usage'))
                ->schema(UsageReadout::revocablePanel()),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListMediaAssets::route('/'),
            'view' => ViewMediaAsset::route('/{record}'),
        ];
    }

    /**
     * @param  list<MediaSource|MimeSource>  $cases
     * @return array<string, string>
     */
    protected static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = __('media-library::messages.management.enums.'.$case->value);
        }

        return $options;
    }

    /**
     * A byte count as a person reads it. Kept here rather than pulled in as a
     * dependency, since it is one column and one entry.
     */
    protected static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / 1024 ** $power, $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
