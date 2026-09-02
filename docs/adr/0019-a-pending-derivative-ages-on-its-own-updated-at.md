# 19. A pending Derivative ages on its own updated_at

Date: 2026-09-02

## Status

Accepted

## Context

Pending is a claim on work. A row is written pending before the job is queued, so a second render of the same card finds it rather than queueing the same generation again, and the operator's selectors leave it alone because something is already coming for it.

Exactly one writer releases that claim: the job's own record of its outcome, on success or through the failure hook. A worker killed outright runs neither. An OOM, a `kill -9`, a deploy that stops the queue mid-job, and the row is pending for good. No render re-dispatched it, no selector could reach it, and the card painted the quiet tile on every view from then on.

The fix is to stop reading pending as a permanent fact and start reading it as a claim with an age: past a configured window the generation is nobody's, and a render may queue it again. That needs a time to measure from, and the two candidates are not equally trustworthy.

`media_assets.updated_at` is not. An asset row is written by renames, tenancy claims, mime re-resolution, the unattached clock and whatever a host application does to it, none of which say anything about whether a hash is being computed. Timing a claim by it would read an unrelated rename as evidence that a dead worker was alive. That is why hashing needed a column of its own, `blurhash_pending_since`.

`media_derivatives.updated_at` is. A derivative row is not editable, not attachable and not nameable; it has no operator surface of its own, and nothing outside the pipeline writes one. The column moves when and only when the pipeline wrote the row.

## Decision

The abandoned window for a Derivative is measured against the row's own `updated_at`, and no column is added.

The pipeline stays that column's only writer. A dispatch over a row that is already pending would write back the values it already holds, and an update with nothing dirty moves no timestamp, so the dispatch sets the time deliberately (`MediaDerivative::beginGeneration()`) rather than relying on a write that may not happen.

Only pending is readable by age. A ready row is a rendering however old, and a failed one exhausted its retries and is cleared by an operator rather than by the clock.

## Consequences

An upgrade needs no migration and asks the operator for no data decision. A row a dead worker stranded a month ago is already older than any window, so it is reclaimed by the first render or the first `media:regenerate-derivatives --abandoned` after the upgrade.

The saving is bought with an invariant, and it is the invariant to watch: anything that writes a derivative row without generating one, a backfill, an operator fixup, a `touch()` reaching through the relation, tells the window that a dead generation is alive and holds the card grey for another window's length. A new writer of `media_derivatives` has to either be the pipeline or settle the row.

The asymmetry with the BlurHash is deliberate and is the reason to read this beside ADR 18. The same bug on the asset row cost a column; here it costs a comment, because the two rows are written by different numbers of people.
