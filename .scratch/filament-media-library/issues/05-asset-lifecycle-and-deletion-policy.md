# Define Asset Lifecycle and Deletion Policy

Status: resolved
Type: grilling
Blocked by: 02, 03

## Question

What lifecycle rules govern upload, attach, detach, replacement, explicit deletion, backing-object deletion, and orphan cleanup? The baseline is that detaching never deletes a reusable asset; decide the configurable authorization-aware deletion modes and shared-reference safeguards.

## Answer

**Detach** only ever removes an Attachment row — never touches the Media Asset record or storage object.

**Replace** (uploading a new file into an existing single-selection field slot) creates a new Media Asset, attaches it in that field context, and detaches the previous one. The previous asset becomes an ordinary orphan asset if nothing else references it — no auto-deletion, no special-casing; it's reachable through the same explicit-delete path as any other asset.

**Delete** is a distinct, explicit library-management action, not exposed via field pickers. If the asset has other Attachments, delete is blocked by default and the UI shows the usage list — each attaching host record and field context, resolved via an optional per-host-model label callback, falling back to `HostType #id (field_name)` when none is configured. A **force delete** confirmation overrides the block.

Delete (unshared, or forced) soft-deletes the Media Asset record immediately (Laravel `SoftDeletes`) and dispatches a queued job to delete the backing storage object at the same time — standard Laravel queue retry/backoff, landing in `failed_jobs` on exhaustion rather than custom failure tracking.

**Orphan cleanup** is a report-only Artisan command (not scheduled by default) that lists orphan assets older than a configurable grace period (default 30 days). It never deletes automatically — operators act on the list themselves.

**Authorization** mechanics are deferred to ticket 07 (Define Authorization and Private Delivery), but this ticket fixes the action space ticket 07 must gate: detach, delete-unshared, and force-delete-shared, with force-delete-shared the most privileged of the three.

All of the above — replace/detach/delete semantics, soft-delete, queued backing-object deletion, shared-reference blocking, and the orphan grace period — are **package-global config**, not per-picker overridable. Per-picker overrides stay scoped to storage placement (disk/path, per ticket 03); lifecycle safety rules must hold uniformly everywhere an asset is used.

Domain model updated: `CONTEXT.md` now defines Detach, Replace, Delete, Force delete, Usage list, and Orphan asset alongside the existing Media Asset/Attachment/Host model/Field context terms.

## Comments

- Resolved with the requester on 2026-08-26.
