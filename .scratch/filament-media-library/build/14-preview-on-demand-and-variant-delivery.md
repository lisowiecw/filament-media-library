# 14: Preview On Demand and Variant Delivery

**What to build:** opening an asset full size renders a 1600px preview, generated the first time somebody actually asks for it, and a private thumbnail is delivered through the same checked route as its parent without defeating browser caching.

**Blocked by:** 13

**Status:** ready-for-agent

- [ ] `preview` at 1600px longest edge, generated on its first actual request only, never eagerly
- [ ] The Delivery route gains a variant parameter, re-checking `view` once per request against the asset row it already loaded
- [ ] Derivative responses carry `Cache-Control: private, immutable`
- [ ] Derivative URL expiry is quantized to a configurable bucket boundary (default 6 hours), so the URL is byte-stable within its window and `immutable` actually hits; originals keep the 5-minute per-render signature
- [ ] A derivative is never public when its parent is private, and no presigned derivative URL is ever handed out
- [ ] The lightbox and the management page view panel both resolve through this path
- [ ] Tested at the HTTP seam, including byte-stability of a derivative URL within one bucket and its change across a boundary
