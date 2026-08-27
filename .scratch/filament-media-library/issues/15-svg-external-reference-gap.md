# Close the SVG External Reference Gap

Status: resolved
Type: grilling
Blocked by: 14

## Question

Ticket 14 established that `enshrined/svg-sanitize` does not strip external references the way ticket 13 assumed: `removeRemoteReferences` is off by default, its matcher misses unquoted `url(...)` and any `style="fill: url(...)"`, and `<image href="https://...">` survives regardless because `isHrefSafeValue()` allowlists `http://` and `https://` and `<image>` is an allowed tag. `<style>` and `<a>` also survive, so arbitrary CSS reaches the served file. The consequence is a privacy and tracking leak: an admin opening the library grid makes a third-party request carrying a referrer and an IP.

How does the plugin close it, and how far? The options ticket 14 surfaced, in increasing cost: enable `removeRemoteReferences(true)` and document the residual gaps; additionally narrow the tag and attribute allowlists with `setAllowedTags()` and `setAllowedAttrs()` to drop `image` and `style`, which closes both remaining vectors but rejects legitimate SVGs; or add a restrictive `Content-Security-Policy` header (`default-src 'none'; style-src 'unsafe-inline'; sandbox`) on the Delivery route, which neutralizes remote fetches and any script that got past the sanitizer without depending on the sanitizer being perfect.

Decide which of these ship, and settle what the CSP option implies beyond SVG: whether the header applies to every Delivery route response or only to inline-served images, and whether a public SVG (which never passes through the Delivery route at all, per ticket 07) can be covered by any of this or must simply be accepted as uncovered.

## Answer

The gap closes in **layers**, not by picking one lever, because the failure ticket 14 exposed was not that the sanitizer was misconfigured but that ticket 13 trusted it to be right. Every layer below is chosen for how little it depends on the layer beneath it.

### Layer 1: `removeRemoteReferences(true)`, always

Switched on for every SVG upload, public or private. It is free and it closes the quoted `url('https://...')` case. Its two known holes (unquoted `url(...)`, and `style="fill: url(...)"`, upstream issues #94 and #116, both unaddressed) are documented as residual rather than worked around, because the layers below cover them.

### Layer 2: a Content-Security-Policy on every Delivery response

`default-src 'none'; style-src 'unsafe-inline'; sandbox`, unconditionally, on every response the Delivery route produces: attachments, images, derivatives, not only SVG. Those other types cost nothing under `default-src 'none'`, and a header applied conditionally is a header someone eventually forgets to apply.

This is the layer that matters most, because it is the only one that does not assume the sanitizer got it right. It neutralizes a remote fetch, and any script that slipped past the allowlists, without the plugin having to be correct about which markup is dangerous.

**It adds a constraint to the Delivery route.** ADR 0001 lets Delivery either stream the bytes or redirect to the disk's own temporary URL. A CSP header does not survive a 302, so **inline SVG delivery must stream, never redirect**. Other types keep the choice.

### Layer 3: narrowed allowlists, public placement only

`setAllowedTags()` / `setAllowedAttrs()` narrowed to drop `image`, `style` and `a` runs **only when the field's placement is public**, and never on private uploads.

The reason is structural and is the same one that made ticket 13 refuse HTML on public placement: a public asset resolves straight to `Storage::disk()->url()` and never passes through the Delivery route, so layer 2 cannot reach it at all. Ticket 13's SVG carve-out therefore left public SVG with layer 1 alone. Rather than withdraw the carve-out (public SVG logos are the most common real use, and refusing them makes the carve-out worthless) or accept the leak, the plugin pays layer 3's compatibility cost exactly where the cheaper layer cannot reach, and nowhere else.

The cost is real: an SVG with an embedded raster `<image>`, a `<style>` block, or a link is legitimate output from ordinary design tools. That cost is not paid on private uploads, which are the majority and which layer 2 already covers.

### A public SVG that trips the narrow allowlist is refused, not silently stripped

`enshrined/svg-sanitize` does not fail on a disallowed tag; it removes it and returns a string, so an SVG with an embedded raster would come back visually broken and be stored that way. The plugin therefore **diffs the pass** and refuses the upload naming the offending element ("this SVG embeds an external image, which cannot be served from a public field") rather than handing the editor a logo with a hole in it and no explanation.

It is refused outright, with no offer to store it privately instead: ticket 03 made placement a deliberate field-level decision and ticket 13 already ruled out silently downgrading it, and an offer that changes where the file lands is that downgrade wearing a prompt.

This makes the refusal rule for SVG two-part, extending ticket 14's three-way check: refuse when `sanitize()` returns `false`, throws, or returns a root that is not `svg` (any placement), **and** refuse when a public-placement pass removed a tag the default pass would have kept.

### Nothing is re-sanitized at rest

An SVG accepted before layer 1 was enabled keeps its bytes. No migration command ships, and ticket 13's "rules bind at ingest and at delivery, never at rest" is not reopened: rewriting stored bytes on a read path is exactly what that rule exists to prevent.

Layer 2, being a delivery-time rule, covers every already-stored private SVG immediately, which is the clearest demonstration of why it is worth its cost. Already-stored **public** SVGs are the one population no layer reaches retroactively; that residual is documented, and re-uploading is the remedy.

### Amendment to ticket 13

Ticket 13's "Sanitized SVG is its own thumbnail" no longer rests on sanitization having removed external references. The no-rasterization ruling stands unchanged; what carries the risk now is the Delivery route's CSP for private SVG, and the narrowed allowlist for public SVG.

## Comments

- Resolved with the requester on 2026-08-27 via grilling; all five questions accepted as recommended.
