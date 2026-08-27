# Define Upload Validation and Active Content Handling

Status: resolved
Type: grilling
Blocked by: 07, 11

## Question

The destination promises arbitrary file types, but nothing has yet fixed what a field validates at upload beyond ticket 06's `acceptedFileTypes` picker gate, nor how the plugin treats browser-active formats (HTML, SVG, and files whose declared type is executable or scriptable). Ticket 07 fixed that private content always flows through the plugin-owned Delivery route with inline-by-default disposition, and ticket 11 fixed that a MIME type carries a `mime_source` rung recording how much it can be trusted. Both are now inputs to this decision.

Decide: what size and type limits exist and at which level (package global, field, or both); whether a declared MIME is ever verified against content at upload and what happens on a mismatch; and whether active content is refused, stored but forced to attachment disposition on delivery, or served as-is. Ticket 11's `mime_source` should inform the disposition rule, since an extension-derived type is a weaker basis for serving something inline than a sniffed one.

## Answer

### Size limits

`media.max_upload_size` in package config (default 12 MB, env-readable) is the value every `MediaPicker` starts from, and `->maxSize()` overrides it per field in either direction. Size is fit-for-purpose (an avatar field wants 2 MB, a video field 500 MB), so unlike the type floor below there is no safety reason to stop a field raising it. The plugin reads the PHP (`upload_max_filesize`, `post_max_size`) and Livewire temporary-upload ceilings at boot and logs a warning when a configured limit exceeds them, because that mismatch surfaces as a confusing browser-level failure rather than a validation message.

### Type floor: a denylist, never an allowlist

The destination promises arbitrary file types, so the package-global gate is a deny list, `media.blocked_types` (default `php`, `phar`, `phtml`, `htaccess`, plus executable mimes such as `application/x-httpd-php` and `application/x-msdownload`). It is a floor a field can never widen: ticket 06's `acceptedFileTypes` only ever narrows what remains, and unset still means everything else. Matching runs on both extension and resolved mime, since either alone is evadable.

### Uploads are always sniffed

Every upload is sniffed with `finfo`. The bytes are already on local disk as a Livewire temporary file, so this costs nothing and needs no network fetch, unlike ticket 08's import path where sniffing stays behind `--sniff` precisely because it must fetch. The consequence is that an uploaded asset never lands at the `header` rung in practice, so `mime_source: header` becomes an import-only value. The browser's `Content-Type` is kept only as an input to the mismatch check, never as the stored type.

### Mismatch: the bytes win, and the gate re-runs against them

The sniffed type is always the stored `mime_type`, and a mismatch is never an error in itself, because innocent disagreement is routine (`.csv` sniffing as `text/plain`, Office formats as `application/zip`). Instead the gate re-runs against the truth: after sniffing, the resolved type is re-checked against `media.blocked_types` and the field's `acceptedFileTypes`, and the upload is rejected only if it now fails, with a message naming both types ("this file declares itself as image/jpeg but its contents are text/html"). One extra rule catches deception that a permissive field would otherwise wave through: a sniffed type in a different top-level family than the extension implies is refused even when the field accepts both.

### Active content: stored, never served inline, SVG excepted

HTML, XML, JavaScript and anything scriptable are accepted, since an HTML file is legitimate cargo for a downloads field, but the Delivery route serves them `attachment` unconditionally, overriding ticket 07's inline default and ignoring an explicit `?download=0`. The reason is that the Delivery route is served from the application's own origin, so inline active content there is stored XSS against a logged-in panel session rather than a harmless file.

SVG is carved out because it is a genuine image people want inline and thumbnailed. It is sanitized at upload (script elements and event-handler attributes stripped) and then treated as an ordinary inline image; an SVG that fails to sanitize is refused. Sanitization needs a library, so the plugin probes for one at runtime and refuses SVG uploads when it is absent, rather than accepting them unsanitized.

**Amended by [Choose the SVG Sanitizer Dependency](14-svg-sanitizer-dependency.md).** The library is `enshrined/svg-sanitize` `^0.22`, and it ships as a hard `require` rather than the optional dependency this ticket assumed: ticket 12's `ffmpeg` analogy fails because Composer can install a Composer package, and a silently SVG-less plugin is a functional regression rather than a cosmetic one. The runtime probe stays as a fail-closed guard. External references are **not** stripped, so this ticket's original wording overpromised; see ticket 14's amendment and [Close the SVG External Reference Gap](15-svg-external-reference-gap.md). "Fails to sanitize" also needs a three-way check (`false`, thrown, or a returned root element that is not `svg`), because a well-formed non-SVG comes back as a string rather than as a failure.

### Ingest rules and serving rules split at the importer

Ingest rules do not apply to import. Size limits and the mismatch refusal are upload-time concerns, and an importer that silently skipped rows would leave a host model pointing at an asset that does not exist, so the importer adopts everything it discovers and lists refusable rows in its report. Serving rules do apply to every asset regardless of `source`, since disposition is a property of what the bytes are, not of how they arrived. The denylist sits between the two: it blocks import as well, because adopting a `.phar` is never wanted, and a blocked row is reported by path rather than adopted.

### Disposition is earned, not assumed

`mime_source` is a second, independent disposition gate. An asset at the `extension` or `unknown` rung is served `attachment` whatever its `mime_type` claims, because an extension-derived `image/jpeg` asserts only that the filename ended in `.jpg`. Disposition is therefore `inline` only when the type is not active content **and** the rung is `sniffed` or `header`. This makes the rule an honest statement of what is known rather than a type blocklist a bad filename walks past, and it gives ticket 11's `media:resolve-mimes --sniff` a concrete payoff: running it promotes imported assets from download-only to inline-renderable. The visible cost is that a freshly imported library renders as downloads until sniffed, so `media:resolve-mimes --sniff` is documented as the second step of the migration rather than optional cleanup. Recorded as `docs/adr/0004-disposition-is-earned-not-assumed.md`.

### Public placement refuses active content

A public asset bypasses the Delivery route entirely (ticket 07) and resolves straight to `Storage::disk()->url()`, so the plugin is not in the request path and cannot force `attachment`. The combination is therefore refused at upload: active content (SVG excluded once sanitized) cannot be uploaded to a field whose placement is public, as a validation failure naming the reason rather than a silent downgrade to private, because ticket 03 made placement a deliberate field-level decision and overriding it would make the field lie about where its uploads land. As defence in depth the plugin sets `ContentDisposition: attachment` object metadata where the driver supports it. An imported active-content asset on a public disk is reported but adopted, consistent with the ingest rule above.

### Rules bind at ingest and at delivery, never at rest

A stored asset is never rejected, hidden or deleted because config changed. The denylist and size limit are upload-time gates only, so an existing oversized asset stays attached and usable. The disposition rules, being delivery-time, apply immediately to everything, which is the intended direction: tightening config makes serving safer at once without touching stored data. A stored unsanitized SVG predating the sanitizer is not retroactively sanitized (that would rewrite bytes the plugin did not create, in place, on a read path) and is served `attachment` until re-uploaded. No revalidation command ships; `media:resolve-mimes` already covers the only rung that changes what an asset can do.

### The offer gate takes the denylist, not the rung

An asset whose type is on `media.blocked_types` is never offered, because offering it invites someone to attach a file the app has declared unwanted. A weak-rung asset stays offered normally: the rung governs how content is served, not whether the asset is a legitimate choice, and hiding imported assets from every picker until a sniff pass ran would make the migration window unusable. In the grid a weak-rung asset shows ticket 12's glyph tile rather than a thumbnail.

### Sanitized SVG is its own thumbnail

No rasterization. A sanitized SVG gets no `thumb` or `preview` derivative rows; the grid card renders the SVG file directly and lets the browser scale it. The file is typically smaller than the WEBP would be, and this keeps the rasterizer's SVG frontend (itself an attack surface through entity expansion and render-time network fetches) out of the pipeline entirely rather than trusting it. This paragraph originally also claimed sanitization had removed the external references that make browser-side rendering risky; ticket 14 established that it has not, which is a tracking leak rather than a script-execution risk and does not change the no-rasterization ruling, but it is the gap ticket 15 exists to close. This is a narrow, stated exception to ticket 12's "two fixed variants for every renderable asset".

## Comments

- Resolved with the requester on 2026-08-27 via grilling; all eleven questions accepted as recommended.
