<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Ingest\ActiveContent;

it('names the types a browser would execute', function (string $mimeType): void {
    expect(ActiveContent::matches($mimeType))->toBeTrue();
})->with([
    'text/html',
    'application/xhtml+xml',
    'application/xml',
    'text/javascript',
    'application/x-msdownload',
    'application/x-executable',
]);

it('leaves ordinary content alone', function (?string $mimeType): void {
    expect(ActiveContent::matches($mimeType))->toBeFalse();
})->with([
    'image/png',
    'application/pdf',
    'text/plain',
    'image/svg+xml',
    null,
]);
