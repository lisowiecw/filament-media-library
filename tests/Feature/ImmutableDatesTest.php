<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * An application is free to ask Laravel for immutable dates, and many do. The
 * date factory decides what `now()` returns and what a `datetime` cast reads
 * back as, so a signature written against the mutable subclass is a signature
 * that only works for half the applications out there.
 *
 * The factory is a global, so it is set and put back around each test here
 * rather than in the suite's own setup: every other test in the suite is a
 * test of the mutable half.
 */
beforeEach(function (): void {
    Date::use(CarbonImmutable::class);
});

afterEach(function (): void {
    Date::useDefault();
});

it('stamps the unattached clock when an external reference is the last one revoked', function (): void {
    $asset = libraryAsset();
    $asset->attachments()->createExternal('newsletter-2026-09');

    $asset->attachments()->revokeExternal('newsletter-2026-09');

    expect($asset->fresh()->unattached_since)->not->toBeNull();
});

it('stamps the unattached clock when a host detaches the last attachment', function (): void {
    $asset = libraryAsset();
    $host = article();
    attach($host, $asset);

    $host->detachMedia('cover_image', $asset);

    expect($asset->fresh()->unattached_since)->not->toBeNull();
});

it('leaves the clock unset while another reference remains', function (): void {
    $asset = libraryAsset();
    attach(article(), $asset);
    $asset->attachments()->createExternal('newsletter-2026-09');

    $asset->attachments()->revokeExternal('newsletter-2026-09');

    expect($asset->fresh()->unattached_since)->toBeNull();
});

it('reads the unattached clock back', function (): void {
    $asset = libraryAsset();
    $asset->attachments()->createExternal('newsletter-2026-09');
    $asset->attachments()->revokeExternal('newsletter-2026-09');

    expect($asset->fresh()->unattachedSince())->toBeInstanceOf(CarbonImmutable::class);
});

it('falls back to the creation date for an asset that was never attached', function (): void {
    expect(libraryAsset()->unattachedSince())->toBeInstanceOf(CarbonImmutable::class);
});

it('runs the unattached report', function (): void {
    $asset = storedAsset();
    $asset->forceFill(['created_at' => now()->subDays(40)])->save();

    $this->artisan('media:unattached-assets')
        ->expectsOutputToContain($asset->ulid)
        ->assertSuccessful();
});

it('leaves an asset out of the report until the grace period passes from the revocation', function (): void {
    $asset = storedAsset();
    $asset->forceFill(['created_at' => now()->subDays(40)])->save();
    $asset->attachments()->createExternal('newsletter-2026-09');
    $asset->attachments()->revokeExternal('newsletter-2026-09');

    $this->artisan('media:unattached-assets')
        ->doesntExpectOutputToContain($asset->ulid)
        ->assertSuccessful();

    MediaAsset::query()->whereKey($asset->getKey())->toBase()
        ->update(['unattached_since' => now()->subDays(40)]);

    $this->artisan('media:unattached-assets')
        ->expectsOutputToContain($asset->ulid)
        ->assertSuccessful();
});
