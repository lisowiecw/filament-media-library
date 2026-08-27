# 05: SVG Sanitization

**What to build:** an SVG upload is sanitized before it is stored, or it is refused. A public SVG is held to a stricter standard and told exactly which element failed it.

**Blocked by:** 04

**Status:** ready-for-agent

- [ ] `enshrined/svg-sanitize` is a hard require, with a runtime probe kept as a fail-closed guard
- [ ] Three-way failure check on every sanitize: a `false` return, a thrown exception, or a resulting root element that is not `svg`
- [ ] `removeRemoteReferences(true)` always, on both passes
- [ ] Public placement runs a second, narrower pass whose allowlists drop `image`, `style` and `a`
- [ ] A public SVG that trips the narrow pass is refused, and the message names the offending element, found by diffing the two passes
- [ ] Only the sanitized bytes are ever written; an SVG that cannot be sanitized is never stored
- [ ] A sanitized SVG is its own thumbnail: no rasterization anywhere, and no derivative rows
- [ ] Nothing is re-sanitized at rest
- [ ] The GPL-2.0-or-later obligation is recorded for the README ticket
