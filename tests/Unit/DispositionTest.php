<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Delivery\Disposition;
use Lisowiecw\MediaLibrary\Enums\MimeSource;

it('renders in place when the type is not active content and was not guessed from a filename', function (MimeSource $source): void {
    $asset = makeAsset(['mime_type' => 'image/jpeg', 'mime_source' => $source]);

    expect(Disposition::for($asset, download: false))->toBe(Disposition::INLINE);
})->with([MimeSource::Header, MimeSource::Sniffed]);

it('serves for saving when the type came from the filename alone', function (MimeSource $source): void {
    $asset = makeAsset(['mime_type' => 'image/jpeg', 'mime_source' => $source]);

    expect(Disposition::for($asset, download: false))->toBe(Disposition::ATTACHMENT);
})->with([MimeSource::Extension, MimeSource::Unknown]);

it('serves active content for saving however it was typed', function (): void {
    $asset = makeAsset(['mime_type' => 'text/html', 'mime_source' => MimeSource::Sniffed]);

    expect(Disposition::for($asset, download: false))->toBe(Disposition::ATTACHMENT);
});

it('forces saving when a download is asked for', function (): void {
    $asset = makeAsset(['mime_type' => 'image/jpeg', 'mime_source' => MimeSource::Sniffed]);

    expect(Disposition::for($asset, download: true))->toBe(Disposition::ATTACHMENT);
});
