<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Delivery;

use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Http\Controllers\DeliveryController;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use RuntimeException;

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
        // route, so the signature is validated before a lookup happens. The
        // signature is what protects the id: an unsigned or tampered URL is
        // refused without the row ever being looked for.
        Route::get('media/{asset}', DeliveryController::class)
            ->middleware('signed')
            ->name(self::ROUTE_NAME);
    }

    /**
     * The route name for the current panel. There is no name outside a panel:
     * the route only exists inside one, so guessing Filament's prefix here
     * would hand back the name of a route that was never registered.
     */
    public static function name(): string
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            throw new RuntimeException('The Delivery route is registered per panel and has no name outside one.');
        }

        return $panel->generateRouteName(self::ROUTE_NAME);
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
     * A signed URL for one variant of an asset, which is how a private
     * Derivative's bytes are addressed at all.
     *
     * The expiry is quantized to a bucket boundary rather than counted from
     * now, so the URL is byte-identical for every render inside that window
     * and the browser's cache of the response is actually reachable. Nothing
     * about the check is weakened by it: View is still re-read on every hit,
     * and the window is a bound on a copied link, not a grant.
     */
    public static function derivativeUrl(MediaAsset $asset, DerivativeVariant $variant, ?string $digest = null): string
    {
        return URL::temporarySignedRoute(
            self::name(),
            self::bucketExpiry(),
            // The digest rides along so that a regeneration under changed
            // settings, which overwrites the object in place, hands out a URL
            // nothing has cached rather than leaving a stale rendering pinned
            // until the bucket rolls. The route itself ignores it.
            //
            // It is the digest recorded on the row, not the one the current
            // settings would produce: the URL has to move when the bytes move,
            // which is after a successful write, not when the setting is
            // edited. A row of unknown provenance carries none.
            array_filter([
                'asset' => $asset->ulid,
                'variant' => $variant->value,
                'digest' => $digest,
            ], fn (?string $value): bool => $value !== null),
        );
    }

    /**
     * How long the current bucket has left, which is how long a derivative
     * response may be cached: never past the signature that earned it.
     */
    public static function bucketRemaining(): int
    {
        return max(0, self::bucketExpiry()->getTimestamp() - now()->getTimestamp());
    }

    /**
     * The end of the bucket the current moment falls in, measured from the
     * epoch so every process agrees on where the boundary is without sharing
     * any state.
     */
    private static function bucketExpiry(): Carbon
    {
        $bucket = self::bucket();

        return Carbon::createFromTimestamp((intdiv(now()->getTimestamp(), $bucket) + 1) * $bucket);
    }

    /**
     * How wide a quantization bucket is, in seconds.
     */
    private static function bucket(): int
    {
        /** @var int $bucket */
        $bucket = config('media-library.derivative_url_bucket', 6 * 60 * 60);

        return max(1, $bucket);
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
