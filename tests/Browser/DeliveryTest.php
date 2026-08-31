<?php

declare(strict_types=1);

use Filament\Facades\Filament;

/**
 * Two addresses, and the difference between them is the whole of the delivery
 * story: a private asset is fetched from the checked route and arrives wearing
 * the content policy, and a public one is fetched from the disk's own URL and
 * never reaches the route at all.
 *
 * The fetches are made by the page, from the page's own origin, because a
 * header that only holds for a PHP test client is not the header a browser
 * receives.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('serves a private asset through the Delivery route, with the content policy on it', function (): void {
    $this->signIn();

    $asset = $this->ingest('private-one.jpg');
    $url = $asset->url();

    expect($url)->toContain('/admin/media/'.$asset->ulid);

    $response = visit('/admin')->script(<<<JS
        (async () => {
            const response = await fetch({$this->js($url)}, { credentials: 'include' })

            return {
                status: response.status,
                policy: response.headers.get('content-security-policy'),
                nosniff: response.headers.get('x-content-type-options'),
                type: response.headers.get('content-type'),
            }
        })()
    JS);

    expect($response['status'])->toBe(200)
        ->and($response['type'])->toContain('image/jpeg')
        ->and($response['nosniff'])->toBe('nosniff')
        ->and($response['policy'])->toContain("default-src 'none'")
        ->and($response['policy'])->toContain('sandbox');
});

it('resolves a public asset to the disk URL, which the Delivery route never answers', function (): void {
    $this->signIn();

    $asset = $this->ingest('public-one.jpg', visibility: 'public');
    $url = $asset->url();

    expect($url)->toContain('/storage/')
        ->and($url)->not->toContain('/admin/media/');

    $response = visit('/admin')->script(<<<JS
        (async () => {
            const response = await fetch({$this->js($url)}, { credentials: 'include' })

            return {
                status: response.status,
                policy: response.headers.get('content-security-policy'),
            }
        })()
    JS);

    // Served by the disk, so no policy header rides along: the header is the
    // route's, and the route is not what answered.
    expect($response['status'])->toBe(200)
        ->and($response['policy'])->toBeNull();

    // And the route refuses the public asset outright rather than duplicating
    // the disk's answer behind a signature.
    $refusal = visit('/admin')->script(<<<JS
        (async () => {
            const response = await fetch({$this->js($this->deliveryUrl($asset))}, { credentials: 'include' })

            return response.status
        })()
    JS);

    expect($refusal)->toBe(404);
});
