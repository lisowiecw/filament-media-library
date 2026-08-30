<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssetResource;

class MediaLibraryPlugin implements Plugin
{
    /**
     * Whether this panel gets the management page as well as the picker.
     *
     * Off by default and opted into per panel, because the resource is a
     * different audience from the field: an application that only wants
     * editors to attach files should not have to write a policy to keep the
     * whole library out of its navigation.
     */
    protected bool $hasLibraryManagement = false;

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'media-library';
    }

    /**
     * Opt this panel into the `MediaAssetResource` management page.
     *
     * Opting in is not the same as opening it up: the page is still gated by
     * the `viewAny` ability, which fails closed until the application's own
     * policy says otherwise.
     */
    public function withLibraryManagement(bool $condition = true): static
    {
        $this->hasLibraryManagement = $condition;

        return $this;
    }

    public function hasLibraryManagement(): bool
    {
        return $this->hasLibraryManagement;
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

        if ($this->hasLibraryManagement) {
            $panel->resources([MediaAssetResource::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
