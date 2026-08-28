<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Delivery;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Lisowiecw\MediaLibrary\Http\Controllers\DeliveryController;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The single endpoint the plugin registers to serve a private Media Asset's
 * content, and the only supported way to address it.
 *
 * The route is internal. Its URL, its name and its parameters may change in
 * any release, which is why nothing outside the package should build either by
 * hand: ask here instead.
 *
 * One route is registered per panel, inside that panel's own middleware, so a
 * request is evaluated in the same context the picker that produced the URL
 * was rendered in.
 */
final readonly class DeliveryRoute
{
    /**
     * The unprefixed route name. Filament prefixes it per panel, which is why
     * callers ask for `name()` rather than using this.
     */
    private const string ROUTE_NAME = 'media-library.asset';

    /**
     * Called from inside a panel's route group, where the panel's middleware,
     * path prefix and name prefix are already applied.
     */
    public static function register(): void
    {
        // The asset is resolved in the controller rather than bound by the
        // route, so the signature is validated before a lookup happens: a
        // tampered URL is refused rather than answered with whether the id it
        // names exists.
        Route::get('media/{asset}', DeliveryController::class)
            ->middleware('signed')
            ->name(self::ROUTE_NAME);
    }

    /**
     * The route name for the current panel, or the default one outside a
     * panel request.
     */
    public static function name(): string
    {
        return Filament::getCurrentOrDefaultPanel()?->generateRouteName(self::ROUTE_NAME)
            ?? 'filament.'.self::ROUTE_NAME;
    }

    /**
     * A freshly signed URL. The signature is generated per render rather than
     * cached, so the window a copied URL survives in is the configured TTL
     * counted from the moment the page was drawn.
     */
    public static function signedUrl(MediaAsset $asset, bool $download = false): string
    {
        return URL::temporarySignedRoute(
            self::name(),
            now()->addSeconds(self::ttl()),
            $download
                ? ['asset' => $asset->ulid, 'download' => 1]
                : ['asset' => $asset->ulid],
        );
    }

    /**
     * How long a signature lasts, in seconds. The same window bounds a disk's
     * own temporary URL, so a redirect never outlives the request that earned
     * it by more than the route signature would have.
     */
    public static function ttl(): int
    {
        /** @var int $ttl */
        $ttl = config('media-library.signed_url_ttl', 5 * 60);

        return $ttl;
    }
}
