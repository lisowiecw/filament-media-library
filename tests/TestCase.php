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

use function Orchestra\Testbench\workbench_path;

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

        // Neither the cache nor the queue has a table in the test database, and
        // neither is what any of these tests is about.
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'discarded');
        $app['config']->set('queue.connections.discarded', ['driver' => 'null']);
        $app['config']->set('filesystems.default', $this->disk);
        $app['config']->set('filesystems.disks.'.$this->disk, [
            'driver' => 'local',
            'root' => storage_path('app/'.$this->disk),
            'url' => '/storage/'.$this->disk,
        ]);

        // The workbench's `.env` is copied into the Testbench skeleton by
        // `composer build`, so on a machine that has run it the package's own
        // environment variables are set. The suite states its own placement
        // rather than inheriting whichever disks the example happens to use.
        $app['config']->set('media-library.disk', null);
        $app['config']->set('media-library.public_disk', null);
        $app['config']->set('media-library.private_disk', null);
        $app['config']->set('media-library.directory', 'media');
        $app['config']->set('media-library.visibility', 'private');

        $app['view']->addNamespace('media-library-tests', __DIR__.'/Fixtures/views');
    }

    /**
     * The articles table, from the workbench's own migration rather than
     * restated here: the suite and the example attach media to one Article, so
     * its schema is stated in one place too.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(workbench_path('database/migrations'));
    }

    /**
     * The rest of the host schema, which is schema no application would have:
     * the authenticated user the policy and the gates are asked about, and the
     * legacy tables the importer reads.
     */
    protected function createFixtureTables(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // The legacy schema the importer reads: a column holding a path the
        // application stored years before the plugin existed, and one holding
        // a JSON array of them, since both shapes are out there.
        Schema::create('legacy_records', function (Blueprint $table): void {
            $table->id();
            $table->string('cover_path')->nullable();
            $table->text('gallery_paths')->nullable();
            $table->string('author_id')->nullable();
        });
    }
}
