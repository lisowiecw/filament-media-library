# 5. Public SVG is sanitized more strictly than private SVG

Date: 2026-08-27

## Status

Accepted, with its Context amended on 2026-09-11 by the upgrade to `enshrined/svg-sanitize` `1.0`. Amends [4. Disposition is earned, not assumed](0004-disposition-is-earned-not-assumed.md) only insofar as it adds a second placement-dependent rule.

## Context

A Sanitized SVG is the one Active content type the plugin serves for rendering in place. Sanitization was assumed to remove external references; it does not. `enshrined/svg-sanitize` leaves `<image href="https://...">` intact by design, and its optional remote-reference matcher misses unquoted and inline-style URLs. The residual is a privacy leak, not stored XSS: opening the library grid makes a third-party request carrying a referrer and an IP.

The cheap, general fix is a `default-src 'none'` content policy on the Delivery route, which neutralizes the fetch without the plugin having to be correct about which markup is dangerous. But a public asset never reaches the Delivery route: it resolves straight to the disk's own URL, and the plugin is not in the request path. The layer that covers every other SVG covers public SVG not at all.

The alternatives were to refuse SVG on public placement as the plugin already refuses HTML, or to accept the leak and document it.

### Amendment, 2026-09-11

`enshrined/svg-sanitize` `1.0` closes most of the residual this decision was written for. Its remote-reference matcher, which the plugin opts into, was generalized: `<image href="https://...">` and its `xlink:href` spelling are now stripped, as are a protocol-relative URL, an unquoted `url()`, a `url()` among other declarations in a `style` attribute, and a remote `url()` or `@import` in the text of a `<style>` element. Every vector checked by hand against `1.0` came back clean, so the sentence above describing what the matcher misses no longer describes the installed sanitizer.

The decision stands unchanged. The narrow pass is not retired on the strength of a residual having shrunk, because the reason a public asset needs a rule of its own is structural rather than a list of vectors: the plugin is not in the request path, so it gets no second chance if a later markup trick, or a later sanitizer regression, gets through. The reversal condition named below is unchanged and still unmet: public assets gaining a way to carry response headers of their own is what makes this pass redundant, not the upstream matcher improving.

What does change is the cost side. Refusing an embedded raster, a `<style>` block or a link on a public field now buys less than it did, so a future revisit has a weaker case to answer.

## Decision

Public placement runs a narrower sanitization pass, dropping embedded images, style blocks and links. Private placement does not. An SVG that loses an element to the narrow pass is refused, naming the element.

## Consequences

The plugin's own promise about a file type now depends on where the field puts it, which is a seam a reader will not expect and is the reason this is written down.

Legitimate SVGs are rejected on public fields: an embedded raster, a `<style>` block, a link. That cost is paid only where the content policy cannot reach, and never on private uploads, which are the majority. Refusing rather than silently stripping means the editor learns why, at the cost of a rejection they may not be able to fix without re-exporting. Since the 2026-09-11 amendment that cost buys less than it did, because the sanitizer now strips on its own most of what the narrow pass was dropping whole elements to be sure of.

Public SVGs stored before this decision are the one population no layer covers, since nothing is re-sanitized at rest. Re-uploading is the remedy.

The decision reverses cleanly the day public assets gain a way to carry response headers of their own; the narrow pass would then be redundant rather than wrong.
