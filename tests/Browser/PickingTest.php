<?php

declare(strict_types=1);

/**
 * Picking, as a person does it: open the modal, choose a card, attach it, save
 * the host form, and come back to a page that still shows it. The last step is
 * the one that matters, because an attachment that only survives until the next
 * request is not an attachment.
 */
it('picks an asset from the library and keeps it across a reload', function (): void {
    $this->signIn();

    $asset = $this->ingest('gallery-one.jpg');
    $article = $this->article('Picking');

    $page = visit("/admin/articles/{$article->id}/edit")
        ->assertSeeIn('[data-field="gallery"]', 'Nothing attached yet.')
        ->click('[data-field="gallery"] .fi-ml-picker-trigger button')
        ->waitForText('Library')
        ->click(".fi-ml-library-grid .fi-ml-card[data-asset-id=\"{$asset->id}\"]")
        ->assertPresent(".fi-ml-card-selected[data-asset-id=\"{$asset->id}\"]")
        ->click('Attach')
        ->waitForText($asset->display_name)
        ->assertPresent("[data-field=\"gallery\"] .fi-ml-picker-item[data-asset-id=\"{$asset->id}\"]");

    $page->click('Save changes')
        ->waitForText('Saved');

    expect($article->fresh()->media('gallery')->pluck('id')->all())->toBe([$asset->id]);

    visit("/admin/articles/{$article->id}/edit")
        ->assertPresent("[data-field=\"gallery\"] .fi-ml-picker-item[data-asset-id=\"{$asset->id}\"]")
        ->assertSeeIn('[data-field="gallery"]', $asset->display_name);
});
