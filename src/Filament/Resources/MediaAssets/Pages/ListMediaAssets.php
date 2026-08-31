<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Resources\MediaAssets\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssetResource;
use Lisowiecw\MediaLibrary\Tenancy\Tenancy;

/**
 * The library as a table. Everything it can do hangs off the resource, so the
 * page itself is only the mounting point, and the one thing it holds of its
 * own is whether the listing is scoped to a tenant.
 *
 * The listing is scoped by default. Opting a panel into management is a
 * decision about an audience, not about a boundary, so the unscoped view is a
 * second decision: it is reachable only through `viewAllTenants`, which fails
 * closed, and it is a toggle rather than a page so that a librarian who does
 * hold it is never left wondering which of the two they are looking at.
 */
class ListMediaAssets extends ListRecords
{
    protected static string $resource = MediaAssetResource::class;

    /**
     * Asked for rather than assumed, even where the ability is held: the
     * scoped listing is what a person came here to read.
     */
    public bool $allTenants = false;

    /**
     * Whether the query is unscoped right now. The ability is re-read here
     * rather than trusted from the moment the toggle was pressed, so a
     * withdrawn ability takes effect on the next render.
     */
    public function isShowingAllTenants(): bool
    {
        return $this->allTenants && app(MediaAuthorization::class)->allowsAllTenants();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('allTenants')
                ->label(fn (): string => __($this->allTenants
                    ? 'media-library::messages.management.actions.this_tenant'
                    : 'media-library::messages.management.actions.all_tenants'))
                ->icon('heroicon-m-globe-alt')
                ->color('gray')
                ->visible(fn (): bool => Tenancy::isEnabled() && app(MediaAuthorization::class)->allowsAllTenants())
                ->action(function (): void {
                    $this->allTenants = ! $this->allTenants;

                    // The selection was made under the other listing, and half
                    // of it may be about to disappear from view.
                    $this->deselectAllTableRecords();
                }),
        ];
    }
}
