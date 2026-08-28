<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Policies\MediaAssetPolicy;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

it('registers the packaged policy for the asset model', function (): void {
    expect(Gate::getPolicyFor(MediaAsset::class))->toBeInstanceOf(MediaAssetPolicy::class);
});

it('denies every ability until the application writes a policy', function (string $ability): void {
    $this->actingAs(user());

    expect(Gate::allows($ability, makeAsset(['visibility' => 'private'])))->toBeFalse();
})->with(['viewAny', 'view', 'update', 'delete', 'forceDelete', 'detach']);

it('denies the gates that precede an asset existing', function (string $gate): void {
    $this->actingAs(user());

    expect(Gate::allows($gate, [article(), 'cover']))->toBeFalse();
})->with([MediaAuthorization::UPLOAD_MEDIA, MediaAuthorization::ATTACH_MEDIA]);

it('needs no check to read a public asset', function (): void {
    $asset = makeAsset(['visibility' => 'public']);

    expect(app(MediaAuthorization::class)->allowsView($asset))->toBeTrue();
});

it('evaluates a private asset once per request', function (): void {
    HostPolicy::$evaluations = 0;
    Gate::policy(MediaAsset::class, HostPolicy::class);

    $asset = makeAsset(['visibility' => 'private']);
    $authorization = app(MediaAuthorization::class);

    expect($authorization->allowsView($asset))->toBeTrue()
        ->and($authorization->allowsView($asset->fresh()))->toBeTrue()
        ->and(HostPolicy::$evaluations)->toBe(1);
});
