<?php

declare(strict_types=1);

use Filament\Contracts\Plugin;
use Filament\Panel;
use Lisowiecw\MediaLibrary\MediaLibraryPlugin;
use Lisowiecw\MediaLibrary\Tests\Fixtures\User;

it('is a Filament plugin with a stable id', function (): void {
    $plugin = MediaLibraryPlugin::make();

    expect($plugin)->toBeInstanceOf(Plugin::class)
        ->and($plugin->getId())->toBe('media-library');
});

it('is not tenanted at all where neither the panel nor the plugin says so', function (): void {
    $plugin = MediaLibraryPlugin::make();

    expect($plugin->isTenanted(new Panel))->toBeFalse()
        ->and($plugin->resolveTenant(new Panel))->toBeNull();
});

it('takes the resolver it was given over the panel', function (): void {
    $plugin = MediaLibraryPlugin::make()->tenantUsing(fn () => 'acme');

    expect($plugin->isTenanted(new Panel))->toBeTrue()
        ->and($plugin->resolveTenant(new Panel))->toBe('acme');
});

it('reads a key off a model the resolver answers with', function (): void {
    $tenant = new User(['name' => 'Acme']);
    $tenant->id = 7;

    $plugin = MediaLibraryPlugin::make()->tenantUsing(fn () => $tenant);

    expect($plugin->resolveTenant(new Panel))->toBe('7');
});

it('follows a panel that has tenancy of its own with no resolver configured', function (): void {
    $panel = (new Panel)->tenant(User::class);

    expect(MediaLibraryPlugin::make()->isTenanted($panel))->toBeTrue();
});
