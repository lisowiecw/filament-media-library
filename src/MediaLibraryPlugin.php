<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;

class MediaLibraryPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'media-library';
    }

    /**
     * The Delivery route is registered per panel, inside that panel's own
     * middleware, so a request for private content is evaluated in the same
     * context the picker that produced its URL was rendered in.
     */
    public function register(Panel $panel): void
    {
        $panel->routes(function (): void {
            DeliveryRoute::register();
        });
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
