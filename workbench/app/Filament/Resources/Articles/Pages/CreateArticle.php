<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Resources\Articles\Pages;

use Filament\Resources\Pages\CreateRecord;
use Workbench\App\Filament\Resources\Articles\ArticleResource;

/**
 * Creating exercises the half of the picker a test cannot skip past: the field
 * holds a list of asset ids for a record that does not exist yet, and the
 * attachments are reconciled once the save has given it an id.
 */
class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;
}
