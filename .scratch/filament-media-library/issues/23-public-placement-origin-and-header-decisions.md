# Settle Public Placement Origin, Headers and the Stored SVG Residual

Status: resolved
Type: grilling
Blocked by: 22

## Question

Ticket 22 reported the facts; this ticket spends them. Three decisions hang off them, and they interact.

**The same-origin assumption.** Ticket 13 refused active content on public placement because a public asset resolves to `Storage::disk()->url()` on a foreign origin, so the plugin cannot force `attachment`. That reasoning reverses for a deployment fronting a public bucket with a CDN on the application's own domain: the content is then same-origin with the panel session, which makes public placement *more* dangerous than private, not less. Decide whether the plugin cares. Does it try to detect the case (the disk's configured `url` host against the app host), does it document it as a deployment rule the operator owns, or does the existing refusal already cover it because active content never reaches public placement anyway? Note the refusal has a hole shaped exactly like ticket 13's SVG carve-out.

**Stored headers, now that their reach is known.** `ContentDisposition`, `ContentType` and `CacheControl` are settable at write time through the standard Laravel options array, and R2 honours them; `Content-Security-Policy` is not settable at all. Decide what the plugin actually writes on a public upload, whether it writes anything on a private one (where the Delivery route already sets headers), and whether ticket 13's "as defence in depth where the driver supports it" becomes an unconditional rule or stays best effort. Since PutObject cannot patch one field, whatever is chosen binds at upload only and an existing object keeps whatever it has.

**The stored public SVG residual.** Ticket 15 left already-stored public SVGs as the population no layer reaches, and ticket 22 confirms no stored-header or plugin-side lever closes it: the only mechanisms are an edge CSP (Cloudflare Transform Rules or a CloudFront response headers policy, and on Cloudflare only with a custom domain, never on `r2.dev`) and re-uploading. Decide whether the remedy is documentation naming the concrete edge configuration, a command that reports which stored public SVGs are affected so an operator can re-upload deliberately, or acceptance of the residual as stated. A report-only command has precedent in ticket 05's orphan cleanup; rewriting stored bytes does not, and ticket 13's "never at rest" rule is not up for reopening here.

Also settle whether a configured CDN base URL is a plugin concern at all. Ticket 22 found the disk's `url` key already covers it, which suggests no, but say so explicitly so ticket 03's contract is not silently extended later.

## Answer

Ticket 22 reported the facts; this is how they are spent. The through-line is that the plugin has exactly three levers on a public asset (the bytes it accepts, the headers it stores at upload, and the documentation it writes), and none of them is a request-time lever, so every decision here is made once at upload or not at all.

### The same-origin case is a documented deployment rule, never a detected one

The plugin assumes public placement is a foreign origin and does not verify it. The operator obligation is stated in the README: serve a public media bucket from a hostname that is not the panel's origin and shares no cookie scope with it.

Detection was rejected. Comparing the disk's configured `url` host against the application host is wrong in both directions in exactly the deployments that have the problem: a reverse proxy, a CNAME, a sibling subdomain under a shared parent cookie domain, and an `APP_URL` that need not match the panel's real host. A check that misses real same-origin deployments while firing on safe ones is worse than none, because it reads as cover. Shipping it later would also start refusing uploads in deployments that work today, which is why the assumption is written down now.

Ticket 13's existing refusal is *nearly* the whole answer on its own, and the gap is worth naming precisely. HTML, XML, JavaScript and anything scriptable already cannot be uploaded to a public field, so the only active content that reaches a public bucket is a sanitized SVG, or an active-content asset adopted in place by the importer (reported but adopted, per ticket 13, unchanged here). The SVG case is exactly what ticket 15's layer 3 was sized for. So the deployment rule protects a hole that already has a plugin-side layer over it; it is defence in depth for the one carve-out, not the primary control.

Recorded as `docs/adr/0009-public-media-is-a-foreign-origin-by-deployment.md`, because the foreign-origin premise is load-bearing under both ticket 13's refusal and ticket 15's layer 3 and was implicit in both rather than stated anywhere.

### Stored headers: three keys, every upload, no placement branch

Every upload writes, through the standard Laravel options array that ticket 22 confirmed passes through untouched:

- `ContentType`, from the sniffed bytes rather than the browser's claim, so the stored header agrees with the recorded `mime_type` at the `sniffed` rung. Note Flysystem will otherwise detect and set this itself, so passing it explicitly is how the plugin keeps the value it decided on rather than one it did not.
- `ContentDisposition`, whenever the delivery rule for that asset would be `attachment` under ticket 13's earned-disposition rule.
- `CacheControl: public, max-age=31536000, immutable`.

Unconditionally, on private placement as well as public, with no driver probe. Ticket 13's "where the driver supports it" hedge is dropped: unknown config keys are ignored by non-S3 adapters, so the hedge bought a branch and nothing else. The stored headers are simply inert on a private asset, since the Delivery route sets its own, and a rule that is merely redundant in one case is cheaper to hold than a rule with an exception in it. `Content-Security-Policy` is not written because it cannot be: it has no PutObject slot, and `x-amz-meta-` comes back prefixed and inert, which is ticket 22's finding that ticket 15's layer 2 provably cannot reach public placement.

`immutable` is honest here in a way it is not for derivatives. Ticket 21 had to quantize derivative URL expiry precisely because a regeneration overwrites in place; a public *original* never does, since ticket 05 makes replace create a new asset and ticket 03 makes the object key opaque and never reused. The one case that breaks the promise is the importer adopting a key that an external system later overwrites, which the plugin does not control and which is documented in the import docs rather than paid for with a weaker header for every asset. The cost is stated plainly: an operator who overwrites a stored object out of band faces a year-long cache.

Two constraints ride along. PutObject cannot patch a single metadata field, so this binds at upload only and every existing object keeps whatever headers it already has, which is ticket 13's "never at rest" rule rather than an exception to it. And ticket 22 could not find a Cloudflare sentence confirming that R2 *emits* a stored `Content-Disposition` on a public GET (the inference from S3 semantics is strong but unstated), so the spec treats these headers as defence in depth and not as a security control until that is checked empirically against a real bucket.

### The stored public SVG residual: documentation, and the report that already exists

The remedy is documentation, naming the concrete edge configuration rather than gesturing at one: a Cloudflare Response Header Transform Rule or a CloudFront response headers policy, with the flat caveat that on Cloudflare this requires a custom domain and is impossible on `r2.dev` (which is rate-limited and development-only in any case). Re-uploading is named as the only in-plugin remedy, and the residual stays a residual.

No report-only command ships, despite ticket 05's orphan precedent. The report already exists: ticket 10's management page lists every asset unscoped, and ticket 09's facets cover type, visibility and date, so "public SVGs uploaded before *date*" is a query a human can already run. A `media:report-public-svgs` command would ship a narrower version of a feature two closed tickets already paid for.

### A CDN base URL is not a plugin concern

Explicitly, and on the record so nobody later adds a `media-library.cdn_url` key thinking it was an oversight. The plugin calls `Storage::disk()->url()` and accepts whatever host that produces, exactly as ticket 03 made bucket selection the disk's business. Ticket 03's contract is restated, not extended.

The asymmetry this implies is correct rather than a misconfiguration: `url` and `temporary_url` are independent disk keys, and R2 presigned URLs do not work on custom domains, so an operator may legitimately have a custom-domain public host and a different signed-URL host at the same time.

### Amendments to closed tickets

- **Ticket 13** loses its "as defence in depth where the driver supports it" hedge on stored `ContentDisposition`: it becomes unconditional and gains `ContentType` and `CacheControl` alongside it. Its public-placement refusal stands unchanged, and its foreign-origin premise is now explicit in ADR-0009 rather than implicit in the paragraph.
- **Ticket 15's** residual is unchanged in substance and gains a remedy: an edge CSP, documented concretely, with the management page as the way to find the affected population.
- **Ticket 03** is confirmed rather than amended: no CDN base URL setting, now or later.
