<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Actions;

use Filament\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tenancy\Claim;
use Lisowiecw\MediaLibrary\Tenancy\Tenancy;

/**
 * Claiming the untenanted pile, out of the listing that is the only place it
 * is visible.
 *
 * Claiming is one way and allowed once, so a row that already has an owner is
 * skipped and said so rather than moved. That is the whole difference between
 * this and an edit: there is no tenant to choose here, only the tenant the
 * panel resolved for whoever is doing the claiming.
 */
final readonly class ClaimAssetsForTenant
{
    public static function make(): BulkAction
    {
        return BulkAction::make('claim')
            ->label(__('media-library::messages.management.actions.claim'))
            ->icon('heroicon-m-hand-raised')
            ->visible(fn (): bool => Tenancy::isEnabled()
                && Tenancy::current() !== null
                && app(MediaAuthorization::class)->allowsAllTenants())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('media-library::messages.management.modals.claim', [
                'tenant' => Tenancy::current() ?? '',
            ]))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $tenant = Tenancy::current();

                if ($tenant === null) {
                    return;
                }

                $report = new BulkReport;

                /** @var MediaAsset $record */
                foreach ($records as $record) {
                    if (Claim::assign($record, $tenant)) {
                        $report->did();

                        continue;
                    }

                    $report->skipped('owned', $record);
                }

                $report->send('bulk_claimed');
            });
    }
}
