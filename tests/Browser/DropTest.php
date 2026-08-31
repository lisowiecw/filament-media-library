<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Workbench\App\Models\Article;

it('commits a drop on the inline trigger at once, without the modal', function (): void {
    $this->signIn();

    $article = $this->article('Dropping');

    $page = visit("/admin/articles/{$article->id}/edit");

    $this->drop($page, '[data-field="cover_image"] .fi-ml-picker-trigger', 'on-the-trigger.jpg')
        ->waitForText('on-the-trigger')
        // No modal was opened, so nothing was confirmed: the drop itself is
        // what attached the asset.
        ->assertScript("document.querySelectorAll('.fi-modal-window').length", 0);

    expect(MediaAsset::query()->sole()->display_name)->toBe('on-the-trigger');

    $page->click('Save changes')->waitForText('Saved');

    expect(Article::query()->sole()->media('cover_image')->pluck('id')->all())
        ->toBe([MediaAsset::query()->sole()->id]);
});

it('commits a drop on the Library tab body at once, without the modal\'s confirm', function (): void {
    $this->signIn();

    $article = $this->article('Dropping');

    $page = visit("/admin/articles/{$article->id}/edit")
        ->click('[data-field="cover_image"] .fi-ml-picker-trigger button')
        ->waitForText('Library');

    $this->drop($page, '.fi-ml-library[data-droppable]', 'on-the-grid.jpg')
        ->waitForText('on-the-grid');

    // Read before the modal is confirmed: the drop is what attached it.
    expect(MediaAsset::query()->sole()->display_name)->toBe('on-the-grid');

    $page->click('Attach')
        ->click('Save changes')
        ->waitForText('Saved');

    expect(Article::query()->sole()->media('cover_image')->pluck('id')->all())
        ->toBe([MediaAsset::query()->sole()->id]);
});

it('stages a drop on the Upload tab, which commits on the confirm', function (): void {
    $this->signIn();

    $article = $this->article('Dropping');

    $page = visit("/admin/articles/{$article->id}/edit")
        ->click('[data-field="cover_image"] .fi-ml-picker-trigger button')
        ->waitForText('Upload')
        ->click('Upload');

    $this->drop($page, '.filepond--root', 'on-the-upload-tab.jpg')
        ->assertPresent($this->staged());

    // The Upload tab holds what it is given: unlike the other two surfaces,
    // nothing is ingested until the modal is confirmed.
    expect(MediaAsset::count())->toBe(0);

    $page->click('Attach')->waitForText('on-the-upload-tab');

    expect(MediaAsset::query()->sole()->display_name)->toBe('on-the-upload-tab');
});
