<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Policies\MediaAssetPolicy;
use Lisowiecw\MediaLibrary\Tenancy\Tenancy;

/**
 * The one place the package asks whether something is allowed, and the entry
 * point a host application should ask too rather than reaching for the Gate
 * facade.
 *
 * It exists for two reasons. The public-asset shortcut is stated once,
 * so no caller can forget that content already addressable without a session
 * needs no check. And View answers are cached for the life of the request, so
 * a grid painting forty-eight cards costs one evaluation per asset rather than
 * one per render of it.
 *
 * The cache is per request by construction: the class is bound scoped, so a
 * queue worker handling a second job gets a second instance and a policy
 * revoked between two requests is honoured on the next one.
 */
class MediaAuthorization
{
    /**
     * The gate names a host application writes into its own authorization,
     * covering the two actions that precede an asset existing. Both are part
     * of the promised surface, so they are named here rather than spelled as
     * strings at each call site.
     */
    public const string UPLOAD_MEDIA = 'uploadMedia';

    public const string ATTACH_MEDIA = 'attachMedia';

    /**
     * The ability that reaches past the tenant boundary, on the policy rather
     * than as a gate because it is a question about the model. It fails closed
     * like everything else, so opting a panel into tenancy never produces a
     * cross-tenant reader by accident.
     */
    public const string VIEW_ALL_TENANTS = 'viewAllTenants';

    /** @var array<string, bool> */
    private array $viewed = [];

    private ?Request $answeredFor = null;

    /**
     * Register the fail-closed defaults. Unconditionally: an application
     * provider boots after a package one, so a host registering its own policy
     * or gates simply replaces these, and guarding on what is already there
     * would only make the outcome depend on boot order.
     */
    public static function registerDefaults(): void
    {
        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);

        foreach ([self::UPLOAD_MEDIA, self::ATTACH_MEDIA] as $gate) {
            Gate::define($gate, fn (): bool => false);
        }
    }

    /**
     * Whether this request may be handed the asset's actual bytes. The public
     * shortcut lives here rather than in the policy, since the policy is the
     * one piece a host application replaces wholesale: asking the Gate about a
     * public asset directly will answer false.
     */
    public function allowsView(MediaAsset $asset): bool
    {
        // Before the public shortcut, not after it. Public says the bytes are
        // already addressable to anyone who holds the disk's URL; it does not
        // say this panel should hand another tenant's asset over.
        if ($this->excludedByTenant($asset)) {
            return false;
        }

        if ($asset->visibility->isPublic()) {
            return true;
        }

        $this->forgetStaleAnswers();

        $key = $asset->ulid.'|'.(Auth::id() ?? 'guest');

        return $this->viewed[$key] ??= Gate::allows('view', $asset);
    }

    /**
     * Whether the tenant boundary puts this asset out of reach. It is the
     * comparison plus the one ability that crosses it, stated here so the
     * scope, the policy and the Delivery route cannot answer it differently.
     */
    public function excludedByTenant(MediaAsset $asset): bool
    {
        return Tenancy::excludes($asset) && ! $this->allowsAllTenants();
    }

    /**
     * Whether this request may read across tenants at all, which is what the
     * management page's unscoped listing is unlocked by.
     */
    public function allowsAllTenants(): bool
    {
        $this->forgetStaleAnswers();

        return $this->viewed['all-tenants|'.(Auth::id() ?? 'guest')] ??= Gate::allows(
            self::VIEW_ALL_TENANTS,
            MediaAsset::class,
        );
    }

    /**
     * The cache is keyed to the request it was filled during, so a permission
     * withdrawn between two requests is honoured on the next one even where
     * the container instance outlives the request, as it does under a test
     * runner and a persistent worker.
     */
    private function forgetStaleAnswers(): void
    {
        $request = request();

        if ($this->answeredFor !== $request) {
            $this->viewed = [];
            $this->answeredFor = $request;
        }
    }

    /**
     * Whether this request may upload into the given field context. The host
     * is an instance where the form has one and a class-string on a create
     * form, since the record does not exist yet.
     */
    public function allowsUpload(Model|string|null $host, ?string $field): bool
    {
        return Gate::allows(self::UPLOAD_MEDIA, [$host, $field]);
    }

    /**
     * Whether this request may attach an existing asset into the given field
     * context. Attaching is a separate answer from uploading: a role may well
     * reuse the library without being allowed to add to it.
     */
    public function allowsAttach(Model|string|null $host, ?string $field): bool
    {
        return Gate::allows(self::ATTACH_MEDIA, [$host, $field]);
    }
}
