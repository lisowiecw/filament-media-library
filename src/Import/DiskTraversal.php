<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Generator;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * Walks a prefix on a disk, yielding object keys one at a time.
 *
 * This is the degraded half of discovery, and it is deliberately the only
 * thing it does: a key on a bucket names no host row, no field context and no
 * uploader, so a traversal run adopts objects and stops there.
 *
 * The walk goes through `listContents()` rather than `allFiles()`, because the
 * second builds the whole listing as an array before the caller sees a single
 * key, and the buckets this exists for are the ones where that array is the
 * problem. Yielding keeps one page in memory however large the prefix is.
 */
final readonly class DiskTraversal
{
    /**
     * @return Generator<int, string>
     */
    public static function keys(FilesystemAdapter $disk, string $prefix): Generator
    {
        foreach ($disk->listContents($prefix, true) as $attributes) {
            if (! $attributes->isFile()) {
                continue;
            }

            yield $attributes->path();
        }
    }

    /**
     * A prefix as the disk will match it: no leading slash, and no trailing one
     * either, since a listing of `legacy/` and one of `legacy` are the same
     * request written two ways.
     */
    public static function normalise(string $prefix): string
    {
        return trim(trim($prefix), '/');
    }
}
