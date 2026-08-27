# 09: Public Placement

**What to build:** a public asset resolves to the storage disk's own URL and never touches the Delivery route, so CDN and browser caching work.

**Blocked by:** 08

**Status:** ready-for-agent

- [ ] `$asset->url()` is the single supported way to get a URL, choosing the disk's own URL for public assets and the Delivery route for private ones
- [ ] The CDN base URL is the disk's `url` key, with `temporary_url` kept separate; the plugin adds no setting of its own
- [ ] A saving disposition is written onto the object where the earned-disposition rule says `attachment`, since the plugin is not in the public request path
- [ ] Attaching an existing asset never changes its disk, directory or visibility, and never promotes it to public
- [ ] The plugin assumes public placement is a foreign origin and never verifies the hostname
- [ ] Tests assert the URL shape for both visibilities and that no Delivery request occurs for a public asset
