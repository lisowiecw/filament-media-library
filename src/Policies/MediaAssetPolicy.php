<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The packaged default policy, which denies everything.
 *
 * It exists so that forgetting to write a policy denies rather than allows: a
 * package that shipped no policy at all would leave the model unguarded and
 * every ability open to whatever the host application's fallback is. A host
 * application replaces it wholesale with `Gate::policy()`, which is why every
 * method here is a plain false rather than a hook to extend.
 *
 * Reads of a public asset never arrive here. Public content is already
 * publicly addressable, so the panel's own auth is the only gate, and the
 * question is answered before the policy is consulted.
 *
 * Rename is `update` and download is `view`: neither earns an ability of its
 * own, because an application that can say who may edit an asset's name has
 * already said who may rename it.
 */
class MediaAssetPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return false;
    }

    public function view(?Authenticatable $user, MediaAsset $asset): bool
    {
        return false;
    }

    public function update(?Authenticatable $user, MediaAsset $asset): bool
    {
        return false;
    }

    public function delete(?Authenticatable $user, MediaAsset $asset): bool
    {
        return false;
    }

    public function forceDelete(?Authenticatable $user, MediaAsset $asset): bool
    {
        return false;
    }

    public function detach(?Authenticatable $user, MediaAsset $asset): bool
    {
        return false;
    }
}
