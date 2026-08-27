# Define Media Library Management Page

Status: resolved
Type: grilling
Blocked by: 05, 06, 07

## Question

Ticket 05 requires a surface for delete, force delete and the usage list, and ticket 06 excluded all of them from the picker. What is that surface? Decide whether it ships as a Filament page, resource, or panel plugin registration; what it lists and how it differs from the picker's scoped grid; how rename, delete, force delete and the usage list are presented; whether orphan assets are surfaced there or only by the report-only Artisan command; and how the package registers it without forcing it on applications that only want the picker.

## Answer

**Registration.** The management surface is a Filament **Resource** (`MediaAssetResource`), not a bespoke page, and it is **opt-in**: `MediaLibraryPlugin::make()->withLibraryManagement()`. An application that only wants the picker registers the plugin and gets no management surface at all, honouring ticket 06's rejection of any design that makes the management page a prerequisite. Being a Resource buys the table builder, filters, bulk selection and authorization wiring for free.

**Table, not grid.** The Resource renders a Filament **table**: rows, sortable columns, a thumbnail column, bulk select, numbered pages. It is deliberately unlike the picker's faceted infinite-scroll grid (ticket 09), because the jobs differ: management is auditing and housekeeping, the picker is visual browsing. Sharing one component would make one of the two jobs worse.

**What it lists.** **Every Media Asset, unscoped**, including private assets no public field could ever offer, and soft-deleted ones behind a trashed filter. Ticket 07's rule stands unchanged: listing is never row-gated; `view` is checked only when content is delivered.

**Actions.** Rename (name + alt), delete, force delete, restore, download, upload. Explicitly **not** replace-file-in-place, change-visibility, or move-disk/directory: those mutate a shared asset's identity or Placement and would silently break every other attachment.

**Usage list, three surfaces, one resolver.** A sortable usage **count** column (the same source as ticket 09's in-use/unattached facet), a full usage **panel** on the asset view page, and the same list inside the force-delete confirmation.

**Unattached assets.** Surfaced as a **filter with a grace-period-aware preset**, not a second concept. The report-only Artisan command is unchanged and remains the headless/scheduled path.

**Bulk actions.** Bulk delete (subject to the same shared-reference block, reporting per-row skips) and bulk restore. **No bulk force delete**: force delete's entire safety story is reviewing one asset's usage list, and a bulk override reduces that to a checkbox.

**Rename `Orphan asset` → `Unattached asset`.** "Orphan" asserts that nothing references the asset, which the plugin cannot know, since a URL may sit in a sent email, a rich-text body, an export or a third-party system. "Unattached" asserts only what the plugin observes: zero *tracked* Attachments. It is evidence, not proof. Renamed now, before it lands in a config key (`orphan_grace_period`) and a command name.

**External references.** `$asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412')` writes the same Attachment row with a **null host**, costing one nullable host column on an existing table. The usage list then tells the truth ("Campaign #412" beside "BlogPost #17 (cover_image)"), and normal delete-blocking protects the emailed asset automatically, with no second mechanism and no new safety rule. Rejected: a bare keep-flag (carries no reason) and doing nothing (leaves bulk delete pointing at a mislabelled set).

An external reference is **not** an Attachment for field purposes: null `host_type`/`host_id`/`field_name`, excluded from every field-context query, so `HasMediaAttachments` never sees it. It participates in exactly two things: the usage list and the usage count (and hence ticket 09's in-use/unattached facet, in the picker as well as here).

**Revoking an external reference.** Creation is code-only (it needs the application's identifier and label); **revocation is available in the usage panel**, per external row, so an operator standing in front of a blocked delete can clear a stale campaign reference whose creating code no longer exists. Host-model attachment rows are never removable there, because that is detach, and it belongs on the host record.

**Deleting the unattached set.** The unattached preset stays bulk-deletable, but only for assets unattached longer than the configured grace period (ticket 05's default 30 days). Age is the plugin's only real evidence; the alternative is hand-deleting hundreds of assets, which operators stop doing.

**Page authorization.** `MediaAssetPolicy` gains **`viewAny`**, governing Resource access and navigation visibility, fail-closed like the rest, so opting the Resource in does not expose the whole library to every panel user. It gates the *page*, not rows.

**Importer stays CLI-only.** It takes a disk name, a column mapping and a `--copy` flag, is idempotent, and is run once or twice in a migration window by someone with shell access, not by the content editor this page serves. A button would need a form modelling every flag and would invite re-running against a live library. Recorded on the map as out of scope.
