# 22. The hash allowance governs asking, not settling

Date: 2026-09-11

## Status

Accepted

## Context

Lazy hashing has two halves that look alike and are not. A render that finds no BlurHash claims the pending status and queues a read; a worker that has done the read writes what it found. `BlurHashing::dispatchLazily()` is the first, `BlurHashing::write()` is the second, and for a while both asked `HashDispatch::allows()` before proceeding.

The allowance is a cap on admitting work. It is sized to a page of cards per request and to a shared counter per minute, and `allows()` spends a slot when it answers yes, so asking it twice for one hash spent the budget twice. Worse, the second ask could refuse. A busy minute meant a fully decoded hash, or a `settleAsFailed` from a worker whose read had failed for good, was discarded: the row stayed pending until the abandoned window of ADR 18 lapsed, and the next render bought the same read and the same decode again. The cap made the library do more work, not less, exactly when it was busiest.

The twin pipeline never had the symmetry. `GenerateDerivative::record()` writes its outcome unconditionally, and nothing in ADR 18 or ADR 19 asks for anything else.

Nor does the allowance make the write safe. That is the claim carried into the `update()`: a conditional write that only settles a row still owed a hash, and only under the claim the worker was holding. Removing the allowance takes nothing away from first-writer-wins.

## Decision

The hash allowance is asked when work is being admitted and never when a result is being recorded. `dispatchLazily()` asks it; `write()` does not.

## Consequences

A hash costs one slot rather than two, so the configured per-minute figure now means what it says.

Settling cannot be refused. Every path that computed something records it: the upload that had the bytes in hand, the thumb job that already had the raster, the lazy job that paid for the read, and the failure hook of a worker whose object would not decode. A row therefore leaves pending as soon as somebody knows the answer, rather than waiting out a window for work that had already been done.

The rule is worth stating because the two halves read alike. The next change that wants back-pressure on hashing belongs in `HashDispatch` and in the paths that ask for work, never in the write.
