<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tenancy;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Throwable;

/**
 * Who the current request belongs to, as the plugin knows it.
 *
 * Tenancy enters through one resolver on the panel plugin, so the answer is a
 * property of the panel a request is being served by rather than of the
 * package: a tenanted panel and an untenanted one can sit in the same
 * application, and an application that configures neither is untouched by
 * every method here.
 *
 * The plugin never inspects a host model's tenancy. It only ever knows the
 * resolved value, which is why a tenant is a string on the asset rather than a
 * relationship to something the package would have to understand.
 *
 * See ADR 7: the scope decides what is offered, the policy decides what is
 * delivered, and neither of them is the other's backstop.
 */
final class Tenancy
{
    /**
     * How an operator says "no tenant" on a command line, where an absent
     * option already means "not stated" and cannot also mean "none".
     */
    public const string NONE = 'none';

    /**
     * Whether the panel serving this request knows about tenants at all. An
     * unset resolver on a panel without tenancy of its own means the plugin is
     * not tenanted, and every other method here says so.
     */
    public static function isEnabled(): bool
    {
        $panel = self::panel();

        return $panel !== null && self::plugin($panel)?->isTenanted($panel) === true;
    }

    /**
     * The tenant this request belongs to, or null where there is none: no
     * panel, no tenancy, or a resolver that answered with nothing.
     *
     * Null is not "every tenant". A request that cannot name its tenant is
     * offered nothing and delivered nothing, which is the fail-closed half of
     * ADR 7.
     */
    public static function current(): ?string
    {
        $panel = self::panel();

        if ($panel === null) {
            return null;
        }

        return self::plugin($panel)?->resolveTenant($panel);
    }

    /**
     * The key a resolved tenant is stamped as. A model answers with its own
     * key, since that is the value a host application would have joined on
     * anyway, and anything scalar is taken as already being the key.
     */
    public static function key(mixed $tenant): ?string
    {
        if ($tenant instanceof Model) {
            /** @var int|string|null $key */
            $key = $tenant->getKey();

            return $key === null ? null : (string) $key;
        }

        if (is_string($tenant) || is_int($tenant)) {
            return (string) $tenant;
        }

        return null;
    }

    /**
     * What an upload made in this request is stamped with. Stamping happens
     * once, at upload, and nothing reassigns it afterwards.
     */
    public static function stamp(): ?string
    {
        return self::current();
    }

    /**
     * Narrow a query to what this request may be offered.
     *
     * An untenanted asset belongs to no one rather than to everyone, so it is
     * not here either: a site that configures a resolver after years of
     * single-tenant use inherits a library nobody sees, which is a visible
     * incomplete state rather than every customer's history published at once.
     *
     * @param  Builder<MediaAsset>  $query
     */
    public static function scope(Builder $query): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $current = self::current();

        if ($current === null) {
            // Nothing rather than everything. A request inside a tenanted
            // panel that cannot say which tenant it is has not earned a
            // library.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('tenant_id', $current);
    }

    /**
     * Whether this asset sits outside the current tenant's boundary, which is
     * true of another tenant's asset and of an unclaimed one alike.
     *
     * This is the comparison and nothing more: whether a cross-tenant reader
     * is allowed anyway is `viewAllTenants`, and that question belongs to the
     * authorization seam.
     */
    public static function excludes(MediaAsset $asset): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        $current = self::current();

        return $current === null || $asset->tenant_id !== $current;
    }

    /**
     * The panel this request is being served by, or the default panel where
     * Filament can name one. Nothing here decides that a command or a job is
     * unscoped: those paths are unscoped because none of them asks this seam
     * anything, which is the boundary being in the panel rather than in the
     * worker. A worker that stopped generating derivatives for tenanted
     * assets would be a boundary in the wrong place.
     */
    private static function panel(): ?Panel
    {
        try {
            return Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            // Filament is installed but has no panel to speak of, which is
            // every context that never boots one.
            return null;
        }
    }

    private static function plugin(Panel $panel): ?MediaLibraryPlugin
    {
        if (! $panel->hasPlugin('media-library')) {
            return null;
        }

        $plugin = $panel->getPlugin('media-library');

        return $plugin instanceof MediaLibraryPlugin ? $plugin : null;
    }
}
