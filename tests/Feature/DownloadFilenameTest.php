<?php

declare(strict_types=1);

use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Ingest\IngestService;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

beforeEach(function (): void {
    Gate::policy(MediaAsset::class, HostPolicy::class);

    // The saved header is the response's own here, rather than a redirect to
    // a disk that would have carried it as an override.
    withoutTemporaryUrls();

    $this->actingAs(user());
});

function resolveDownloadFilename(Closure $resolver): void
{
    MediaLibraryPlugin::make()->downloadFilenameUsing($resolver);
}

/**
 * The saved name the route settles on, with the disk's temporary URLs out of
 * the way so the header is the response's own.
 */
function savedDisposition(MediaAsset $asset): string
{
    $response = test()->get(DeliveryRoute::signedUrl($asset, download: true));
    $response->assertOk();

    return (string) $response->headers->get('content-disposition');
}

function storedDisposition(MediaAsset $asset): ?string
{
    return app(IngestService::class)->storedHeaders($asset)['ContentDisposition'] ?? null;
}

it('names a saved file after the uploader filename when no resolver is registered', function (): void {
    $asset = storedAsset(['original_client_filename' => 'holiday photo.jpg']);

    expect(savedDisposition($asset))->toBe('attachment; filename="holiday photo.jpg"');
});

it('falls back to the display name where the asset has no original filename', function (): void {
    $asset = storedAsset(['original_client_filename' => null, 'display_name' => 'Holiday photo']);

    expect(savedDisposition($asset))->toContain('filename="Holiday photo.jpg"');
});

it('lets the application name the saved file', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => 'Annual report.pdf');

    expect(savedDisposition(storedAsset()))->toBe('attachment; filename="Annual report.pdf"');
});

it('appends the recorded extension to a stem the resolver returns without one', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => $asset->display_name);

    expect(savedDisposition(storedAsset(['display_name' => 'Annual report'])))
        ->toBe('attachment; filename="Annual report.jpg"');
});

it('refuses to let a resolver break the header it is written into', function (): void {
    resolveDownloadFilename(
        fn (MediaAsset $asset): string => "../evil\r\nX-Injected: yes\".jpg",
    );

    $disposition = savedDisposition(storedAsset());

    expect($disposition)->not->toContain("\r")
        ->and($disposition)->not->toContain("\n")
        ->and($disposition)->toBe('attachment; filename="evilX-Injected: yes\".jpg"');
});

it('falls back to the default where a resolver answers with nothing usable', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => '   ');

    expect(savedDisposition(storedAsset()))->toContain('filename="holiday photo.jpg"');
});

it('carries a non-ASCII name beside an ASCII fallback', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => 'Jahresbericht Ü.pdf');

    expect(savedDisposition(storedAsset()))
        ->toBe("attachment; filename=\"Jahresbericht U.pdf\"; filename*=utf-8''Jahresbericht%20%C3%9C.pdf");
});

it('asks the same resolver for the disposition written onto the object at upload', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => 'Annual report.pdf');

    $asset = makeAsset(['mime_type' => 'text/html', 'mime_source' => MimeSource::Sniffed]);

    expect(storedDisposition($asset))->toBe('attachment; filename="Annual report.pdf"');
});

it('scrubs a header-breaking resolver answer before it reaches a stored header', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => "evil\r\nX-Injected: yes.html");

    $asset = makeAsset(['mime_type' => 'text/html', 'mime_source' => MimeSource::Sniffed]);

    expect(storedDisposition($asset))->toBe('attachment; filename="evilX-Injected: yes.html"');
});

it('asks the disk for the same name when it redirects instead of streaming', function (): void {
    resolveDownloadFilename(fn (MediaAsset $asset): string => 'Annual report.pdf');

    $asked = [];

    config()->set('filesystems.disks.media.serve', true);
    Storage::forgetDisk('media');
    Storage::disk('media')->buildTemporaryUrlsUsing(
        function (string $path, DateTimeInterface $expiry, array $options) use (&$asked): string {
            $asked = $options;

            return 'https://bucket.test/'.$path;
        },
    );

    $this->get(DeliveryRoute::signedUrl(storedAsset(), download: true))->assertRedirect();

    expect($asked['ResponseContentDisposition'])->toBe('attachment; filename="Annual report.pdf"');
});

it('calls a file with no name of its own a download', function (): void {
    $asset = new MediaAsset(['mime_type' => 'text/html', 'mime_source' => MimeSource::Sniffed]);

    expect(storedDisposition($asset))->toBe('attachment; filename=download');
});
