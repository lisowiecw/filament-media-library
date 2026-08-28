# Spec: Filament Media Library Plugin

Status: ready-for-agent

Collapsed on 2026-08-27 from the wayfinder map at [map.md](map.md) and its 25 resolved decision tickets. Where this spec and a ticket disagree, the ticket is the primary source; where a ticket was amended by a later one, this spec states the amended position only. Vocabulary throughout is the project glossary in `CONTEXT.md`; the ten ADRs in `docs/adr/` are binding.

## Problem Statement

A Laravel application that uploads files through Filament today gets a per-field upload column: the bytes land under a hashed key, the row stores a path string, and the file belongs to that one field on that one record. Reusing a picture in a second place means uploading it again. Nobody can see what the application holds, nobody can tell whether deleting a file will break a page, and the only name a file has is the one the storage provider gave it.

Concretely, the people involved are stuck in these ways:

- A **content editor** cannot find a file they uploaded last month, cannot reuse it in a second field, and cannot tell a private file from a public one before publishing.
- A **field author** (the developer building a Filament resource) has to decide storage placement, validation and delivery separately on every field, and gets no reuse story at all.
- An **operator** has an object store full of hashed keys with no way back to a human-meaningful record, no idea which objects are unused, and no safe way to delete anything.
- A **developer migrating an existing application** has thousands of live hashed uploads referenced by columns on host tables, and any library that demands a rename or a move of those objects is unusable.
- Anyone serving user-supplied files from the panel's own origin is one uploaded HTML or SVG file away from stored XSS against a logged-in session.

## Solution

A reusable Laravel package, `lisowiecw/filament-media-library`, that gives a Filament panel a **Media Library**: plugin-owned, reusable Media Assets with human-readable names, and one field component that attaches them to any host model.

From each actor's point of view:

- The **content editor** gets `MediaPicker`, one field that shows what is currently attached and opens one library modal with a Library tab and an Upload tab. They drop a file anywhere (including on the inline trigger, which uploads and attaches without opening the modal), or they browse a faceted, infinitely scrolling grid of what the library already holds and click to select. Names are the names they typed. Every card says whether the asset is public or private. Removing an attachment detaches it and never destroys the file.
- The **field author** writes `MediaPicker::make('cover_image')->acceptedFileTypes(['image/*'])->disk('media')->directory('posts/covers')->visibility('public')` and is done: that call fixes both what uploads land as and what the grid offers. The host table gains no column; the field is virtual and the Attachment rows are the only copy of the fact. The host model reads its own media back through a `HasMedia` trait.
- The **operator** opts in to a `MediaAssetResource` management page listing every asset with usage counts, rename, delete, force delete, restore and an unattached-assets filter, plus Artisan commands for import, MIME re-resolution, derivative regeneration and tenant assignment.
- The **migrating developer** runs `media:import`, which registers existing hashed objects in place, never writing to the source disk, taking the legacy key verbatim as the asset's object key. Legacy and new uploads then differ only in how their keys happen to look.
- **Safety** is structural rather than advisory: private content only ever reaches a browser through a plugin-owned Delivery route that re-checks authorization on every request; active content is stored but never served inline; SVG is sanitized at upload and refused if it cannot be; public placement refuses active content outright because the plugin is not in that request path.

## User Stories

### Attaching and reusing (content editor)

1. As a content editor, I want one field that shows the files currently attached to this record, so that I can see the record's media without opening anything.
2. As a content editor, I want to open a library of files the application already holds, so that I can reuse a file instead of uploading it twice.
3. As a content editor, I want to click a card to select it and click again to deselect, so that selection is obvious and reversible.
4. As a content editor, I want a footer that always shows my live selection count and its order, so that I know exactly what confirming will attach.
5. As a content editor, I want to drop a file onto the field trigger without opening the modal, so that the common case of "add this one file" costs one gesture.
6. As a content editor, I want to drop files onto the Library tab body and the Upload tab as well, so that drop works wherever I happen to be pointing.
7. As a content editor, I want dropping several files on a single-selection field to use the first and warn me, so that a fumbled drop is not an error page.
8. As a content editor, I want to reorder a multiple-selection field by dragging, so that a gallery appears in the order I intend.
9. As a content editor, I want arrow controls as well as dragging, so that reordering works from the keyboard.
10. As a content editor, I want removing an attachment to detach it and never delete the file, so that removing a picture from one post cannot break another.
11. As a content editor, I want swapping the file in a single-selection field to leave the old asset alone, so that a replacement is not a destruction.
12. As a content editor, I want to be prevented from attaching the same asset twice to one field, so that a gallery cannot contain accidental duplicates.
13. As a content editor, I want the field label and the drop banner to state where uploads will land and with what visibility, so that I am never surprised by an image that turns out to be private.
14. As a content editor, I want every card and every attached item to carry a public or private badge, so that I can tell at a glance what publishing this record exposes.

### Finding things (content editor)

15. As a content editor, I want one search box over the library, so that I can find a file by typing part of anything I remember about it.
16. As a content editor, I want search to match across the readable name, the original filename, the alt text, the uploader and the object key, so that whichever fragment I remember gets me there.
17. As a content editor, I want multiple search words to narrow rather than widen, so that "hero 2024" finds the 2024 hero rather than everything from 2024.
18. As a content editor, I want my matched text highlighted on the card, so that I can see why a result matched.
19. As a content editor, I want a facet sidebar for type, visibility, usage, uploader and upload date, so that I can narrow without learning a query syntax.
20. As a content editor, I want facet counts that describe what clicking would yield, so that I never click into an empty grid.
21. As a content editor, I want the type facet derived from the field's own accepted types, so that a field that only takes images does not offer me a video filter.
22. As a content editor, I want a "not attached anywhere" filter in the picker, so that finding an unused file is a normal browsing task.
23. As a content editor, I want to sort by newest, oldest, name or most used, so that I can browse the way the task suits.
24. As a content editor, I want the grid to keep loading as I scroll, plus an explicit "Load more" button, so that browsing is continuous and still keyboard-operable.
25. As a content editor, I want to be told when a filter change discards my selection, so that a reset is never silent.
26. As a content editor, I want cards to paint a blurred placeholder while their thumbnail is loading, so that a scrolling grid does not look broken.
27. As a content editor, I want an end-of-library marker stating the total, so that I know when I have seen everything.

### Names (content editor)

28. As a content editor, I want a file's readable name to start as the filename I uploaded with its extension removed, so that it is recognisable without me typing anything.
29. As a content editor, I want to rename a file afterwards, so that "IMG_4471" can become "Team photo, Berlin office".
30. As a content editor, I want renaming to change nothing about where the file is stored, so that renaming can never break a live URL.
31. As a content editor, I want my name kept in its own script rather than transliterated, so that a name I typed in Japanese stays in Japanese.
32. As a content editor, I want underscores, hyphens and my own capitalisation preserved, so that a part number or "iPhone" survives upload.
33. As a content editor, I want to be told when my upload's name matches an existing one and offered "create new asset" or "cancel", so that I can decide rather than have the system guess.
34. As a content editor, I want a name collision to never block or overwrite anything, so that two files may legitimately share a name.

### Building forms (field author)

35. As a field author, I want a single field component for both single and multiple selection, so that I learn one API.
36. As a field author, I want the field to be virtual with no host column, so that a host migrating off a legacy path column simply drops it.
37. As a field author, I want the field's value to always be an ordered array of asset ids whatever the cardinality, so that my rules, casts and `afterStateUpdated()` hooks do not fork on a config call.
38. As a field author, I want the array index to be the attachment order, so that the ordering rule is the same one the importer uses.
39. As a field author, I want `->acceptedFileTypes()` to gate uploads and scope the grid at once, so that a field cannot offer something it would refuse.
40. As a field author, I want `->disk()`, `->directory()` and `->visibility()` to describe upload placement only and never to scope the library, so that a cover image can be reused in a gallery.
41. As a field author, I want `->maxSize()` to override the package default in either direction, so that an avatar field and a video field can both be right.
42. As a field author, I want `->multiple()`, `->reorderable()` and `->maxItems()`, so that a gallery field is one line of configuration.
43. As a field author, I want `->droppable(false)`, so that a reuse-only field cannot receive an upload.
44. As a field author, I want `->scopeLibrary()` as a narrowing escape hatch, so that an unusual topology (several public buckets) is expressible without a new mechanism.
45. As a field author, I want `->thumbnailUsing()`, so that I can override how one field resolves previews.
46. As a field author, I want `->modalWidth()` and `->defaultTab()`, so that the modal fits the surface it opens on.
47. As a field author, I want cardinality validation rules (`required`, `minItems`, `maxItems`) over the id array, so that "a post needs a cover" is expressible where I write my other rules.
48. As a field author, I want a save containing an id the viewer cannot have to fail the whole save naming the field, so that a partial save never quietly drops images.
49. As a field author, I want the validation message to never name the offending asset id, so that a cross-tenant probe learns nothing.
50. As a field author, I want attachment writes deferred until after the host record is persisted, so that an abandoned create form leaves no debris.
51. As a field author, I want saving to diff against existing rows rather than delete and reinsert, so that attachment identity and `created_at` stay meaningful.
52. As a host model author, I want a `HasMedia` trait with `media(string $field)` and `firstMedia(string $field)`, so that I can read my own attachments without hardcoding a pivot table.
53. As a host model author, I want the trait to exclude soft-deleted assets, so that I never render a URL whose object is queued for deletion.

### Storage placement (field author, operator)

54. As an operator, I want the plugin to reach storage only through Laravel filesystem disks, so that swapping S3 for R2 for local is a config change.
55. As an operator, I want one Laravel disk per bucket, so that the plugin needs no bucket setting of its own.
56. As an operator, I want new objects to default to private, so that the unsafe direction is never the default.
57. As an operator, I want new objects to default under a `media` prefix, so that the plugin's writes are identifiable in a shared bucket.
58. As an operator, I want the asset row to record disk, object key and visibility, so that every storage operation resolves from the row rather than from convention.
59. As an operator, I want object keys server-generated, opaque and independent of user input, so that a readable name can never reach a storage path.
60. As an operator, I want the key's extension to follow the sniffed bytes while the stored extension follows the client name, so that the provider sees the truth and the human sees what they typed.
61. As an operator, I want attaching an existing asset to never change its disk, directory or visibility, so that reuse cannot alter delivery for every other attachment.
62. As an operator, I want no CDN base URL setting in the plugin, so that there is exactly one place (the disk's own `url`) that answers where public assets come from.

### Delivery and authorization

63. As a security-minded developer, I want every private asset served through one plugin-owned route, so that there is one place that authorization can be enforced.
64. As a security-minded developer, I want that route to re-check `view` on every single request, so that a URL that leaks stops working when the policy says so.
65. As a security-minded developer, I want no raw presigned URL ever handed to a browser, so that access is revocable.
66. As a security-minded developer, I want public assets to bypass the route entirely and resolve to the disk's own URL, so that CDN and browser caching are not thrown away.
67. As a security-minded developer, I want one `MediaAssetPolicy` with `viewAny`, `view`, `update`, `delete`, `forceDelete` and `detach`, so that authorization lives where Laravel developers expect it.
68. As a security-minded developer, I want `uploadMedia` and `attachMedia` gates receiving the user, host context and field name, so that the two actions preceding an asset's existence are still gated.
69. As a security-minded developer, I want every mutating and private-content action to deny by default until I write a policy, so that forgetting to configure the plugin fails closed.
70. As a security-minded developer, I want grid listing never gated per row, so that authorization does not become an N+1 problem duplicated in query scopes.
71. As a security-minded developer, I want rename to reuse `update` and download to reuse `view`, so that there are no extra ability names to keep in sync.
72. As a security-minded developer, I want a signed TTL from config defaulting to five minutes for originals, so that a copied URL expires quickly.
73. As a security-minded developer, I want derivative URL expiry quantized to a bucket boundary (six hours by default), so that `immutable` caching actually hits without weakening the per-request check.
74. As a security-minded developer, I want a restrictive content policy on every Delivery response, so that a served file cannot fetch anything it references.
75. As a security-minded developer, I want an asset that must render in place to stream rather than redirect, so that the content policy survives.
76. As a security-minded developer, I want `uploaded_by` recorded whenever an upload is authenticated, so that I can write "uploader or admin" policies without inventing my own tracking.
77. As a security-minded developer, I want the uploader to imply no authority of its own, so that provenance is never mistaken for ownership.

### Upload validation and active content

78. As an operator, I want a package-global size default that a field can override, so that one sensible number covers most fields.
79. As an operator, I want a boot-time warning when a configured size exceeds the PHP or Livewire ceiling, so that I do not debug a browser-level failure with no message.
80. As an operator, I want a package-global blocked-types denylist that a field can only narrow, so that "arbitrary file types" stays the promise while `.phar` never gets in.
81. As an operator, I want denylist matching on both extension and resolved MIME, so that neither alone can be evaded.
82. As an operator, I want every upload sniffed from the bytes, so that the browser's claim is never what gets stored.
83. As an operator, I want a declared/sniffed mismatch to re-run the gate against the truth rather than error on the disagreement, so that routine cases (`.csv` sniffing as `text/plain`) are not false alarms.
84. As an operator, I want a rejection message naming both the declared and the sniffed type, so that a refusal is diagnosable.
85. As an operator, I want a sniffed type in a different top-level family than the extension refused even on a permissive field, so that deliberate deception is caught.
86. As an operator, I want active content stored but served only as a download, so that a downloads field is possible and stored XSS is not.
87. As an operator, I want `?download=0` ignored for active content, so that the rule cannot be talked out of.
88. As an operator, I want SVG sanitized at upload and refused if it cannot be sanitized, so that no unsanitized SVG is ever stored.
89. As an operator, I want a sanitized SVG treated as an ordinary inline image and used as its own thumbnail, so that no rasterizer SVG frontend enters the pipeline.
90. As an operator, I want remote references stripped from every SVG, so that the most common tracking vector is closed at ingest.
91. As an operator, I want a narrower sanitization pass on public placement, dropping embedded images, style blocks and links, so that the population the Delivery route cannot reach is covered.
92. As an operator, I want a public SVG failing the narrow pass refused by name of the offending element, so that nothing is silently stripped and nothing is silently downgraded.
93. As an operator, I want active content refused at upload on a public field with a message stating why, so that placement is never silently changed under the field author.
94. As an operator, I want an asset served in place only when it is not active content and its MIME came from a stored header or a sniff, so that disposition is a statement of what is known.
95. As an operator, I want a config change to never reject, hide or delete a stored asset, so that tightening the rules is safe.
96. As an operator, I want tightened serving rules to apply immediately to everything, so that safety improvements do not wait for a backfill.
97. As an operator, I want a blocked-type asset never offered in a picker, so that the grid does not invite attaching something the application has declared unwanted.

### Stored headers and public delivery

98. As an operator, I want every upload to write its content type from the sniffed bytes onto the storage object, so that a public GET serves the right type.
99. As an operator, I want a saving disposition written onto the object where the earned-disposition rule says so, so that the rule survives outside the Delivery route.
100. As an operator, I want a long immutable cache instruction written onto every object, so that a public original is cached properly at the edge and in the browser.
101. As an operator, I want the plugin to assume public placement is a foreign origin and never to check, so that it does not guess wrong in exactly the deployments that have the problem.
102. As an operator, I want the README to state the foreign-host obligation plainly, so that I know it is mine.
103. As an operator, I want the README to name a concrete edge content policy for public buckets and to say it needs a custom domain, so that I can cover the stored public SVG residual myself.

### Derivatives

104. As a content editor, I want image cards to show a real preview rather than a type glyph, so that browsing is visual.
105. As a content editor, I want a lightbox and management view showing a larger rendering, so that I can check an image before attaching it.
106. As a content editor, I want video cards to show a glyph tile with a play badge, so that a video is distinguishable from a still.
107. As a content editor, I want a pending or missing preview to render as a quiet dimmed tile with no spinner, so that a grid of 48 does not become a progress display.
108. As an operator, I want two fixed derivative variants (a 400px thumb and a 1600px preview), so that the key space is predictable.
109. As an operator, I want dimensions and quality configurable but the variant set fixed, so that every installation's matrix is the same shape.
110. As an operator, I want derivatives stored as child rows rather than as Media Assets, so that they never leak into a picker grid or a management table.
111. As an operator, I want a derivative to inherit its parent's disk and visibility, so that a private thumbnail is never public.
112. As an operator, I want derivatives keyed by asset and variant so they are removable by prefix and immutable by construction, so that regeneration overwrites in place.
113. As an operator, I want the thumb generated eagerly and queued on upload, so that a fresh upload has a card ready.
114. As an operator, I want the preview generated only on first actual request, so that assets nobody opens full size cost nothing.
115. As an operator, I want generation never to run inline in a web request, so that an upload does not block on image processing.
116. As an operator, I want a render miss to dispatch the job and show the pending tile, so that imports, failed jobs and newly configured dimensions all self-heal through one path.
117. As an operator, I want lazy backfill dispatch rate-capped by config, so that the first person to browse a freshly imported library does not trigger a job stampede.
118. As an operator, I want a small browser-renderable raster under a configurable ceiling to get no derivatives at all, so that logos and icons cost nothing.
119. As an operator, I want exhausted failures to stick as failed and stop re-dispatching, so that a broken file is not retried forever.
120. As an operator, I want failures surfaced as a health count with a regenerate action, so that I can find and fix them.
121. As an operator, I want deleting an asset to queue its derivatives for removal too, so that nothing is stranded.
122. As an operator, I want restoring an asset to regenerate derivatives lazily rather than resurrect them, so that restore has no second cleanup story.
123. As an operator, I want no part of the plugin to depend on an optional binary, so that installation is a Composer install.

### Stale derivatives

124. As an operator, I want each derivative to record a digest of the settings that produced it, so that staleness is detectable by comparison rather than inspection.
125. As an operator, I want the digest to cover only the target edge and quality, so that an encoder upgrade does not mark a whole library stale.
126. As an operator, I want an unknown digest to mean unknown rather than stale, so that upgrading the plugin marks nothing stale.
127. As an operator, I want a stale derivative still served silently, so that a settings change never blanks the grid.
128. As an operator, I want `media:regenerate-derivatives --stale` with `--dry-run`, so that refreshing is an explicit act with a preview.
129. As an operator, I want no automatic sweep and no lazy regeneration on render, so that one config edit does not spread its cost invisibly across arriving traffic.
130. As an operator, I want the digest carried in the Delivery URL, so that immutable caching survives an in-place overwrite.
131. As an operator, I want the digest to move only after a successful write, and the old object never deleted first, so that a failed regeneration leaves a working asset.
132. As an operator, I want a stale count on the management page, so that I know a refresh is available without running a command.

### Management page (operator, library administrator)

133. As an application developer, I want the management page to be opt-in via `->withLibraryManagement()`, so that an application wanting only the picker gets only the picker.
134. As a library administrator, I want a table with sortable columns rather than the picker's grid, so that auditing and browsing are each served by the right shape.
135. As a library administrator, I want every asset listed including private ones and, behind a filter, soft-deleted ones, so that the page tells the whole truth.
136. As a library administrator, I want rename, delete, force delete, restore, download and upload actions, so that housekeeping is possible from the panel.
137. As a library administrator, I want replace-file, change-visibility and move-disk explicitly absent, so that no action can silently break every other attachment.
138. As a library administrator, I want a usage count column, a usage panel on the asset page and the same list inside a force-delete confirmation, so that one resolver answers "what uses this" everywhere.
139. As a library administrator, I want delete blocked by default when the asset is used, so that deleting cannot break a page by accident.
140. As a library administrator, I want force delete to require reviewing the usage list, so that the override is deliberate.
141. As a library administrator, I want bulk delete and bulk restore but no bulk force delete, so that the safety story is not reduced to a checkbox.
142. As a library administrator, I want bulk delete to report per-row skips, so that a partial result is legible.
143. As a library administrator, I want an unattached filter with a grace-period preset, so that cleanup candidates are found without a command.
144. As a library administrator, I want to bulk delete only unattached assets older than the grace period, so that a hasty sweep cannot catch a fresh upload.
145. As a library administrator, I want the disk and object key shown as a copyable field on the asset page, so that I can go from an asset to a bucket object.
146. As a library administrator, I want to paste an object key into search and find its asset, so that I can go the other way too.
147. As a library administrator, I want the page gated by `viewAny`, so that opting the resource in does not expose the library to every panel user.
148. As a library administrator, I want `source` filterable and `import_source` and `mime_source` visible on the asset page, so that an imported asset's differences are legible.
149. As a library administrator, I want provenance never exposed as a picker facet, so that the editor's surface stays about choosing a file.

### External references

150. As an application developer, I want to record that something outside any host model uses an asset, so that a campaign or an export is not invisible.
151. As an application developer, I want an external reference to be an attachment with a null host, so that it blocks deletion through the existing mechanism rather than a second one.
152. As an application developer, I want external references excluded from every field-context query, so that `HasMedia` never sees them.
153. As a library administrator, I want to revoke an external reference from the usage panel, so that a stale campaign reference whose creating code is gone does not block a delete forever.
154. As a library administrator, I want host-model attachment rows not removable from that panel, so that detaching stays on the host record where it belongs.

### Lifecycle

155. As an application developer, I want detach to touch only the attachment row, so that its meaning is unambiguous.
156. As an application developer, I want delete to soft-delete the record and queue the object's removal, so that a mistake is recoverable for a window and the bucket is still cleaned.
157. As an application developer, I want the object-removal job to use standard queue retries and land in `failed_jobs` on exhaustion, so that there is no bespoke failure tracking to learn.
158. As an operator, I want a report-only unattached-assets command, so that cleanup is something I decide rather than something that happens.
159. As an operator, I want that command not scheduled by default, so that installing the package schedules nothing.
160. As an operator, I want a configurable grace period defaulting to 30 days, so that "unattached" means "unattached for a while".
161. As an application developer, I want lifecycle rules to be package-global and not per-field overridable, so that safety cannot be switched off one field at a time.

### Import and migration

162. As a migrating developer, I want import to register existing objects in place and never write to the source disk, so that running it cannot damage live files.
163. As a migrating developer, I want the legacy key taken verbatim as the object key, so that every existing URL keeps working.
164. As a migrating developer, I want an explicit opt-in `--copy` mode that asserts the destination is missing and never deletes the source, so that consolidating a prefix is possible and never lossy.
165. As a migrating developer, I want no move mode at all, so that there is no way to ask for the destructive version.
166. As a migrating developer, I want discovery driven by a declared host model, column, disk and field context, so that ownership and field context come from the row that actually knows them.
167. As a migrating developer, I want disk traversal available as an explicitly degraded fallback requiring a prefix, so that a column-less legacy layout is still importable.
168. As a migrating developer, I want traversal to iterate lazily, so that a large bucket does not exhaust memory.
169. As a migrating developer, I want the legacy basename recorded as the original filename and its stem as the display name, so that imported assets are as readable as the legacy layout allows.
170. As a migrating developer, I want an unknown disk to fail the run hard, so that no row ever names a bucket the plugin cannot read.
171. As a migrating developer, I want an unknown uploader left null, so that provenance is not fabricated.
172. As a migrating developer, I want visibility never read from an S3-driver disk, so that the run does not raise on a provider that leaves that call unimplemented.
173. As a migrating developer, I want identity to be a unique disk and object key pair created with `firstOrCreate`, so that re-runs are idempotent and my later edits survive them.
174. As a migrating developer, I want declared cardinality with a hard failure on a shape mismatch in either direction, so that a single-value column and an array column cannot be confused.
175. As a migrating developer, I want array index order taken as attachment order verbatim, so that a gallery's order survives the migration.
176. As a migrating developer, I want in-array duplicates and missing or empty elements skipped and reported, so that a messy legacy column does not stop the run.
177. As a migrating developer, I want a nested object or a URL in the column to fail the run, so that a shape the tool cannot honestly handle is refused rather than guessed.
178. As a migrating developer, I want attachment idempotency on host, field and asset, and an existing order never rewritten, so that a second run does not undo a human's reordering.
179. As a migrating developer, I want ingest rules (size, mismatch refusal) not applied to import, so that no host is left pointing at an asset that was skipped.
180. As a migrating developer, I want the denylist applied to import as well, reporting a blocked row by path, so that a `.phar` is never adopted.
181. As a migrating developer, I want an import report as both a console summary and a machine-readable file under `storage/logs/`, so that I can diff two runs.
182. As a migrating developer, I want the report to name omissions rather than successes, so that it is short enough to read.
183. As a migrating developer, I want `--sniff` as an opt-in flag alongside a standalone re-resolution pass, so that a slow, expensive fetch is my choice.
184. As a migrating developer, I want `media:resolve-mimes` defaulting to the extension rung and requiring `--sniff` to fetch bytes, so that the expensive operation is never implicit.
185. As a migrating developer, I want MIME resolution to write type and rung together, so that a row never claims a rung it did not come from.
186. As a migrating developer, I want MIME resolution never to happen lazily on the Delivery route, so that a read path never performs a write and a fetch.
187. As a migrating developer, I want a `mime_source` facet on the management page rather than a banner, so that I can find the un-sniffed population myself.
188. As a migrating developer, I want the documentation to name the sniff pass as the second step of migration, so that I know why a fresh import renders as downloads.

### Tenancy

189. As a multi-tenant application developer, I want tenancy to enter through one `->tenantUsing()` resolver on the panel plugin, so that there is exactly one place to configure it.
190. As a multi-tenant application developer, I want an unset resolver to mean the plugin is not tenanted, so that single-tenant applications are untouched.
191. As a multi-tenant application developer, I want the resolver defaulted to the panel's own tenant where the panel has tenancy, so that the common case is zero configuration.
192. As a multi-tenant application developer, I want the resolver on the panel instance rather than in package config, so that a tenanted panel and an untenanted panel can coexist.
193. As a multi-tenant application developer, I want a plugin-owned nullable indexed string tenant column, so that a UUID tenant needs no migration change.
194. As a multi-tenant application developer, I want the tenant stamped once at upload and never reassigned between tenants, so that a usage list is always honest about who could reach an asset.
195. As a multi-tenant application developer, I want the query scope to decide what is offered and the policy to decide what is delivered, so that route-model binding cannot sail past the boundary.
196. As a multi-tenant application developer, I want a cross-tenant delivery request to return 404, so that ids cannot be probed for existence.
197. As a multi-tenant application developer, I want a null tenant to belong to no one rather than to everyone, so that configuring tenancy does not publish a two-year-old library to every customer.
198. As a multi-tenant application developer, I want claiming an untenanted asset to be one way and allowed once, so that ownership never moves.
199. As a multi-tenant application developer, I want claiming available both as a command and as a bulk action on the unscoped listing, so that the untenanted pile is fixable.
200. As a multi-tenant application developer, I want the management page scoped by default with the unscoped view behind a fail-closed `viewAllTenants`, so that opting in does not create a cross-tenant listing.
201. As a multi-tenant application developer, I want attaching to refuse on a tenant mismatch, so that a programmatic attach cannot bypass the grid's scope.
202. As a multi-tenant application developer, I want an existing mismatched attachment to degrade to a dimmed tile, so that the day tenancy is configured is not the day every form errors.
203. As a multi-tenant application developer, I want the plugin never to inspect the host model's tenancy, so that it does not have to know how my application is tenanted.
204. As a multi-tenant application developer, I want the Delivery route registered per panel inside that panel's middleware, so that the resolver evaluates in the same context the picker did.
205. As a multi-tenant application developer, I want jobs and commands neither scoped nor policy-checked, so that derivative generation does not silently stop for tenanted assets.
206. As a multi-tenant application developer, I want the importer to require a `--tenant` option accepting a literal `none`, so that no new untenanted rows appear by accident.

### Packaging and upgrades

207. As an application developer, I want a normal Laravel package with a service provider, config, migrations and a Filament plugin class, so that installation is familiar.
208. As an application developer, I want PHP 8.3, Laravel 13 and Filament 5 as the guaranteed platform, so that I know what is supported.
209. As an application developer, I want Filament 4 supported on the same Composer line and proven by a CI matrix, so that the constraint is honest.
210. As an application developer, I want a red Filament 4 job to block a release, so that support cannot lapse silently under an unchanged constraint.
211. As an application developer, I want a README compatibility table generated from the CI matrix, so that drift is visible.
212. As an application developer, I want the plugin to declare only its three real constraints and to reach storage through Laravel's contracts, so that no Flysystem or AWS SDK version is pinned by the plugin.
213. As an application developer, I want a clear promised surface (plugin class and fluent config, `MediaPicker`, `HasMedia`, the `MediaAsset` model, ability and gate names, config keys, command signatures), so that I know what will survive an upgrade.
214. As an application developer, I want the Delivery route, view names, queue payloads, derivative key layout and the rest of the schema documented as internal, so that I do not build on sand.
215. As an application developer, I want `$asset->url()` as the supported way to get a URL, so that no template hardcodes the internal route.
216. As an application developer, I want an `UPGRADING.md` defining breaking by behaviour rather than by signature, so that a "harmless" denylist addition is treated as what it is.
217. As an application developer, I want the launch at `0.1.0`, so that every release is visibly breaking until the package has survived one real upgrade.

## Implementation Decisions

### Package shape

- Composer package `lisowiecw/filament-media-library`; PHP namespace `Lisowiecw\MediaLibrary\`; config published as `config/media-library.php`; view and translation namespace `media-library::`.
- Declared requirements only: `php ^8.3`, `laravel/framework ^13.0`, `filament/filament ^4.0|^5.0`, plus `enshrined/svg-sanitize ^0.22` and `spatie/laravel-package-tools ^1.93`, the latter because the Filament plugin docs build the service provider on its `PackageServiceProvider`. Storage is reached solely through the `Storage` facade and `Illuminate\Contracts\Filesystem`, so no Flysystem or AWS SDK constraint is declared. The package is GPL-relevant through the sanitizer, noted in the README.
- Registration is a Filament plugin implementing `Filament\Contracts\Plugin`, registered with `->plugin(MediaLibraryPlugin::make())`. Fluent configuration on the plugin instance: `->withLibraryManagement()`, `->tenantUsing()`.
- Launch version `0.1.0`. `UPGRADING.md` carries the four behavioural breaking-change rules from ticket 19.

### Schema

Three tables, unprefixed and with no prefix knob.

- **`media_assets`**: identity (`id`, `ulid`), naming (`display_name` not null, `original_client_filename`, `extension`, `alt`), type (`mime_type`, `mime_source` enum of `header`/`sniffed`/`extension`/`unknown`), bytes (`size`), storage (`disk`, `object_key`, `visibility`), provenance (`source` enum of `upload`/`import` not null, `import_source` nullable string on the `host.column` convention, `uploaded_by` nullable), tenancy (`tenant_id` nullable indexed string), rendering (`blurhash` nullable string), timestamps and `SoftDeletes`. Unique index on `(disk, object_key)`.
- **`media_attachments`**: `media_asset_id`, nullable `host_type`/`host_id`/`field_name` (all null together for an External reference, which additionally carries an identifier and a label), `order`, timestamps. Unique on `(host_type, host_id, field_name, media_asset_id)` for host rows.
- **`media_derivatives`**: `media_asset_id`, `variant` (`thumb`/`preview`), `disk`, `object_key`, `width`, `height`, `bytes`, `status` (`pending`/`ready`/`failed`) with a failure reason, `config_digest` nullable, timestamps. Key layout `<derivatives-prefix>/<asset-ulid>/<variant>.webp`.

The schema beyond the columns the model exposes is internal.

### Modules

- **Ingest service** (its own module, the chosen seam). One entry point taking an uploaded file plus a resolved Placement and returning a `MediaAsset`. It runs, in order: name scrub, sniff, denylist and accepted-type re-gate against the sniffed truth, family-mismatch refusal, SVG sanitization (strict pass on public placement), public-placement active-content refusal, key generation, write with Stored headers, tenant stamp, `uploaded_by` stamp, thumb job dispatch (unless the small-original rule applies). Both the picker's upload path and the management page's upload action call it. The importer deliberately does not: it adopts rather than ingests, and only shares the denylist.
- **Placement** value object: disk, directory, visibility, resolved from field configuration over package config. Fixed on the asset at upload, never re-applied by attaching.
- **Name algorithm** module: basename reduction, control and bidi stripping, NFC, trim, 255-byte cap on the original filename; extension strip, whitespace collapse, 255-character cap, non-empty fallback on the display name; NFC plus case-fold plus whitespace-collapse comparison for a library-wide collision check that informs and never blocks.
- **Attachment reconciliation** module: the diff invoked after the host record is persisted. Attach new, detach missing, rewrite `order` only where it differs, no-op on equality.
- **Offer scope**: the query that decides what a picker grid lists (accepted-type match, plus public or the field uploads private, minus blocked types, minus the tenant scope, then the field's own `->scopeLibrary()` narrowing).
- **Delivery**: one route per panel, registered inside that panel's middleware, taking an asset and an optional variant. Re-checks `view`, 404s on tenant mismatch, chooses stream or redirect from the disposition rule, sets `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` on every response, quantizes derivative expiry to the configured bucket.
- **Derivative pipeline**: a queued generation job per variant, a lazy dispatch path behind a rate cap, a digest computed from target edge and quality only, and `media:regenerate-derivatives` with `--missing`, `--failed`, `--stale`, a variant selector and `--dry-run`.
- **Filament surfaces**: `MediaPicker` field, the library modal Livewire component (Library and Upload tabs, facet sidebar, infinite scroll), and the opt-in `MediaAssetResource`.
- **Commands**: `media:import`, `media:resolve-mimes`, `media:regenerate-derivatives`, `media:assign-tenant`, and the report-only unattached-assets command. Only `media:import` and `media:resolve-mimes` were promised as stable by ticket 19; the other three are documented as stable in this spec's release notes only if ticket 19's list is amended, so treat their signatures as promised from `0.1.0` onward and record the amendment in `UPGRADING.md`.

### Interfaces promised to consumers

```php
MediaPicker::make('cover_image')
    ->label('Cover image')
    ->acceptedFileTypes(['image/*'])
    ->disk('media')->directory('posts/covers')
    ->visibility('public')
    ->maxSize(2048);

MediaPicker::make('gallery')
    ->multiple()->reorderable()->maxItems(12)
    ->acceptedFileTypes(['image/*', 'application/pdf', 'video/mp4'])
    ->visibility('private')
    ->droppable(false)
    ->modalWidth('7xl')
    ->defaultTab('library')
    ->thumbnailUsing(fn (MediaAsset $a) => $a->previewUrl())
    ->scopeLibrary(fn (Builder $query) => $query->where('disk', 'archive'));

class BlogPost extends Model
{
    use HasMedia; // media(string $field): Collection, firstMedia(string $field): ?MediaAsset
}
```

The field's value is always `int[]`, ordered, in both directions. `$data['cover'][0] ?? null` is the documented single-value read. The host table carries no media column.

### Authorization contract

`MediaAssetPolicy` abilities: `viewAny`, `view`, `update`, `delete`, `forceDelete`, `detach`, `viewAllTenants`. Gates: `uploadMedia`, `attachMedia`. All fail closed except reads of a public asset, which require no check beyond the panel's own auth. Listing is never row-gated; `view` is checked only where content is delivered.

### Config keys (package-global unless noted)

Default disk override, default directory prefix, default visibility (private), `max_upload_size` (12 MB, field-overridable), `blocked_types`, signed TTL for originals (5 minutes), derivative URL quantization bucket (6 hours), derivative dimensions and quality, derivatives prefix, small-original byte ceiling (32 KB) and edge ceiling (800px), lazy-dispatch rate caps, search debounce (400ms), facet-count threshold (50,000 rows), unattached grace period (30 days).

### Decisions carried from ADRs

ADR-0001 (private media always through the plugin route), ADR-0002 (external references are host-less attachments), ADR-0003 (derivatives are child rows), ADR-0004 (disposition is earned), ADR-0005 (public SVG is sanitized more strictly), ADR-0006 (the readable extension follows the client name), ADR-0007 (tenancy is a policy boundary), ADR-0008 (Filament 4 rides one line guarded by CI), ADR-0009 (public media is a foreign origin by deployment), ADR-0010 (the picker field is virtual) are all binding and none are reopened here.

### Filament 4

The Filament 4 half of the matrix is a single ticket near the end of the build, not an acceptance criterion on every slice. One test suite runs against both majors; any v4 adapter shim is isolated behind the shared field and plugin APIs, per ticket 01.

## Testing Decisions

A good test here asserts external behaviour: what a content editor sees in the grid, what rows exist after a save, what status code and headers a request gets back, what a command wrote and reported. It does not assert that a particular service was called, that a job class was constructed, or that a private method ran. Job dispatch is asserted as an outcome (`Queue::fake()` plus a bus assertion) only where the dispatch itself is the promised behaviour, such as the rate-capped lazy backfill and the eager thumb.

Stack: **Pest**, **Orchestra Testbench** hosting a real Filament panel with a fixture host model, and **`Storage::fake()`** for the disk. No real R2 in CI: ticket 25's stored-header confirmation was a deliberate one-off manual observation against a real bucket, and re-running it on every push would buy flakiness rather than confidence. The README records the observation and the date.

Four seams, in descending preference:

1. **Filament component seam** (highest, and the default). Livewire component tests against the `MediaPicker` mounted in a fixture form and against `MediaAssetResource`. Covers fill and hydrate order, selection, upload, reorder, detach, the save diff, duplicate prevention, cardinality validation, the rejected save on an unavailable id, offer rules, search and facet behaviour, infinite scroll batching, selection reset on filter change, and every management-page action including the usage panel and the force-delete confirmation.
2. **HTTP seam**. Real requests to the Delivery route: `view` re-check on every hit, 403 within tenant against 404 cross-tenant, inline against attachment disposition, the active-content override of `?download=0`, the variant parameter, the content policy header on every response, streaming rather than redirect where the policy must survive, and byte-stability of a derivative URL within its quantization bucket.
3. **Ingest seam**. Direct tests of the ingest service, chosen because it is where the rule density is highest (tickets 13, 14, 15, 16, 23) and driving each branch through Livewire would be slow and indirect. Covers the name scrub table, sniff and re-gate, the family-mismatch refusal, SVG sanitize and its three-way failure check, the strict pass and its named-element refusal, the public active-content refusal, stored headers written on the object, the tenant stamp, and the small-original skip.
4. **Artisan seam**. Commands driven end to end against a faked disk and a seeded legacy schema: import in place and `--copy`, idempotency on re-run, cardinality mismatch failure, array order and skip reporting, hard failure on unknown disk, the report file's contents, `media:resolve-mimes` rung transitions, `media:regenerate-derivatives` selectors including `--stale` and `--dry-run`, `media:assign-tenant` claiming and its refusal to move, and the unattached report.

Prior art: none in this repo, since it holds no code yet. The two named references are the Filament plugin testing conventions (Livewire component tests over a Testbench panel) and Laravel's own package testing conventions for commands and storage fakes. The first implemented ticket therefore also establishes the harness, and every later ticket follows the pattern it sets.

Two behaviours are explicitly not covered by automated tests, and both are recorded rather than silently skipped: R2's public-GET header behaviour (manual, ticket 25) and edge content policy configuration on a custom domain (operator obligation, ADR-0009).

## Out of Scope

- Resource-specific consumer implementation. The blog-post example exists only as a fixture and a README sample.
- Automatic renaming, re-keying or migration of existing hashed objects without an explicit command invocation. There is no move mode anywhere.
- Provider-specific storage APIs outside the Laravel filesystem contract. No R2 ACL calls, no AWS SDK usage, no CDN base URL setting.
- Exposing the importer as a Filament action. It stays CLI-only.
- Reaching into nested array elements during import. A nested object or a URL in a legacy column fails the run.
- Video poster frames, video duration, and any dependency on an external binary such as `ffmpeg`.
- Rasterizing SVG. A sanitized SVG is its own thumbnail.
- An open-ended derivative variant registry. Two variants, configurable dimensions only.
- Retroactive revalidation or re-sanitization of stored bytes. Already-stored public SVGs uploaded before the strict pass are the one population no layer covers; re-upload is the only remedy.
- A configurable table prefix.
- Numbered pages or a page-size control in the picker grid.
- Public derivatives, presigned derivative URLs, inline `data:` thumbnail URIs, and batch sprite endpoints.
- Promoting an asset's visibility on attach.
- Exact visual or interaction parity between Filament 4 and Filament 5.

## Further Notes

- The standing cost constraint from the map governs any later decision: the plugin must not force heavy usage of the operator's object storage, because reads, writes and egress are billed to the operator. The minimal option is the default, and anything costlier has to earn its place. This is what dropped poster frames, made `preview` on demand, capped lazy backfill and quantized derivative URLs.
- The map's remaining fog is measurement, not decision: whether the modelled derivative footprint (roughly five cents a month on a 12,000 asset library) holds against a real library, and whether predicted read volume matches a real bill. Neither blocks the build, and both want a real deployment rather than a ticket.
- Three prototypes are primary sources on branches out of main and should be consulted rather than re-derived: `prototype/06-picker-workflow` (picker shape, variants B and C rejected), `prototype/09-library-grid` (faceted sidebar and infinite scroll, variant B chosen), `prototype/20-grid-performance` (debounce, count degradation, placeholder).
- Two live tensions worth watching during the build, both already decided but both easy to reintroduce: the per-render signature against `immutable` caching (resolved by quantizing derivative expiry only, with originals keeping the 5-minute per-render signature), and the offer scope against the confidentiality boundary (resolved by keeping the scope as ergonomics and the policy as security, never by row-gating the grid).
- Ticket 19 listed only `media:import` and `media:resolve-mimes` as promised command signatures, before tickets 17 and 21 added `media:assign-tenant` and `media:regenerate-derivatives`. This spec treats all four as promised and expects `UPGRADING.md` to say so.
