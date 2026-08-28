<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Policies\MediaAssetPolicy;

/**
 * The one place the package asks whether something is allowed.
 *
 * Everything the plugin does goes through here rather than through the Gate
 * facade directly, for two reasons. The public-asset shortcut is stated once,
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

    /** @var array<string, bool> */
    private array $viewed = [];

    private ?Request $answeredFor = null;

    /**
     * Register the fail-closed defaults, unless the application has already
     * spoken. A host that registers its own policy or gates first keeps them;
     * one that registers later overwrites these, which is the ordinary
     * Laravel outcome of an application provider booting after a package one.
     */
    public static function registerDefaults(): void
    {
        if (Gate::getPolicyFor(MediaAsset::class) === null) {
            Gate::policy(MediaAsset::class, MediaAssetPolicy::class);
        }

        foreach ([self::UPLOAD_MEDIA, self::ATTACH_MEDIA] as $gate) {
            if (! Gate::has($gate)) {
                Gate::define($gate, fn (): bool => false);
            }
        }
    }

    /**
     * Whether this request may be handed the asset's actual bytes.
     */
    public function allowsView(MediaAsset $asset): bool
    {
        if ($asset->isPublic()) {
            return true;
        }

        $this->forgetStaleAnswers();

        $key = $asset->ulid.'|'.(Auth::id() ?? 'guest');

        return $this->viewed[$key] ??= Gate::allows('view', $asset);
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
