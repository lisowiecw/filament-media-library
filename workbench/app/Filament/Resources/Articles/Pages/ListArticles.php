<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Articles\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Workbench\App\Filament\Resources\Articles\ArticleResource;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
