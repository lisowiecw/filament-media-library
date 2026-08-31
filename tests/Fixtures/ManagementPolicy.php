<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The host application's policy as the management page sees it: every ability
 * separately switchable, so a test can allow the page and refuse one action on
 * it without writing a policy per case.
 *
 * Everything starts allowed, unlike the packaged default, because most tests
 * here are about what the page does rather than about who may reach it. The
 * refusals are the interesting cases, and each one flips a single static.
 */
class ManagementPolicy
{
    /** @var array<string, bool> */
    public static array $allows = [];

    /**
     * Per-record refusals, as ability to asset id. This is what lets a bulk
     * action be tested for the thing it is supposed to do: skip the one row
     * this person may not touch, and still act on the rest.
     *
     * @var array<string, int>
     */
    public static array $refuseFor = [];

    public static function reset(): void
    {
        static::$allows = [];
        static::$refuseFor = [];
    }

    public function viewAny(?Authenticatable $user): bool
    {
        return static::allowed('viewAny');
    }

    public function view(?Authenticatable $user, MediaAsset $asset): bool
    {
        return static::allowed('view', $asset);
    }

    public function update(?Authenticatable $user, MediaAsset $asset): bool
    {
        return static::allowed('update', $asset);
    }

    public function delete(?Authenticatable $user, MediaAsset $asset): bool
    {
        return static::allowed('delete', $asset);
    }

    public function forceDelete(?Authenticatable $user, MediaAsset $asset): bool
    {
        return static::allowed('forceDelete', $asset);
    }

    public function restore(?Authenticatable $user, MediaAsset $asset): bool
    {
        return static::allowed('restore', $asset);
    }

    public function deleteAny(?Authenticatable $user): bool
    {
        return static::allowed('deleteAny');
    }

    public function restoreAny(?Authenticatable $user): bool
    {
        return static::allowed('restoreAny');
    }

    /**
     * Unlike every other ability here, this one starts refused, because that
     * is what it is: the fail-closed answer a host has to say yes to before a
     * person sees anything outside their own tenant.
     */
    public function viewAllTenants(?Authenticatable $user): bool
    {
        return static::$allows['viewAllTenants'] ?? false;
    }

    public function detach(?Authenticatable $user, MediaAsset $asset): bool
    {
        return static::allowed('detach', $asset);
    }

    private static function allowed(string $ability, ?MediaAsset $asset = null): bool
    {
        if ($asset !== null && (static::$refuseFor[$ability] ?? null) === $asset->id) {
            return false;
        }

        return static::$allows[$ability] ?? true;
    }
}
