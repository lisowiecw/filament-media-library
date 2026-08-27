# 16: Lifecycle and Cleanup

**What to build:** deleting a file is an explicit, recoverable, blocked-by-default act, and finding unused files is a report the operator asks for rather than something that happens to them.

**Blocked by:** 06

**Status:** ready-for-agent

- [ ] Detach touches only the attachment row
- [ ] Delete soft-deletes the record and queues removal of the backing object, using standard queue retries and landing in `failed_jobs` on exhaustion
- [ ] Delete is blocked by default when the asset is attached anywhere, showing the usage list; force delete overrides it
- [ ] Deleting or force-deleting an asset queues its derivatives for removal alongside the backing object
- [ ] Restoring an asset regenerates derivatives lazily rather than resurrecting them
- [ ] A report-only Artisan command listing unattached assets, with a configurable grace period defaulting to 30 days
- [ ] The command is not scheduled by default, so installing the package schedules nothing
- [ ] All lifecycle rules are package-global and not overridable per field
