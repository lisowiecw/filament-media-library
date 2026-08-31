<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Articles\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Workbench\App\Filament\Resources\Articles\ArticleResource;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
