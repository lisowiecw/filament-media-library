<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;

use function Orchestra\Testbench\workbench_path;

/**
 * The panel `composer serve` boots, which is an ordinary Filament panel with
 * the plugin registered on it and nothing else unusual.
 *
 * Management is opted in, because the point of the example is to show both
 * halves of the promised surface: the picker an editor uses on a host record,
 * and the library page a librarian uses on its own.
 *
 * The panel is deliberately untenanted. Tenancy is a property of the host
 * application's panel rather than of the plugin, so demonstrating it would mean
 * inventing a tenant model the package has no opinion about, and every page
 * here would then be read as though the scoping were the plugin's doing. What
 * tenancy does to the library is covered by the suite instead.
 */
class WorkbenchPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors(['primary' => Color::Amber])
            ->discoverResources(
                in: workbench_path('app/Filament/Resources'),
                for: 'Workbench\App\Filament\Resources',
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                ConvertEmptyStringsToNull::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugin(MediaLibraryPlugin::make()->withLibraryManagement());
    }
}
