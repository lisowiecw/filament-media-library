<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;

it('is a Filament plugin with a stable id', function (): void {
    $plugin = MediaLibraryPlugin::make();

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->getId())->toBe('media-library');
});
