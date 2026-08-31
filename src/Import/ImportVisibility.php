<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Enums\Visibility;
use Throwable;

/**
 * What visibility an adopted object is recorded with.
 *
 * The order is: what the operator asserted, what a local disk's file mode
 * actually says, what the disk is configured as, then private. The stored
 * value is delivery intent rather than a readback of provider state, which is
 * why configuration outranks the provider everywhere it is available.
 *
 * The read is never attempted on an `s3` driver: Laravel's `getVisibility()`
 * catches nothing, and the underlying `GetObjectAcl` is unimplemented on R2,
 * so asking would end the run over a value the configuration already knows.
 */
final readonly class ImportVisibility
{
    public static function resolve(string $disk, string $key, ?Visibility $declared): Visibility
    {
        if ($declared !== null) {
            return $declared;
        }

        /** @var array<string, mixed> $configuration */
        $configuration = config('filesystems.disks.'.$disk, []);

        if (($configuration['driver'] ?? null) === 'local') {
            $read = self::read($disk, $key);

            if ($read !== null) {
                return $read;
            }
        }

        /** @var string|null $configured */
        $configured = $configuration['visibility'] ?? null;

        // `tryFrom`, because a disk configured with something visibility is
        // not is a reason to fall to private, never to end the run.
        return $configured === null ? Visibility::Private : Visibility::tryFrom($configured) ?? Visibility::Private;
    }

    /**
     * A local disk derives visibility from file mode, so the answer is real.
     * An unreadable one is not an error here: the fallbacks below it are.
     */
    private static function read(string $disk, string $key): ?Visibility
    {
        try {
            return Visibility::from(Storage::disk($disk)->getVisibility($key));
        } catch (Throwable) {
            return null;
        }
    }
}
