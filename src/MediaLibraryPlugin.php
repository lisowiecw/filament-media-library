<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Delivery\DownloadFilename;
use Lisowiecw\MediaLibrary\Filament\Resources\MediaAssetResource;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

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
     * Decide what a saved file is called on the viewer's disk, for every asset
     * the application ever serves.
     *
     * The closure is handed the Media Asset alone, so it cannot vary by host:
     * an asset can be attached in many places, and the Stored header is
     * written at upload before any attachment exists. Registered once, and
     * global rather than per panel, since the same resolver has to answer for
     * an upload made from anywhere.
     *
     * Its answer is scrubbed by the Readable name rules and given the asset's
     * extension where it returns a stem without one, so a title is enough.
     *
     * @param  Closure(MediaAsset): string | null  $resolver
     */
    public function downloadFilenameUsing(?Closure $resolver): static
    {
        DownloadFilename::resolveUsing($resolver);

        return $this;
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
