<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Attachments\AttachmentReconciler;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

beforeEach(function (): void {
    HostPolicy::$allows = true;
    Gate::policy(MediaAsset::class, HostPolicy::class);

    $this->actingAs(user());
});

/**
 * The base URL an application configures on the disk, which is the only place
 * a CDN host is named. Re-resolving the disk is what makes the change take.
 */
function withDiskUrl(string $url = 'https://cdn.test/files', string $disk = 'media'): void
{
    config()->set('filesystems.disks.'.$disk.'.url', $url);

    Storage::forgetDisk($disk);
}

function publicAsset(): MediaAsset
{
    return makeAsset([
        'object_key' => 'media/'.Str::random(12).'.jpg',
        'visibility' => 'public',
    ]);
}

it('resolves a public asset to the URL the disk itself hands out', function (): void {
    withDiskUrl();

    $asset = publicAsset();

    expect($asset->url())->toBe('https://cdn.test/files/'.$asset->object_key);
});

it('never sends a public asset through the Delivery route', function (): void {
    withDiskUrl();

    expect(publicAsset()->url())
        ->not->toContain('/admin/media/')
        ->not->toContain('signature=');
});

it('resolves a private asset to a signed Delivery URL', function (): void {
    withDiskUrl();

    $url = makeAsset()->url();

    expect($url)->toContain('/admin/media/')
        ->and($url)->toContain('signature=');
});

it('refuses to deliver a public asset, whose content is already addressable', function (): void {
    $asset = publicAsset();

    Storage::disk($asset->disk)->put($asset->object_key, 'the bytes');

    $this->get(DeliveryRoute::signedUrl($asset))->assertNotFound();
});

it('never changes an asset placement by attaching it', function (): void {
    $asset = publicAsset();
    $before = $asset->only(['disk', 'object_key', 'visibility']);

    app(AttachmentReconciler::class)->reconcile(article(), 'cover', [$asset->id]);

    expect($asset->fresh()->only(['disk', 'object_key', 'visibility']))->toBe($before);
});

it('never promotes a private asset to public by attaching it', function (): void {
    $asset = makeAsset();

    app(AttachmentReconciler::class)->reconcile(article(), 'cover', [$asset->id]);

    expect($asset->fresh()->visibility->isPublic())->toBeFalse();
});
