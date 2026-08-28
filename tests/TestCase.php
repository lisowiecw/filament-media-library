<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\MediaLibraryServiceProvider;
use Lisowiecw\MediaLibrary\Tests\Fixtures\TestPanelProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * The disk every test writes to. Faked in setUp so nothing in the suite
     * ever reaches a real filesystem, and so the package is exercised through
     * the Storage facade rather than against a named driver.
     */
    protected string $disk = 'media';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->disk);

        $this->createFixtureTables();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            // Registered after Filament: Filament's support provider binds a
            // DataStore override, and Livewire's provider has to be the one
            // that puts the shared instance in the container afterwards.
            LivewireServiceProvider::class,
            MediaLibraryServiceProvider::class,
            TestPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('filesystems.default', $this->disk);
        $app['config']->set('filesystems.disks.'.$this->disk, [
            'driver' => 'local',
            'root' => storage_path('app/'.$this->disk),
        ]);

        $app['view']->addNamespace('media-library-tests', __DIR__.'/Fixtures/views');
    }

    /**
     * The host model tickets attach media to, and the authenticated user the
     * policy and the gates are asked about.
     */
    protected function createFixtureTables(): void
    {
        if (Schema::hasTable('articles')) {
            return;
        }

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
