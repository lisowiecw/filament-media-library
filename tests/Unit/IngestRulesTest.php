<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Ingest\IngestRules;

it('defaults to the configured size and denylist', function (): void {
    $rules = IngestRules::resolve();

    expect($rules->maxUploadSize)->toBe(12 * 1024)
        ->and($rules->blockedTypes)->toContain('php', 'phar', 'application/x-httpd-php')
        ->and($rules->acceptedTypes)->toBeNull();
});

it('lets a field move the size in either direction', function (): void {
    expect(IngestRules::resolve(maxUploadSize: 512)->maxUploadSize)->toBe(512)
        ->and(IngestRules::resolve(maxUploadSize: 60 * 1024)->maxUploadSize)->toBe(60 * 1024);
});

it('only ever adds to the denylist, never replaces it', function (): void {
    $rules = IngestRules::resolve(blockedTypes: ['.exe']);

    expect($rules->blockedTypes)->toContain('php', 'exe');
});

it('matches a blocked type on the extension and on the resolved mime alike', function (): void {
    $rules = IngestRules::resolve();

    expect($rules->blocks('php', 'text/plain'))->toBeTrue()
        ->and($rules->blocks('txt', 'application/x-httpd-php'))->toBeTrue()
        ->and($rules->blocks('png', 'image/png'))->toBeFalse();
});

it('accepts everything unblocked when the field names no accepted types', function (): void {
    expect(IngestRules::resolve()->accepts('png', 'image/png'))->toBeTrue();
});

it('passes a csv sniffing as plain text through a csv gate', function (): void {
    $rules = IngestRules::resolve(acceptedTypes: ['text/csv']);

    expect($rules->accepts('csv', 'text/plain'))->toBeTrue();
});

it('accepts an extension token with or without its dot', function (): void {
    expect(IngestRules::resolve(acceptedTypes: ['.csv'])->accepts('csv', 'text/plain'))->toBeTrue();
});

it('accepts a family wildcard', function (): void {
    $rules = IngestRules::resolve(acceptedTypes: ['image/*']);

    expect($rules->accepts('png', 'image/png'))->toBeTrue()
        ->and($rules->accepts('txt', 'text/plain'))->toBeFalse();
});

it('refuses a type the field does not name', function (): void {
    expect(IngestRules::resolve(acceptedTypes: ['image/png'])->accepts('pdf', 'application/pdf'))->toBeFalse();
});

it('measures the size in whole kilobytes', function (): void {
    $rules = IngestRules::resolve(maxUploadSize: 1);

    expect($rules->exceedsMaxUploadSize(1024))->toBeFalse()
        ->and($rules->exceedsMaxUploadSize(1025))->toBeTrue();
});
