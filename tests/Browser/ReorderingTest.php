<?php

declare(strict_types=1);

/**
 * Reordering without a pointer. The drag is the obvious gesture and the arrows
 * are the one a keyboard has, so the arrows are what a test drives: Enter on
 * the control, and the order that survives the save is the order on screen.
 */
it('reorders an attached list from the keyboard and saves the new order', function (): void {
    $this->signIn();

    $first = $this->ingest('first.jpg');
    $second = $this->ingest('second.jpg');

    $article = $this->article('Reordering');
    $this->attach($article, 'gallery', $first, $second);

    $page = visit("/admin/articles/{$article->id}/edit")
        ->assertPresent('[data-field="gallery"] .fi-ml-picker-item')
        // Enter on the second item's up arrow, reached and fired the way a
        // keyboard reaches it rather than by a click.
        ->keys(
            "[data-field=\"gallery\"] li[data-asset-id=\"{$second->id}\"] .fi-ml-picker-item-up",
            'Enter',
        )
        ->waitForText($second->display_name);

    $page->assertScript(
        '[...document.querySelectorAll(\'[data-field="gallery"] li[data-asset-id]\')].map((item) => item.dataset.assetId).join(",")',
        "{$second->id},{$first->id}",
    );

    $page->click('Save changes')
        ->waitForText('Saved');

    expect($article->fresh()->media('gallery')->pluck('id')->all())->toBe([$second->id, $first->id]);
});
