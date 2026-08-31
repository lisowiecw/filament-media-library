<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Workbench\App\Models\Article;

it('uploads through the Upload tab and attaches what it made', function (): void {
    $this->signIn();

    $article = $this->article('Uploading');
    $path = $this->file('through-the-tab.jpg');

    $page = visit("/admin/articles/{$article->id}/edit")
        ->click('[data-field="cover_image"] .fi-ml-picker-trigger button')
        ->waitForText('Upload')
        ->click('Upload')
        ->attach('input[type="file"]', $path);

    $this->staged($page)
        ->click('Attach')
        ->waitForText('through-the-tab')
        ->click('Save changes')
        ->waitForText('Saved');

    $asset = MediaAsset::query()->sole();

    expect($asset->display_name)->toBe('through-the-tab')
        ->and(Article::query()->sole()->media('cover_image')->pluck('id')->all())->toBe([$asset->id]);
});
