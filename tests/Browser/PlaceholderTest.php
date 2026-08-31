<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Enums\DerivativeStatus;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;

/**
 * What a card shows while a rendering is still in flight. The hash is painted
 * as a coarse gradient and carried on the tile as `data-blurhash`, so a card
 * that has its thumbnail carries neither, and a card that does not carries
 * both.
 */
it('paints a card with no thumbnail from the BlurHash, and leaves a card that has one alone', function (): void {
    $this->signIn();

    $pending = $this->ingest('waiting.jpg');
    $ready = $this->ingest('painted.jpg');

    // The thumbnail for one of them is not done yet, which is the ordinary
    // state of a card in the seconds after an upload.
    MediaDerivative::query()
        ->where('media_asset_id', $pending->id)
        ->update(['status' => DerivativeStatus::Pending]);

    expect($pending->fresh()->blurhash)->not->toBeNull();

    $article = $this->article('Placeholders');

    visit("/admin/articles/{$article->id}/edit")
        ->click('[data-field="gallery"] .fi-ml-picker-trigger button')
        ->waitForText('Library')
        ->assertPresent(".fi-ml-card[data-asset-id=\"{$pending->id}\"] .fi-ml-card-glyph[data-blurhash]")
        ->assertAttributeContains(
            ".fi-ml-card[data-asset-id=\"{$pending->id}\"] .fi-ml-card-glyph",
            'style',
            'radial-gradient',
        )
        ->assertPresent(".fi-ml-card[data-asset-id=\"{$ready->id}\"] img.fi-ml-card-thumb")
        ->assertNotPresent(".fi-ml-card[data-asset-id=\"{$ready->id}\"] .fi-ml-card-glyph")
        ->assertNotPresent(".fi-ml-card[data-asset-id=\"{$ready->id}\"] [data-blurhash]");
});
