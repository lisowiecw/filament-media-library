# Confirm R2 Returns Stored Headers on a Public GET

Status: resolved
Type: task
Blocked by:

## Question

Nothing to decide: a fact to establish, and the spec is written around it in the meantime.

[Settle Public Placement Origin, Headers and the Stored SVG Residual](23-public-placement-origin-and-header-decisions.md) has the plugin write `ContentType`, `ContentDisposition` and `CacheControl` as object metadata on every upload. Research 22 verified that Laravel passes these through untouched, that Flysystem's S3 adapter forwards them into PutObject, and that Cloudflare documents R2 as *storing* all three. It could not find any Cloudflare statement about which headers R2 *emits* when an object is fetched over a public bucket URL. The inference from S3 semantics is strong, but it is an inference, which is why ticket 23 treats the stored headers as defence in depth rather than as a control the spec leans on.

Do the check against a real bucket: upload an object with all three set through `Storage::disk()->put()`, fetch it over both an `r2.dev` URL and a custom domain, and record the response headers verbatim. Two answers matter beyond the headline. Whether a custom domain behind Cloudflare Cache alters or drops them (ticket 22 flagged cache interaction as unverified). And whether the S3 `response-content-disposition` query override works on R2, which ticket 22 also left open and which is the private path's equivalent lever through `temporaryUrl()`.

Resolve by recording the observed headers and the environment they were observed in. If `Content-Disposition` is not returned on a public GET, ticket 23's stored-header decision survives (the headers cost nothing) but the spec must stop describing it as defence in depth for public placement, and ticket 13's public refusal becomes the sole control there.

## Answer

R2 emits all three stored headers verbatim on a public GET, over both origins, and the S3 `response-content-*` query overrides work. The inference ticket 23 declined to lean on is now observed fact.

### Environment

Observed 2026-08-27 against a real bucket named `public` on a live account, both the Public Development URL (`pub-*.r2.dev`) and a custom domain (`cdn.britishpolish.co.uk`) enabled on it. The object was written by a hand-signed SigV4 `PutObject` rather than through `Storage::disk()->put()`, since ticket 22 had already established the Laravel and Flysystem passthrough by reading source, leaving R2's own behaviour as the only unproven link. Stored on the object: `Content-Type: image/webp`, `Content-Disposition: attachment; filename="report.webp"`, `Cache-Control: public, max-age=31536000, immutable`. Key ended `.webp`, which is on Cloudflare's default-cached extension list, so the custom domain cached without a Cache Everything rule.

### Observed responses

`HEAD` over the S3 API, the control proving all three landed as stored metadata:

```
HTTP 200
Content-Type: image/webp
Cache-Control: public, max-age=31536000, immutable
Content-Disposition: attachment; filename="report.webp"
```

`GET` over `r2.dev`:

```
HTTP/1.1 200 OK
Content-Type: image/webp
Cache-Control: public, max-age=31536000, immutable
Content-Disposition: attachment; filename="report.webp"
```

`GET` over the custom domain, first hit then second:

```
HTTP/2 200                     HTTP/2 200
content-type: image/webp       content-type: image/webp
content-disposition: attachment; filename="report.webp"
cache-control: public, max-age=31536000, immutable
cf-cache-status: MISS          cf-cache-status: HIT
                               age: 2
```

Presigned `GET` carrying `response-content-disposition=inline; filename="over.webp"` and `response-content-type=text/plain`:

```
HTTP 200
Content-Type: text/plain
Content-Disposition: inline; filename="over.webp"
Cache-Control: public, max-age=31536000, immutable
```

### What this settles

**The headline.** `Content-Disposition` is returned on a public GET, so the branch this ticket guarded against does not fire. Ticket 23's stored-header decision stands and its defence-in-depth framing is now earned rather than assumed: ticket 13's public refusal is a first control with a real second one behind it, not the sole control. Ticket 23's instruction to store all three on every upload needs no amendment.

**Cache interaction, which ticket 22 flagged unverified.** Cloudflare Cache alters none of the three. The edge served a HIT with `content-type`, `content-disposition` and `cache-control` byte-identical to the MISS, so nothing in ticket 23's header set is cache-fragile. Two limits on the observation: the object was on the default-cached extension list, so a type outside that list is cached only under a Cache Everything rule (unchanged behaviour for the headers themselves, just a MISS every time); and `immutable` survived the edge intact, which matters because ticket 21's digest-on-the-URL scheme and ticket 07's quantized expiry both assume a browser actually receives it.

**The `response-content-*` override.** Supported, contrary to what the S3 compatibility page's silence suggested (it lists neither an implemented nor an unimplemented entry). Both `response-content-disposition` and `response-content-type` were honoured over the stored values. This gives `temporaryUrl()` a per-request disposition lever on the private path. Recording the capability only: it changes nothing today, because ticket 07 decided private content always flows through the plugin-owned Delivery route and never a raw presigned URL, and ticket 15's CSP layer depends on that route existing. The lever is worth knowing about if that decision is ever revisited.

**No new fog.** Ticket 22's remaining open item, edge CSP through Response Header Transform Rules on a custom domain, is untouched by this and already documented as an operator obligation by ticket 23 (ADR-0009).

### Method note

Two false results appeared on the first run and are recorded so they are not mistaken for findings. A missing bucket segment in the path (R2's S3 endpoint is path-style, `/{bucket}/{key}`) made the presigned GET return `NotImplemented: ListObjectsV1 search parameter response-content-disposition not implemented`, which reads exactly like a real negative answer and is not one. And Cloudflare answers python `urllib` with error 1010 on both public origins, so the public GETs must go through a client with an ordinary browser user-agent.
