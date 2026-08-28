<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;
use Lisowiecw\MediaLibrary\Tests\Fixtures\Article;

it('boots a panel with the plugin registered', function (): void {
    $panel = Filament::getPanel('admin');

    Filament::setCurrentPanel($panel);
    $panel->boot();

    expect($panel->hasPlugin('media-library'))->toBeTrue()
        ->and($panel->getPlugin('media-library'))->toBeInstanceOf(MediaLibraryPlugin::class);
});

it('publishes the config under the media-library key', function (): void {
    expect(config('media-library.visibility'))->toBe('private')
        ->and(config('media-library.max_upload_size'))->toBe(12 * 1024)
        ->and(config('media-library.blocked_types'))->toContain('php');
});

it('has a faked disk and a fixture host model', function (): void {
    Storage::disk($this->disk)->put('probe.txt', 'ok');

    expect(Storage::disk($this->disk)->get('probe.txt'))->toBe('ok');

    $article = Article::query()->create(['title' => 'Hello']);

    expect($article->exists)->toBeTrue();
});
