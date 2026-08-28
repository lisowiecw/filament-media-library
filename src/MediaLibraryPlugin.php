<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MediaLibraryPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'media-library';
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
