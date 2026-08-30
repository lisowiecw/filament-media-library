# 12. The disk pair is configured, not named per field

Date: 2026-08-30

## Status

Accepted

## Context

A bucket is a disk: the package already assumes one Laravel disk per storage
bucket. The deployment we researched against keeps public and private media in
two Cloudflare R2 buckets behind two disks, and `media-library.disk` was a single
value, so every public field had to repeat `->disk('r2-public')->visibility('public')`
and every private one the opposite. The pairing is the deployment's real
invariant, and it was stated nowhere, only re-asserted at each call site where it
could drift.

## Decision

Two nullable config keys, `media-library.public_disk` and
`media-library.private_disk`, state the pair once. `Placement::resolve()` picks
the disk from the resolved visibility: field disk, then the visibility's paired
disk, then `media-library.disk`, then `filesystems.default`. A field that names a
disk still wins, and an application that names neither half resolves exactly as
it did before, so nothing about this is a breaking change.

## Consequences

Visibility now decides where bytes land, not just how they are addressed, which
is the coupling the deployment already had informally. `media-library.disk`
narrows in meaning to the fallback for a visibility whose half of the pair is
unset; setting one half only is supported and lands the other visibility on that
fallback, which is what a half-migrated deployment gets.

We rejected making the package validate that a named disk can actually serve its
visibility here, because that refusal is worth its own explicit failure rather
than being folded into resolution.
