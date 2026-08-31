<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tenancy;

use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * Giving an unowned asset an owner.
 *
 * Claiming is the supported way out of the state a site inherits the day it
 * configures a resolver: a library full of assets that belong to no one and
 * are therefore visible to no one. It is one way and allowed once, because an
 * unowned asset gaining an owner is a different act from an asset changing
 * owner, and the second one never happens. The model refuses a reassignment
 * outright, so this reports rather than enforces.
 */
final readonly class Claim
{
    /**
     * Claim one asset, answering whether it was claimable at all. An asset
     * that already has a tenant is left exactly as it was.
     */
    public static function assign(MediaAsset $asset, string $tenant): bool
    {
        if ($asset->tenant_id !== null) {
            return false;
        }

        $asset->tenant_id = $tenant;
        $asset->save();

        return true;
    }
}
