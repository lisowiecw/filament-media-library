# Close the SVG External Reference Gap

Status: open
Type: grilling
Blocked by: 14

## Question

Ticket 14 established that `enshrined/svg-sanitize` does not strip external references the way ticket 13 assumed: `removeRemoteReferences` is off by default, its matcher misses unquoted `url(...)` and any `style="fill: url(...)"`, and `<image href="https://...">` survives regardless because `isHrefSafeValue()` allowlists `http://` and `https://` and `<image>` is an allowed tag. `<style>` and `<a>` also survive, so arbitrary CSS reaches the served file. The consequence is a privacy and tracking leak: an admin opening the library grid makes a third-party request carrying a referrer and an IP.

How does the plugin close it, and how far? The options ticket 14 surfaced, in increasing cost: enable `removeRemoteReferences(true)` and document the residual gaps; additionally narrow the tag and attribute allowlists with `setAllowedTags()` and `setAllowedAttrs()` to drop `image` and `style`, which closes both remaining vectors but rejects legitimate SVGs; or add a restrictive `Content-Security-Policy` header (`default-src 'none'; style-src 'unsafe-inline'; sandbox`) on the Delivery route, which neutralizes remote fetches and any script that got past the sanitizer without depending on the sanitizer being perfect.

Decide which of these ship, and settle what the CSP option implies beyond SVG: whether the header applies to every Delivery route response or only to inline-served images, and whether a public SVG (which never passes through the Delivery route at all, per ticket 07) can be covered by any of this or must simply be accepted as uncovered.
