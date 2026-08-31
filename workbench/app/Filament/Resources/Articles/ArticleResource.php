<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Articles;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Forms\Components\MediaPicker;
use Workbench\App\Filament\Resources\Articles\Pages\CreateArticle;
use Workbench\App\Filament\Resources\Articles\Pages\EditArticle;
use Workbench\App\Filament\Resources\Articles\Pages\ListArticles;
use Workbench\App\Models\Article;

/**
 * An ordinary host resource, which is the point: nothing here is package code.
 * An article has a title and two picker fields, and the two between them stand
 * for the whole of the field's configuration surface.
 *
 * `cover_image` is the single, public case. `gallery` is the multiple, private,
 * ordered one. Reading them back is `$article->firstMedia('cover_image')` and
 * `$article->media('gallery')`; neither is a column on the articles table.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Article')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                ]),
            Section::make('Media')
                ->description('Two fields, one host record, no media column on it.')
                ->schema([
                    // Single, public, images only. A public placement lands in
                    // `MEDIA_LIBRARY_PUBLIC_DISK` and is served by that disk's
                    // own URL, so no request ever reaches the package for it.
                    MediaPicker::make('cover_image')
                        ->label('Cover image')
                        ->helperText('One image, public. Drag a file onto the field or pick one from the library.')
                        ->visibility('public')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->droppable(),

                    // Multiple, ordered, private, and narrowed. A private
                    // placement lands in `MEDIA_LIBRARY_PRIVATE_DISK` and every
                    // read of it is authorized through the Delivery route.
                    MediaPicker::make('gallery')
                        ->label('Gallery')
                        ->helperText('Many files, private, drag to reorder. Dropping is off here, so the modal is the only way in.')
                        ->visibility('private')
                        ->multiple()
                        ->reorderable()
                        ->droppable(false)
                        // What this field is allowed to offer, which is a
                        // narrower question than what the viewer may see.
                        ->scopeLibrary(fn (Builder $query): Builder => $query->where('visibility', Visibility::Private)),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
