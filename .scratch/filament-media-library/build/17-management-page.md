# 17: Management Page

**What to build:** an operator opts in and gets a page listing every asset with usage counts, housekeeping actions, and a health readout, gated so opting in does not expose the library to every panel user.

**Blocked by:** 15, 16

**Status:** ready-for-agent

- [ ] `MediaLibraryPlugin::make()->withLibraryManagement()` opts the `MediaAssetResource` in; without it the application gets only the picker
- [ ] A Filament table, not the picker grid, listing every asset including private ones, plus a trashed filter
- [ ] Actions: rename (name and alt), delete, force delete, restore, download, upload
- [ ] Explicitly absent: replace in place, change visibility, move disk or directory
- [ ] Renaming changes nothing about storage
- [ ] One usage resolver feeding a usage count column, a usage panel on the view page, and the force-delete confirmation
- [ ] An unattached filter with a grace-period-aware preset, and a bulk delete restricted to unattached assets older than the grace period
- [ ] Bulk delete and bulk restore, with per-row skips reported; no bulk force delete
- [ ] The page is gated by `viewAny`
- [ ] Disk and object key shown as a copyable field on the view page, and an object key pasted into search finds its asset
- [ ] `source` filterable, `import_source` and `mime_source` visible on the view page, with a `mime_source` facet
- [ ] A health readout carrying the failed, missing and stale derivative counts with a regenerate action
- [ ] The importer stays CLI-only and is never exposed as an action here
