<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Lisowiecw\MediaLibrary\Filament\Actions\DeleteAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\DownloadAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\ForceDeleteAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\RenameAsset;
use Lisowiecw\MediaLibrary\Filament\Actions\RestoreAsset;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssetResource;

/**
 * One asset, read rather than edited: its storage identity, how its type was
 * arrived at, and the Usage list that decides whether it can go.
 *
 * The same actions the table row offers are repeated here, because this is
 * where the usage panel is, and reviewing usage is the point of the force
 * delete sitting next to it.
 */
class ViewMediaAsset extends ViewRecord
{
    protected static string $resource = MediaAssetResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            RenameAsset::make(),
            DownloadAsset::make(),
            DeleteAsset::make(),
            RestoreAsset::make(),
            ForceDeleteAsset::make(),
        ];
    }
}
