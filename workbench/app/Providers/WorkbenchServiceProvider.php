<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Workbench\App\Policies\MediaAssetPolicy;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * The authorization a host application writes, and the whole of what the
     * workbench replaces. The package's own defaults deny everything, and an
     * application provider boots after a package one, so registering here is
     * enough to take them over.
     *
     * See `Workbench\App\Policies\MediaAssetPolicy` for why these say yes to
     * anyone signed in, and why nothing here belongs in a real application.
     */
    public function boot(): void
    {
        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);

        Gate::define(
            MediaAuthorization::UPLOAD_MEDIA,
            fn (?object $user, Model|string|null $host = null, ?string $field = null): bool => $user !== null,
        );

        Gate::define(
            MediaAuthorization::ATTACH_MEDIA,
            fn (?object $user, Model|string|null $host = null, ?string $field = null): bool => $user !== null,
        );
    }
}
