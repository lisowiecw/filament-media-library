# Settle Public Placement Origin, Headers and the Stored SVG Residual

Status: open
Type: grilling
Blocked by: 22

## Question

Ticket 22 reported the facts; this ticket spends them. Three decisions hang off them, and they interact.

**The same-origin assumption.** Ticket 13 refused active content on public placement because a public asset resolves to `Storage::disk()->url()` on a foreign origin, so the plugin cannot force `attachment`. That reasoning reverses for a deployment fronting a public bucket with a CDN on the application's own domain: the content is then same-origin with the panel session, which makes public placement *more* dangerous than private, not less. Decide whether the plugin cares. Does it try to detect the case (the disk's configured `url` host against the app host), does it document it as a deployment rule the operator owns, or does the existing refusal already cover it because active content never reaches public placement anyway? Note the refusal has a hole shaped exactly like ticket 13's SVG carve-out.

**Stored headers, now that their reach is known.** `ContentDisposition`, `ContentType` and `CacheControl` are settable at write time through the standard Laravel options array, and R2 honours them; `Content-Security-Policy` is not settable at all. Decide what the plugin actually writes on a public upload, whether it writes anything on a private one (where the Delivery route already sets headers), and whether ticket 13's "as defence in depth where the driver supports it" becomes an unconditional rule or stays best effort. Since PutObject cannot patch one field, whatever is chosen binds at upload only and an existing object keeps whatever it has.

**The stored public SVG residual.** Ticket 15 left already-stored public SVGs as the population no layer reaches, and ticket 22 confirms no stored-header or plugin-side lever closes it: the only mechanisms are an edge CSP (Cloudflare Transform Rules or a CloudFront response headers policy, and on Cloudflare only with a custom domain, never on `r2.dev`) and re-uploading. Decide whether the remedy is documentation naming the concrete edge configuration, a command that reports which stored public SVGs are affected so an operator can re-upload deliberately, or acceptance of the residual as stated. A report-only command has precedent in ticket 05's orphan cleanup; rewriting stored bytes does not, and ticket 13's "never at rest" rule is not up for reopening here.

Also settle whether a configured CDN base URL is a plugin concern at all. Ticket 22 found the disk's `url` key already covers it, which suggests no, but say so explicitly so ticket 03's contract is not silently extended later.
