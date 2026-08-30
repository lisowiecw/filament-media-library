<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\Action;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Handing an operator the asset's own bytes.
 *
 * It always sends them to the Delivery route, public assets included, because
 * a link's `download` attribute is ignored cross-origin and the plugin assumes
 * public placement is a foreign origin. There is therefore no visibility
 * branch here: `downloadUrl()` already knows, and the route re-checks View on
 * the way through. See ADR-0001.
 */
final readonly class DownloadAsset
{
    public static function make(): Action
    {
        return Action::make('download')
            ->label(__('media-library::messages.management.actions.download'))
            ->icon('heroicon-m-arrow-down-tray')
            ->authorize('view')
            ->url(fn (MediaAsset $record): string => $record->downloadUrl())
            ->openUrlInNewTab();
    }
}
