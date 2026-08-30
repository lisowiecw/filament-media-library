<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages;

use Filament\Resources\Pages\ListRecords;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssetResource;

/**
 * The library as a table. Everything it can do hangs off the resource, so the
 * page itself is only the mounting point.
 */
class ListMediaAssets extends ListRecords
{
    protected static string $resource = MediaAssetResource::class;
}
