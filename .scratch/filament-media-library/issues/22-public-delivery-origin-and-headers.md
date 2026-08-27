# Research Public Asset Delivery Origin and Headers

Status: resolved
Type: research
Blocked by:

## Question

Two fog patches that resolve together, both turning on the same fact: how much control the plugin has over a *public* object's delivery, where it is not in the request path.

Ticket 13 assumed public content is served from a foreign origin, which is why active content is refused on public placement. That assumption does not hold for a deployment fronting a public bucket with a CDN on the application's own domain, where the same-origin reasoning reverses and public content becomes more dangerous rather than less.

Ticket 15 left already-stored public SVGs as the one population no layer reaches, because the Delivery route's content policy cannot follow an asset the plugin never serves.

Find, against primary sources: whether Cloudflare R2 and S3-compatible drivers under Laravel's filesystem contract support setting response headers (`Content-Security-Policy`, `Content-Disposition`) as stored object metadata at write time, and whether `league/flysystem-aws-s3-v3` exposes that through the standard `put`/`writeStream` options; how R2 custom domains and public bucket URLs interact with `Storage::disk()->url()` configuration, and whether a configured CDN base URL is a thing the plugin should accept; and whether R2 or CloudFront can attach response headers at the edge independently of the stored object, which would make this a deployment concern the plugin documents rather than code it ships.

Report the facts. The product decisions they unblock are a separate ticket.

## Answer

Facts only; the product decisions they unblock are [Settle Public Placement Origin, Headers and the Stored SVG Residual](23-public-placement-origin-and-header-decisions.md). Full cited evidence is in [research-22-public-delivery-origin-and-headers.md](../research-22-public-delivery-origin-and-headers.md).

### Stored object headers: three of four, and not the one ticket 15 wanted

Laravel's `FilesystemAdapter::put()`, `putFileAs()` and `writeStream()` pass the `$options` array to Flysystem untouched (only a bare string is coerced to `visibility`), and `AwsS3V3Adapter::createOptionsFromConfig()` forwards every key in `AVAILABLE_OPTIONS` into the PutObject call. That list includes `ContentType`, `ContentDisposition`, `CacheControl` and `Metadata`. R2's S3 compatibility table marks PutObject system metadata (Content-Type, Cache-Control, Content-Disposition, Content-Encoding, Content-Language, Expires, Content-MD5) implemented, and every ACL header unimplemented.

`Content-Security-Policy` is **not** S3 system metadata and has no slot. The only free-form slot is `Metadata`, which R2 returns `x-amz-meta-` prefixed and which is therefore inert as a policy header. Ticket 15's layer 2 genuinely cannot reach public placement through stored metadata; layer 3 remains the only plugin-side cover.

Ticket 13's `ContentDisposition: attachment` on public uploads is available with zero custom code. S3 PutObject cannot patch a single metadata field, so stored headers are an upload-time-only lever, which is consistent with "rules bind at ingest and at delivery, never at rest".

### A CDN base URL is already a disk config key

`Illuminate\Filesystem\AwsS3V3Adapter::url()` returns the disk's configured `url` value joined to the prefixed path when set, and falls back to `client->getObjectUrl()` otherwise. An R2 custom domain or an `r2.dev` URL goes there, configured by the application, so the plugin needs no CDN setting of its own. `temporary_url` is a separate key for signed URLs, which matters because R2 presigned URLs do not work on custom domains. Separately, `temporaryUrl()` accepts per-request `ResponseContentDisposition` and `ResponseContentType` overrides, which is a private-path lever rather than a public one.

### Edge headers exist, and require a custom domain

Cloudflare Response Header Transform Rules set, add or remove arbitrary response headers (the reserved list is `cf-*`, `x-cf-*`, `server` and `eh-*`, so neither CSP nor Content-Disposition is reserved), and Cloudflare documents CSP replacement through them. CloudFront response headers policies name CSP explicitly and can override origin headers. The caveat that decides the shape of this: Cloudflare states WAF, caching and access controls are unavailable on `r2.dev`, so an edge CSP requires an R2 custom domain, and `r2.dev` is rate-limited and documented as non-production.

### Left unverified

Which headers R2 actually emits on a public GET (settable and stored is documented, returned-on-GET is not); whether R2 honours `response-content-disposition` GET overrides; Cloudflare transform-rule plan availability; cache interaction with per-object disposition. The repo has no `composer.json` or `vendor/` yet, so no version was confirmed locally: Laravel 13 docs pin `league/flysystem-aws-s3-v3` `^3.0`, the branch read, and a future 4.x pin would need `AVAILABLE_OPTIONS` re-checked.

