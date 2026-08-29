<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary;

use Illuminate\Support\Facades\Log;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Derivatives\LazyDispatch;
use Lisowiecw\MediaLibrary\Ingest\IngestRules;
use Lisowiecw\MediaLibrary\Ingest\UploadCeiling;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class MediaLibraryServiceProvider extends PackageServiceProvider
{
    public static string $name = 'media-library';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews(static::$name)
            ->hasTranslations()
            ->discoversMigrations()
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        // Scoped rather than singleton: the View cache it holds is only ever
        // correct for one request.
        $this->app->scoped(MediaAuthorization::class);

        // Scoped for the same reason: the backfill allowance it spends is a
        // per-render budget, and a singleton would hand one page's leftovers
        // to the next.
        $this->app->scoped(LazyDispatch::class);
    }

    /**
     * A configured limit above the PHP or Livewire ceiling cannot be reached:
     * the upload fails in the browser with nothing written anywhere. Warn at
     * boot rather than leave that to be debugged.
     */
    public function packageBooted(): void
    {
        MediaAuthorization::registerDefaults();

        /** @var int $maxUploadSize */
        $maxUploadSize = config('media-library.max_upload_size', IngestRules::DEFAULT_MAX_UPLOAD_SIZE);

        foreach (UploadCeiling::warnings($maxUploadSize) as $warning) {
            Log::warning($warning);
        }
    }
}
