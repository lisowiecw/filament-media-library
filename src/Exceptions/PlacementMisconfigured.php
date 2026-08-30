<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use RuntimeException;

/**
 * A placement whose disk cannot deliver what its visibility promises. This is
 * a configuration error rather than an upload failure: it is raised when the
 * placement resolves, so a misconfigured field fails on the first render
 * instead of writing an asset nobody can fetch, or anybody can.
 *
 * The check reads what the application declared in `filesystems.disks`. It
 * never asks the provider, because the providers this guards (R2 among them)
 * answer no ACL call and would agree with any declaration put to them.
 */
class PlacementMisconfigured extends RuntimeException
{
    public static function publicDiskHasNoUrl(?string $field, string $disk): self
    {
        return new self(sprintf(
            '%s declares public visibility on disk "%s", which exposes no public URL: '
            .'the disk is configured with no "url" key, so nothing the package hands a browser '
            .'is fetchable. Give the disk a public URL, name a disk that has one, or set '
            .'media-library.enforce_disk_visibility to false if this disk is served through '
            .'the application\'s own origin.',
            self::subject($field),
            $disk,
        ));
    }

    public static function privateOnPublicDisk(?string $field, string $disk): self
    {
        return new self(sprintf(
            '%s declares private visibility on disk "%s", which the application has declared '
            .'public, as media-library.public_disk or on the disk itself: the asset would be '
            .'authorized on the way in and still '
            .'fetchable by anyone who guesses its key. Name the private disk of the pair, or set '
            .'media-library.enforce_disk_visibility to false if this disk is not public after all.',
            self::subject($field),
            $disk,
        ));
    }

    private static function subject(?string $field): string
    {
        return $field === null ? 'A media placement' : sprintf('The media field "%s"', $field);
    }
}
