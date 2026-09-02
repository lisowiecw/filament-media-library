# 18. The BlurHash is computed apart from the Derivative

Date: 2026-09-02

## Status

Accepted

## Context

The BlurHash rode on the thumb job's own decode, taken for free from pixels that job already had in hand. That is cheap, and it was the reason the hash existed at all.

It also means the hash arrives only once a Derivative has been generated. A card with no derivative yet has no hash either, so Placeholder painting falls through to the dimmed tile on exactly the view it was written for. The hash helps the second visit, which is the visit that already has the real thumbnail.

The lazy dispatch cap sharpens this. A render may queue five jobs, so a page of twenty-four cards paints nineteen grey tiles, and nothing re-renders when a job lands: the card heals on the next page load. A freshly imported library of a few thousand assets fills in at sixty per minute of continuous browsing, which is an hour or more of someone looking at a grid that stays grey.

The two costs are not alike. A Derivative is a read, a scale, a WebP encode and a write to the object store, and the cap exists to keep that bill bounded. A hash is a read and a decode, thirty bytes, and no write to the object store at all. Rationing them at the same rate is what left the first view with nothing to paint.

## Decision

The hash is computed independently of the Derivative that used to carry it.

At upload it is computed inline, in the ingest request, where the bytes are already in memory and it costs no read. It is not scaled, not encoded and not stored as an object, so this is not the synchronous generation that a thumbnail would be, and an uploaded asset has a hash before its row is ever rendered.

An asset that arrived by import has no bytes in hand, so its hash is dispatched lazily by the first render that wants one, under a cap of its own that is looser than the derivative cap. Imports do not fan out a job per adopted row: a run that touched every object is the cheap re-run the Import report exists to protect.

The hash carries a status of its own, pending, ready and failed, mirroring the one a Derivative carries and for the same reasons. Null means never asked; a recorded failure means never ask again, which a nullable column alone could not say.

The thumb job keeps writing the hash, as an idempotent top-up when the asset has no ready one. It never overwrites a ready hash and never turns a failed one ready by the side door.

## Consequences

A first view paints colour rather than grey, which was the point.

There are now two paths computing the same string, which is the thing a reader will ask about, and the status column is what keeps them from fighting: whoever gets there first wins, and neither repeats the other's work.

An imported asset costs one extra read of its object, the first time somebody looks at it. That is the price of not having read it during the import.

A library that predates this change heals by either path: through the new one on first view, and through the derivative path as thumbnails are generated anyway. The operator's way to force it is the existing regeneration command rather than a second one.

ADR 11 is untouched. It decides how the hash is painted, in CSS rather than in JavaScript, and nothing here disturbs that; this decision is about when the hash exists to be painted from.
