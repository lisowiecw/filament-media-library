<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Filament\Tables;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tenancy\Tenancy;

/**
 * Which tenant's assets the unscoped listing is showing, including the pile
 * that belongs to no one.
 *
 * It only ever appears beside the unscoped listing, because on a scoped one it
 * would be a dimension with a single option: the tenant reading it. The
 * untenanted option is the one an operator comes here for, since that is the
 * set a claim is made out of.
 */
final readonly class TenantFilter
{
    public const string ANY = 'any';

    public const string UNTENANTED = 'untenanted';

    public static function make(): Filter
    {
        return Filter::make('tenant')
            ->visible(fn (): bool => self::isAvailable())
            ->schema([
                Select::make('tenant')
                    ->label(__('media-library::messages.management.filters.tenant'))
                    ->options(fn (): array => self::options())
                    ->default(self::ANY),
            ])
            ->query(function (Builder $query, array $data): void {
                /** @var Builder<MediaAsset> $query */
                /** @var string $tenant */
                $tenant = $data['tenant'] ?? self::ANY;

                match ($tenant) {
                    self::ANY => null,
                    self::UNTENANTED => $query->whereNull('tenant_id'),
                    default => $query->where('tenant_id', $tenant),
                };
            })
            ->indicateUsing(function (array $data): ?string {
                /** @var string $tenant */
                $tenant = $data['tenant'] ?? self::ANY;

                return $tenant === self::ANY ? null : (self::options()[$tenant] ?? $tenant);
            });
    }

    /**
     * The facet is a cross-tenant reading of the library, so it is behind the
     * same ability the unscoped listing is.
     */
    public static function isAvailable(): bool
    {
        return Tenancy::isEnabled() && app(MediaAuthorization::class)->allowsAllTenants();
    }

    /**
     * The tenants the library actually holds, read from the assets rather than
     * from the application: the plugin knows a resolved value and never the
     * thing it was resolved from, so it has nothing else to list.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        /** @var list<string> $tenants */
        $tenants = MediaAsset::query()
            ->withoutGlobalScopes()
            ->whereNotNull('tenant_id')
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->all();

        return [
            self::ANY => __('media-library::messages.management.filters.tenant_any'),
            self::UNTENANTED => __('media-library::messages.management.filters.tenant_untenanted'),
            ...array_combine($tenants, $tenants),
        ];
    }
}
