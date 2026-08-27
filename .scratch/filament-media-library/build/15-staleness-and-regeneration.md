# 15: Derivative Staleness and Regeneration

**What to build:** changing the derivative settings makes the existing renders detectably stale and refreshable on the operator's command, without blanking a grid or spreading cost across arriving traffic.

**Blocked by:** 13

**Status:** ready-for-agent

- [ ] A short `config_digest` on each derivative row covering the target edge and quality only, never format, threshold or encoder version
- [ ] A null digest means unknown, not stale, so upgrading the plugin marks nothing stale
- [ ] A stale derivative is still served silently
- [ ] The digest rides on the Delivery URL, so `immutable` caching survives an in-place overwrite
- [ ] The digest moves only after a successful write, and the old object is never deleted first
- [ ] `media:regenerate-derivatives` accepting `--missing`, `--failed`, `--stale`, a variant selector and `--dry-run`, obeying the same rate cap as lazy dispatch
- [ ] No automatic sweep and no lazy regeneration on render for staleness
- [ ] A stale count is exposed for the management page health display
