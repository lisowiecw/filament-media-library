<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * A stand-in for the policy a host application writes: it answers whatever the
 * test told it to and counts how often it was asked, so a test can see both
 * the re-check on every request and the per-request cache.
 */
class HostPolicy
{
    public static bool $allows = true;

    public static int $evaluations = 0;

    public function view(?Authenticatable $user, MediaAsset $asset): bool
    {
        static::$evaluations++;

        return static::$allows;
    }
}
