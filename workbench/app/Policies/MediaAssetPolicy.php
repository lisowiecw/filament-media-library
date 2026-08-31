<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * This file is the piece a host application replaces, and the only reason the
 * workbench has one.
 *
 * The package ships a policy that denies everything, on purpose: forgetting to
 * write one has to deny rather than allow. That default would make the example
 * look broken, since an evaluator who logs in would meet an empty navigation
 * and a picker that offers nothing. So the workbench says yes to a signed-in
 * person and no to a guest, which is the shortest honest policy there is.
 *
 * Do not copy it into a real application. A real one asks something about the
 * user, and it is the place to say that only editors may delete.
 */
class MediaAssetPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function view(?Authenticatable $user, MediaAsset $asset): bool
    {
        return $user !== null;
    }

    public function update(?Authenticatable $user, MediaAsset $asset): bool
    {
        return $user !== null;
    }

    public function delete(?Authenticatable $user, MediaAsset $asset): bool
    {
        return $user !== null;
    }

    public function forceDelete(?Authenticatable $user, MediaAsset $asset): bool
    {
        return $user !== null;
    }

    public function restore(?Authenticatable $user, MediaAsset $asset): bool
    {
        return $user !== null;
    }

    public function detach(?Authenticatable $user, MediaAsset $asset): bool
    {
        return $user !== null;
    }

    public function deleteAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function restoreAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    /**
     * Refused, unlike everything above it. The panel is not tenanted, so there
     * is no boundary to reach past, and saying yes here would only hide that.
     */
    public function viewAllTenants(?Authenticatable $user): bool
    {
        return false;
    }
}
