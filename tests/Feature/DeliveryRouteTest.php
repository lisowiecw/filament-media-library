<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
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

/**
 * An asset whose bytes are actually on the faked disk.
 */
function storedAsset(array $overrides = []): MediaAsset
{
    $asset = makeAsset(array_merge([
        'object_key' => 'media/'.Str::random(12).'.jpg',
        'mime_type' => 'image/jpeg',
        'mime_source' => MimeSource::Sniffed,
        'visibility' => 'private',
    ], $overrides));

    Storage::disk($asset->disk)->put($asset->object_key, 'the bytes');

    return $asset;
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
