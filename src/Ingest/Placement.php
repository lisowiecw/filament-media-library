<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

use Lisowiecw\MediaLibrary\Enums\Visibility;
use Lisowiecw\MediaLibrary\Exceptions\PlacementMisconfigured;

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
     *
     * The resolved pair is then guarded: a disk that cannot deliver the
     * visibility declared on it is a configuration error here, at resolution,
     * rather than a surprise at the first upload.
     */
    public static function resolve(
        ?string $disk = null,
        ?string $directory = null,
        Visibility|string|null $visibility = null,
        ?string $field = null,
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

        $resolvedDisk = $disk ?? $pairedDisk ?? $configuredDisk ?? $defaultDisk;

        self::guard($resolvedDisk, $visibility, $field);

        return new self(
            disk: $resolvedDisk,
            directory: trim($directory ?? $configuredDirectory, '/'),
            visibility: $visibility,
        );
    }

    /**
     * The same invariant, for a disk and visibility that were resolved
     * somewhere other than a field: an import names both on the command line
     * and needs the identical answer, so the rule stays owned here rather than
     * being restated per caller.
     */
    public static function assertDeliverable(string $disk, Visibility $visibility, ?string $field = null): void
    {
        self::guard($disk, $visibility, $field);
    }

    /**
     * The disk-to-visibility invariant, checked against the declaration alone.
     *
     * A public asset is addressed by the disk's own URL, so a disk with no URL
     * to give hands out one nobody can fetch. A private asset is authorized on
     * every delivery, which says nothing about a bucket the application has
     * already declared public, either by naming it as the public half of the
     * pair or by declaring the disk itself public. A disk the application has
     * not configured is left alone: naming one is its own failure, elsewhere.
     */
    private static function guard(string $disk, Visibility $visibility, ?string $field): void
    {
        if (! (bool) config('media-library.enforce_disk_visibility', true)) {
            return;
        }

        /** @var array<string, mixed>|null $configuration */
        $configuration = config('filesystems.disks.'.$disk);

        if ($configuration === null) {
            return;
        }

        /** @var string|null $url */
        $url = $configuration['url'] ?? null;

        if ($visibility->isPublic() && ($url === null || trim($url) === '')) {
            throw PlacementMisconfigured::publicDiskHasNoUrl($field, $disk);
        }

        if (! $visibility->isPublic() && self::isDeclaredPublic($disk, $configuration)) {
            throw PlacementMisconfigured::privateOnPublicDisk($field, $disk);
        }
    }

    /**
     * The two ways an application says a disk is public: naming it as the
     * public half of the pair, and declaring the disk itself public, which is
     * what Laravel's own stock `public` disk does. Either is enough, since a
     * second disk pointed at the same public bucket is still that bucket.
     *
     * @param  array<string, mixed>  $configuration
     */
    private static function isDeclaredPublic(string $disk, array $configuration): bool
    {
        return $disk === config('media-library.public_disk')
            || ($configuration['visibility'] ?? null) === 'public';
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
