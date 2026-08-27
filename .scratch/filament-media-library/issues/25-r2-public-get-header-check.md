# Confirm R2 Returns Stored Headers on a Public GET

Status: open
Type: task
Blocked by:

## Question

Nothing to decide: a fact to establish, and the spec is written around it in the meantime.

[Settle Public Placement Origin, Headers and the Stored SVG Residual](23-public-placement-origin-and-header-decisions.md) has the plugin write `ContentType`, `ContentDisposition` and `CacheControl` as object metadata on every upload. Research 22 verified that Laravel passes these through untouched, that Flysystem's S3 adapter forwards them into PutObject, and that Cloudflare documents R2 as *storing* all three. It could not find any Cloudflare statement about which headers R2 *emits* when an object is fetched over a public bucket URL. The inference from S3 semantics is strong, but it is an inference, which is why ticket 23 treats the stored headers as defence in depth rather than as a control the spec leans on.

Do the check against a real bucket: upload an object with all three set through `Storage::disk()->put()`, fetch it over both an `r2.dev` URL and a custom domain, and record the response headers verbatim. Two answers matter beyond the headline. Whether a custom domain behind Cloudflare Cache alters or drops them (ticket 22 flagged cache interaction as unverified). And whether the S3 `response-content-disposition` query override works on R2, which ticket 22 also left open and which is the private path's equivalent lever through `temporaryUrl()`.

Resolve by recording the observed headers and the environment they were observed in. If `Content-Disposition` is not returned on a public GET, ticket 23's stored-header decision survives (the headers cost nothing) but the spec must stop describing it as defence in depth for public placement, and ticket 13's public refusal becomes the sole control there.
