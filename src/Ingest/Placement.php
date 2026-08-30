<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

use Lisowiecw\MediaLibrary\Enums\Visibility;

/**
 * The disk, directory prefix and visibility a Media Picker applies to new
 * uploads. Placement is fixed on the asset at upload and never re-applied by
 * attaching, so a shared asset keeps its own placement wherever it is reused.
 */
final readonly class Placement
{
    public function __construct(
        public string $disk,
        public string $directory,
        public Visibility $visibility,
    ) {}

    /**
     * Field configuration wins over package configuration, which falls back to
     * the application's own default disk, a `media` prefix and private.
     *
     * A field that names no disk takes the one its resolved visibility is
     * paired with, which is how a two-bucket deployment states its pairing once
     * in config instead of at every call site.
     */
    public static function resolve(
        ?string $disk = null,
        ?string $directory = null,
        Visibility|string|null $visibility = null,
    ): self {
        /** @var string|null $configuredDisk */
        $configuredDisk = config('media-library.disk');

        /** @var string $defaultDisk */
        $defaultDisk = config('filesystems.default');

        /** @var string $configuredDirectory */
        $configuredDirectory = config('media-library.directory', 'media');

        /** @var string $configuredVisibility */
        $configuredVisibility = config('media-library.visibility', 'private');

        $visibility ??= $configuredVisibility;
        $visibility = $visibility instanceof Visibility ? $visibility : Visibility::from($visibility);

        /** @var string|null $pairedDisk */
        $pairedDisk = $visibility->isPublic()
            ? config('media-library.public_disk')
            : config('media-library.private_disk');

        return new self(
            disk: $disk ?? $pairedDisk ?? $configuredDisk ?? $defaultDisk,
            directory: trim($directory ?? $configuredDirectory, '/'),
            visibility: $visibility,
        );
    }

    /**
     * Public placement is the one the plugin is not in the request path for.
     */
    public function isPublic(): bool
    {
        return $this->visibility->isPublic();
    }

    /**
     * Prefix a server-generated key with the directory, if there is one.
     */
    public function key(string $name): string
    {
        return $this->directory === '' ? $name : $this->directory.'/'.$name;
    }
}
