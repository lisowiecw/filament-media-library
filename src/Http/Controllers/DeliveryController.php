<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Delivery\Disposition;
use Lisowiecw\MediaLibrary\Delivery\DownloadFilename;
use Lisowiecw\MediaLibrary\Derivatives\Derivatives;
use Lisowiecw\MediaLibrary\Enums\DerivativeVariant;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use Lisowiecw\MediaLibrary\Models\MediaDerivative;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one Media Asset's content, re-checking View on every single hit.
 *
 * The signature on the URL bounds how long a copied link survives; it is not
 * the authorization. That is why the policy is consulted here rather than at
 * the moment the URL was signed: a permission withdrawn a minute ago has to
 * take effect on the next fetch, not when the signature happens to lapse.
 */
class DeliveryController
{
    /**
     * Forbids the browser from fetching anything the served file references,
     * and from doing anything with the origin it is served from. It rides on
     * every response, redirects included, since object storage has no slot for
     * a response header of its own.
     */
    private const string CONTENT_POLICY = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    public function __construct(private readonly MediaAuthorization $authorization) {}

    public function __invoke(Request $request, string $asset): Response
    {
        $asset = MediaAsset::where('ulid', $asset)->firstOrFail();

        // A cross-tenant request is answered as though the row were not there
        // at all. A refusal would confirm the id, and an id that can be probed
        // is a library another tenant can enumerate.
        abort_if($this->authorization->excludedByTenant($asset), 404);

        $variant = $request->string('variant')->toString();

        if ($variant !== '') {
            return $this->derivative($asset, DerivativeVariant::tryFrom($variant));
        }

        $download = $request->boolean('download');

        // A public asset is already addressable at the disk's own URL, which
        // is what `MediaAsset::url()` hands out for one. Rendering it here
        // would spend a signed, authorized request on bytes anyone can fetch
        // and throw the caching away, so the route has no answer for it: not
        // a refusal, since a public asset needs no View at all, but nothing
        // to serve. Saving is the exception, because a link cannot tell the
        // browser to save a foreign origin's response and public placement is
        // a foreign origin by deployment.
        abort_if($asset->visibility->isPublic() && ! $download, 404);

        abort_unless($this->authorization->allowsView($asset), 403);

        $disposition = Disposition::for($asset, $download);
        $disk = Storage::disk($asset->disk);
        $header = DownloadFilename::header($disposition, $asset);

        // Rendering in place means the content policy has to reach the
        // browser, and a redirect leaves it behind: what renders streams,
        // whatever the disk could have offered. A public asset streams too,
        // since the only reason it is here is the disposition, and handing
        // that to a disk's response overrides is the one thing a redirect
        // cannot promise.
        if ($disposition === Disposition::Inline || $asset->visibility->isPublic() || ! $disk->providesTemporaryUrls()) {
            $response = $disk->response(
                $asset->object_key,
                null,
                [
                    'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
                    'Content-Disposition' => $header,
                ],
            );

            return $this->guarded($response);
        }

        // The disposition is asked for on the way out too. A disk that honours
        // response overrides keeps the earned answer; one that ignores them
        // serves the object's Stored headers, which were written to say the
        // same thing at upload.
        return $this->guarded(redirect()->away($disk->temporaryUrl(
            $asset->object_key,
            now()->addSeconds(DeliveryRoute::ttl()),
            [
                'ResponseContentType' => $asset->mime_type ?? 'application/octet-stream',
                'ResponseContentDisposition' => $header,
            ],
        )));
    }

    /**
     * Serves one variant of the asset already loaded, on the same check the
     * original just passed rather than a second lookup of its own.
     *
     * A derivative always streams. A presigned URL would hand out bytes the
     * route could no longer take back, and there is nothing to gain by it:
     * the rendering is small, and the content policy has to survive.
     */
    private function derivative(MediaAsset $asset, ?DerivativeVariant $variant): Response
    {
        // A public parent's rendering is already addressable at the disk's own
        // URL, which is what the row hands out for one, so the route has no
        // answer for it, exactly as it has none for a public original.
        abort_if($variant === null || $asset->visibility->isPublic(), 404);

        abort_unless($this->authorization->allowsView($asset), 403);

        $derivative = Derivatives::ready($asset, $variant);

        // A row that is pending or failed has no bytes behind it, so it is a
        // variant that does not exist rather than one that is refused.
        abort_if($derivative === null, 404);

        $response = Storage::disk($derivative->disk)->response(
            $derivative->object_key,
            MediaDerivative::filenameFor($asset, $variant),
            [
                'Content-Type' => MediaDerivative::MIME_TYPE,
                // A derivative's bytes never change under its key, so the
                // response is immutable; it is private because the parent is.
                // The lifetime runs to the end of the URL's own quantization
                // bucket, so a cached response never outlives the signature
                // that earned it.
                'Cache-Control' => 'private, immutable, max-age='.DeliveryRoute::bucketRemaining(),
            ],
            Disposition::Inline->value,
        );

        return $this->guarded($response);
    }

    private function guarded(Response $response): Response
    {
        $response->headers->set('Content-Security-Policy', self::CONTENT_POLICY);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
