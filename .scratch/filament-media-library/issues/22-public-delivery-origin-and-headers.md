# Research Public Asset Delivery Origin and Headers

Status: open
Type: research
Blocked by:

## Question

Two fog patches that resolve together, both turning on the same fact: how much control the plugin has over a *public* object's delivery, where it is not in the request path.

Ticket 13 assumed public content is served from a foreign origin, which is why active content is refused on public placement. That assumption does not hold for a deployment fronting a public bucket with a CDN on the application's own domain, where the same-origin reasoning reverses and public content becomes more dangerous rather than less.

Ticket 15 left already-stored public SVGs as the one population no layer reaches, because the Delivery route's content policy cannot follow an asset the plugin never serves.

Find, against primary sources: whether Cloudflare R2 and S3-compatible drivers under Laravel's filesystem contract support setting response headers (`Content-Security-Policy`, `Content-Disposition`) as stored object metadata at write time, and whether `league/flysystem-aws-s3-v3` exposes that through the standard `put`/`writeStream` options; how R2 custom domains and public bucket URLs interact with `Storage::disk()->url()` configuration, and whether a configured CDN base URL is a thing the plugin should accept; and whether R2 or CloudFront can attach response headers at the edge independently of the stored object, which would make this a deployment concern the plugin documents rather than code it ships.

Report the facts. The product decisions they unblock are a separate ticket.
