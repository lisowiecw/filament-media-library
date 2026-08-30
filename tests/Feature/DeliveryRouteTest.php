<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

beforeEach(function (): void {
    HostPolicy::$allows = true;
    HostPolicy::$evaluations = 0;
    Gate::policy(MediaAsset::class, HostPolicy::class);

    $this->actingAs(user());
});

/**
 * The content policy every Delivery response has to carry.
 */
function contentPolicy(): string
{
    return "default-src 'none'; style-src 'unsafe-inline'; sandbox";
}

it('streams a private asset the policy allows', function (): void {
    $asset = storedAsset();

    $response = $this->get(DeliveryRoute::signedUrl($asset));

    $response->assertOk();
    expect($response->streamedContent())->toBe('the bytes');
});

it('carries the content policy on every response', function (): void {
    $response = $this->get(DeliveryRoute::signedUrl(storedAsset()));

    $response->assertHeader('content-security-policy', contentPolicy());
});

it('refuses a private asset the policy denies', function (): void {
    HostPolicy::$allows = false;

    $this->get(DeliveryRoute::signedUrl(storedAsset()))->assertForbidden();
});

it('re-checks the policy on every request', function (): void {
    $url = DeliveryRoute::signedUrl(storedAsset());

    $this->get($url)->assertOk();

    HostPolicy::$allows = false;

    $this->get($url)->assertForbidden();

    expect(HostPolicy::$evaluations)->toBe(2);
});

it('refuses a URL that was never signed', function (): void {
    $asset = storedAsset();

    $this->get(route(DeliveryRoute::name(), ['asset' => $asset->ulid]))->assertForbidden();
});

it('refuses a signature that has expired', function (): void {
    $url = DeliveryRoute::signedUrl(storedAsset());

    $this->travel(config('media-library.signed_url_ttl') + 1)->seconds();

    $this->get($url)->assertForbidden();
});

it('refuses a URL whose asset id was swapped rather than saying whether it exists', function (): void {
    $asset = storedAsset();
    $url = str_replace($asset->ulid, (string) Str::ulid(), DeliveryRoute::signedUrl($asset));

    $this->get($url)->assertForbidden();
});

it('answers an unknown asset with a not found', function (): void {
    $missing = (string) Str::ulid();

    $this->get(URL::temporarySignedRoute(DeliveryRoute::name(), now()->addMinute(), ['asset' => $missing]))
        ->assertNotFound();
});

it('renders in place what the disposition rule has earned', function (): void {
    $response = $this->get(DeliveryRoute::signedUrl(storedAsset()));

    $response->assertHeader('content-disposition', 'inline; filename="holiday photo.jpg"');
});

it('serves for saving when a download is asked for', function (): void {
    withoutTemporaryUrls();

    $response = $this->get(DeliveryRoute::signedUrl(storedAsset(), download: true));

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('ignores a request to render active content in place', function (): void {
    withoutTemporaryUrls();

    $asset = storedAsset(['mime_type' => 'text/html', 'mime_source' => MimeSource::Sniffed]);

    $url = URL::temporarySignedRoute(DeliveryRoute::name(), now()->addMinute(), [
        'asset' => $asset->ulid,
        'download' => 0,
    ]);

    $response = $this->get($url);

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('serves for saving what the mime type was only guessed for', function (): void {
    withoutTemporaryUrls();

    $asset = storedAsset(['mime_source' => MimeSource::Extension]);

    $response = $this->get(DeliveryRoute::signedUrl($asset));

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('redirects a download to the disk temporary URL where the disk has one', function (): void {
    $asked = [];

    Storage::disk('media')->buildTemporaryUrlsUsing(
        function (string $path, DateTimeInterface $expiry, array $options) use (&$asked): string {
            $asked = $options;

            return 'https://bucket.test/'.$path;
        },
    );

    $asset = storedAsset();

    $response = $this->get(DeliveryRoute::signedUrl($asset, download: true));

    $response->assertRedirect('https://bucket.test/'.$asset->object_key)
        ->assertHeader('content-security-policy', contentPolicy());

    expect($asked['ResponseContentDisposition'])->toStartWith('attachment');
});

it('streams rather than redirects what renders in place, so the content policy survives', function (): void {
    Storage::disk('media')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiry, array $options): string => 'https://bucket.test/'.$path,
    );

    $this->get(DeliveryRoute::signedUrl(storedAsset()))
        ->assertOk()
        ->assertHeader('content-security-policy', contentPolicy());
});

it('registers the route inside the panel middleware', function (): void {
    expect(DeliveryRoute::name())->toBe('filament.admin.media-library.asset')
        ->and(DeliveryRoute::signedUrl(storedAsset()))->toContain('/admin/media/');
});

describe('the variant parameter', function (): void {
    it('streams a private derivative through the same checked route', function (): void {
        $asset = storedAsset();
        $derivative = readyDerivative($asset);

        $response = $this->get($derivative->url());

        $response->assertOk()
            ->assertHeader('content-type', 'image/webp')
            ->assertHeader('content-security-policy', contentPolicy());

        expect($response->streamedContent())->toBe('the rendering');
    });

    it('re-checks view for a derivative too', function (): void {
        $url = readyDerivative(storedAsset())->url();

        HostPolicy::$allows = false;

        $this->get($url)->assertForbidden();
    });

    it('renders a derivative in place whatever the parent disposition would be', function (): void {
        $asset = storedAsset(['mime_source' => MimeSource::Extension]);

        $response = $this->get(readyDerivative($asset)->url());

        expect($response->headers->get('content-disposition'))->toStartWith('inline');
    });

    it('carries a private immutable caching instruction that ends with the bucket', function (): void {
        $bucket = (int) config('media-library.derivative_url_bucket');
        $this->travelTo(Carbon::createFromTimestamp(intdiv(now()->getTimestamp(), $bucket) * $bucket));

        $response = $this->get(readyDerivative(storedAsset())->url());

        expect($response->headers->get('cache-control'))
            ->toContain('private')
            ->toContain('immutable')
            ->toContain('max-age='.$bucket);
    });

    it('names a saved derivative after its parent', function (): void {
        $asset = storedAsset();

        $response = $this->get(readyDerivative($asset)->url());

        expect($response->headers->get('content-disposition'))->toContain('holiday photo-thumb.webp');
    });

    it('streams rather than presigning, whatever the disk offers', function (): void {
        Storage::disk('media')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiry, array $options): string => 'https://bucket.test/'.$path,
        );

        $this->get(readyDerivative(storedAsset())->url())->assertOk();
    });

    it('hands out no URL for a derivative that is not ready', function (): void {
        $asset = storedAsset();
        $derivative = readyDerivative($asset);
        $derivative->update(['status' => DerivativeStatus::Pending->value]);

        expect($derivative->url())->toBeNull();
    });

    it('answers a variant that was never generated with a not found', function (): void {
        $asset = storedAsset();
        readyDerivative($asset);

        $url = URL::temporarySignedRoute(DeliveryRoute::name(), now()->addMinute(), [
            'asset' => $asset->ulid,
            'variant' => DerivativeVariant::Preview->value,
        ]);

        $this->get($url)->assertNotFound();
    });

    it('answers an unknown variant name with a not found', function (): void {
        $asset = storedAsset();

        $url = URL::temporarySignedRoute(DeliveryRoute::name(), now()->addMinute(), [
            'asset' => $asset->ulid,
            'variant' => 'gigantic',
        ]);

        $this->get($url)->assertNotFound();
    });

    it('refuses an unsigned variant URL', function (): void {
        $asset = storedAsset();
        readyDerivative($asset);

        $this->get(route(DeliveryRoute::name(), [
            'asset' => $asset->ulid,
            'variant' => DerivativeVariant::Thumb->value,
        ]))->assertForbidden();
    });

    it('resolves a public parent derivative at the disk rather than the route', function (): void {
        $asset = storedAsset(['visibility' => 'public']);
        $derivative = readyDerivative($asset);

        expect($derivative->url())->not->toContain('/admin/media/');

        $url = URL::temporarySignedRoute(DeliveryRoute::name(), now()->addMinute(), [
            'asset' => $asset->ulid,
            'variant' => DerivativeVariant::Thumb->value,
        ]);

        $this->get($url)->assertNotFound();
    });
});

describe('derivative URL quantization', function (): void {
    it('is byte-stable within one bucket', function (): void {
        $asset = storedAsset();

        // Anchored to a boundary, so the half-bucket step below cannot land in
        // the next window and pass the test for the wrong reason.
        $bucket = (int) config('media-library.derivative_url_bucket');
        $this->travelTo(Carbon::createFromTimestamp(intdiv(now()->getTimestamp(), $bucket) * $bucket));

        $first = DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb);

        $this->travel($bucket / 2)->seconds();

        expect(DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb))->toBe($first);
    });

    it('changes across a boundary', function (): void {
        $asset = storedAsset();

        $first = DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb);

        $this->travel(config('media-library.derivative_url_bucket') + 1)->seconds();

        expect(DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb))->not->toBe($first);
    });

    it('changes when the digest recorded on the rendering changes', function (): void {
        $asset = storedAsset();

        $first = DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb, 'abc123');

        // The URL moves with the bytes, which move on a successful write, not
        // with the setting that will eventually produce them.
        expect(DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb, 'abc123'))->toBe($first)
            ->and(DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb, 'def456'))->not->toBe($first);
    });

    it('leaves the original on its own per-render signature', function (): void {
        $asset = storedAsset();

        $first = DeliveryRoute::signedUrl($asset);

        $this->travel(60)->seconds();

        expect(DeliveryRoute::signedUrl($asset))->not->toBe($first);
    });

    it('still expires, so a copied derivative URL does not live forever', function (): void {
        $asset = storedAsset();
        $url = DeliveryRoute::derivativeUrl($asset, DerivativeVariant::Thumb);

        $this->travel(config('media-library.derivative_url_bucket') + 1)->seconds();

        $this->get($url)->assertForbidden();
    });
});
