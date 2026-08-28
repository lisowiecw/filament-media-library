<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Lisowiecw\MediaLibrary\Authorization\MediaAuthorization;
use Lisowiecw\MediaLibrary\Delivery\DeliveryRoute;
use Lisowiecw\MediaLibrary\Delivery\Disposition;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
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

        abort_unless($this->authorization->allowsView($asset), 403);

        $disposition = Disposition::for($asset, $request->boolean('download'));
        $disk = Storage::disk($asset->disk);
        $filename = $asset->original_client_filename ?? $asset->display_name;

        // Rendering in place means the content policy has to reach the
        // browser, and a redirect leaves it behind: what renders streams,
        // whatever the disk could have offered.
        if ($disposition === Disposition::Inline || ! $disk->providesTemporaryUrls()) {
            $response = $disk->response(
                $asset->object_key,
                $filename,
                ['Content-Type' => $asset->mime_type ?? 'application/octet-stream'],
                $disposition->value,
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
                'ResponseContentDisposition' => $disposition->value.'; filename="'.$filename.'"',
            ],
        )));
    }

    private function guarded(Response $response): Response
    {
        $response->headers->set('Content-Security-Policy', self::CONTENT_POLICY);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
