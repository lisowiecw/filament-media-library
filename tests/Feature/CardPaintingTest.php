<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Derivatives\CardPainting;
use Lisowiecw\MediaLibrary\Enums\BlurHashStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Jobs\ComputeBlurHash;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;
use Lisowiecw\MediaLibrary\Tests\Fixtures\HostPolicy;

/**
 * A public asset too large to be its own thumbnail, which is the one a card
 * has real work waiting on.
 */
function paintableAsset(array $overrides = []): MediaAsset
{
    return makeAsset(array_merge(['visibility' => 'public', 'size' => 900_000], $overrides));
}

function paintedThumb(MediaAsset $asset): MediaDerivative
{
    return MediaDerivative::create([
        'media_asset_id' => $asset->id,
        'variant' => DerivativeVariant::Thumb,
        'status' => DerivativeStatus::Ready,
        'disk' => $asset->disk,
        'object_key' => 'media/derivatives/'.$asset->id.'-thumb.webp',
    ]);
}

it('paints one asset in a single pass, with the hash it emits beside it', function (): void {
    $asset = paintableAsset(['blurhash' => 'L6PZfSi_.AyE_3t7t7R**0o#DgR4', 'blurhash_status' => BlurHashStatus::Ready]);

    $card = (new CardPainting)->paint($asset);

    expect($card->blurhash)->toBe('L6PZfSi_.AyE_3t7t7R**0o#DgR4')
        ->and($card->paint)->toContain('background-color:')
        ->and($card->resolved)->toBeFalse();
});

it('paints a ready thumbnail through the pipeline where no rule was named', function (): void {
    $asset = paintableAsset(['blurhash' => 'L6PZfSi_.AyE_3t7t7R**0o#DgR4', 'blurhash_status' => BlurHashStatus::Ready]);
    $derivative = paintedThumb($asset);

    $card = (new CardPainting)->paint($asset->fresh());

    expect($card->thumbnail)->toContain($derivative->object_key)
        ->and($card->resolved)->toBeTrue();
});

it('paints through the field rule where one was named', function (): void {
    $painting = new CardPainting(fn (MediaAsset $asset): ?string => 'stamped/'.$asset->id);

    expect($painting->paint(paintableAsset())->thumbnail)->toContain('stamped/');
});

it('never hands the rule an asset the viewer may not be delivered', function (): void {
    Gate::policy(MediaAsset::class, HostPolicy::class);
    HostPolicy::$allows = false;

    $asset = makeAsset(['visibility' => 'private', 'size' => 900_000]);

    $painting = new CardPainting(function (MediaAsset $asset): ?string {
        throw new RuntimeException('the rule was asked');
    });

    $card = $painting->paint($asset);

    expect($card->thumbnail)->toBeNull()
        ->and($card->blurhash)->toBeNull()
        ->and($card->resolved)->toBeTrue();
});

it('paints nothing and waits on nothing for an asset with no preview to give', function (): void {
    Bus::fake();

    $card = (new CardPainting)->paint(makeAsset(['mime_type' => 'application/pdf', 'extension' => 'pdf']));

    expect($card->thumbnail)->toBeNull()
        ->and($card->paint)->toBeNull()
        ->and($card->resolved)->toBeTrue();

    Bus::assertNothingDispatched();
});

it('is what asks for the hash of an asset that has none yet', function (): void {
    Bus::fake();

    (new CardPainting)->paint(paintableAsset());

    Bus::assertDispatchedTimes(ComputeBlurHash::class, 1);
});

it('waits while anything on the page is unresolved, and stops once everything is settled', function (): void {
    $waiting = paintableAsset();
    $settled = paintableAsset(['object_key' => 'media/two.jpg', 'blurhash_status' => BlurHashStatus::Failed]);
    paintedThumb($settled);

    $painting = new CardPainting;

    expect($painting->pending([$settled->fresh()]))->toBeFalse()
        ->and($painting->pending([$settled->fresh(), $waiting]))->toBeTrue();
});

it('waits on nothing for a field that paints its own thumbnails', function (): void {
    $painting = new CardPainting(fn (MediaAsset $asset): ?string => 'stamped');

    expect($painting->pending([paintableAsset()]))->toBeFalse()
        ->and($painting->paint(paintableAsset(['object_key' => 'media/two.jpg']))->resolved)->toBeTrue();
});

it('asks again on the configured interval', function (): void {
    config()->set('media-library.poll_interval', '9s');

    expect((new CardPainting)->interval())->toBe('9s');
});
