# 5. Public SVG is sanitized more strictly than private SVG

Date: 2026-08-27

## Status

Accepted. Amends [4. Disposition is earned, not assumed](0004-disposition-is-earned-not-assumed.md) only insofar as it adds a second placement-dependent rule.

## Context

A Sanitized SVG is the one Active content type the plugin serves for rendering in place. Sanitization was assumed to remove external references; it does not. `enshrined/svg-sanitize` leaves `<image href="https://...">` intact by design, and its optional remote-reference matcher misses unquoted and inline-style URLs. The residual is a privacy leak, not stored XSS: opening the library grid makes a third-party request carrying a referrer and an IP.

The cheap, general fix is a `default-src 'none'` content policy on the Delivery route, which neutralizes the fetch without the plugin having to be correct about which markup is dangerous. But a public asset never reaches the Delivery route: it resolves straight to the disk's own URL, and the plugin is not in the request path. The layer that covers every other SVG covers public SVG not at all.

The alternatives were to refuse SVG on public placement as the plugin already refuses HTML, or to accept the leak and document it.

## Decision

Public placement runs a narrower sanitization pass, dropping embedded images, style blocks and links. Private placement does not. An SVG that loses an element to the narrow pass is refused, naming the element.

## Consequences

The plugin's own promise about a file type now depends on where the field puts it, which is a seam a reader will not expect and is the reason this is written down.

Legitimate SVGs are rejected on public fields: an embedded raster, a `<style>` block, a link. That cost is paid only where the content policy cannot reach, and never on private uploads, which are the majority. Refusing rather than silently stripping means the editor learns why, at the cost of a rejection they may not be able to fix without re-exporting.

Public SVGs stored before this decision are the one population no layer covers, since nothing is re-sanitized at rest. Re-uploading is the remedy.

The decision reverses cleanly the day public assets gain a way to carry response headers of their own; the narrow pass would then be redundant rather than wrong.
