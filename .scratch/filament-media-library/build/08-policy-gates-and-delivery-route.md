# 08: Policy, Gates and the Delivery Route

**What to build:** a private asset reaches a browser only through one plugin-owned route that re-checks authorization on every single request. Forgetting to write a policy denies rather than allows.

**Blocked by:** 02, 03

**Status:** ready-for-agent

- [ ] `MediaAssetPolicy` with `viewAny`, `view`, `update`, `delete`, `forceDelete`, `detach`; rename reuses `update` and download reuses `view`
- [ ] `uploadMedia` and `attachMedia` gates receiving the user, host context and field name
- [ ] Everything fails closed except reads of a public asset
- [ ] One signed Delivery route per panel, registered inside that panel's middleware
- [ ] The route re-checks `view` on every hit, then redirects to `temporaryUrl()` or streams; a raw presigned URL is never handed to a browser
- [ ] Signed TTL from config, env-readable, defaulting to 5 minutes for originals
- [ ] Disposition is earned: `inline` only when the asset is not active content and its mime came from a stored header or a sniff, `?download=1` forces `attachment`, and `?download=0` is ignored for active content
- [ ] `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` on every Delivery response
- [ ] An asset that must render in place streams rather than redirects, so the policy header survives
- [ ] A per-request policy cache so a grid batch costs one evaluation per asset
- [ ] The route URL and name are documented as internal
- [ ] Tested at the HTTP seam with real requests
