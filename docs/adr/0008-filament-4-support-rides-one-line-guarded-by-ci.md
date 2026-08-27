# 8. Filament 4 support rides one Composer line, guarded by CI

Date: 2026-08-27

## Status

Accepted. Settles the release strategy that [ticket 01](../../.scratch/filament-media-library/issues/01-platform-and-package-contract.md) deferred when it declared Filament 4 support "best effort".

## Context

The package guarantees Filament 5 and promises Filament 4 on a best-effort basis, limited to shared documented plugin and field APIs. "Best effort" describes an intention, not a resolution rule. Composer resolves against the constraint alone, so a widened `^4.0|^5.0` line lets the resolver hand an installer a combination nobody has ever run, and the installer has no way to tell that apart from a supported one.

The alternatives were a separately tagged and separately tested v4 branch, which resolves honestly but makes every fix land twice and lets the older branch decay quietly, and dropping Filament 4 altogether, which is honest and cheap but abandons the promise ticket 01 made.

## Decision

One Composer line carries both majors with a widened constraint. The promise rests entirely on the compatibility matrix running in CI against both majors on every push, so a red Filament 4 job is a release blocker rather than a known failure. The README's compatibility table is generated from that matrix.

Support ends by narrowing the constraint to `^5.0` and dropping the v4 job in the same commit.

## Consequences

The constraint and the tested reality stay in step only as long as someone pays for the matrix. This is the whole cost of the decision, and it is deliberately concentrated in one visible place: if the v4 job is ever allowed to fail, the promise has already lapsed and only the constraint is still claiming it.

Two branches was rejected as the cost structure of a mature package with paying v4 users, which this package does not have. Had the CI matrix proven unaffordable, the correct answer was v5 only, not a widened constraint on faith: shipping an untested combination silently is worse than declining to support it.

Narrowing the constraint later is a breaking change, so v4 users are never dropped by a patch.
