# Spec: a first view of the library paints colour, not grey

## Problem Statement

Someone opening the media library for the first time meets a grid of grey tiles.

Three things combine to cause it. The BlurHash is computed by the thumb job, so an asset with no Derivative has no hash either, and Placeholder painting has nothing to paint from on exactly the view it was written for. The lazy dispatch cap lets a render queue five jobs, which is smaller than any real page of cards, so most of a page can never be served by the render that asked. And nothing re-renders when a job lands, so a card heals only on the next page load.

The result: a freshly imported library of a few thousand assets stays grey through an hour or more of browsing, and a fresh upload shows a grey tile in the seconds after the person made it, then quietly becomes a picture only if they navigate away and back.

## Solution

The BlurHash stops being a by-product of a Derivative and becomes a fact about the Media Asset, with a lifecycle of its own.

At upload it is computed inline, where the bytes are already in hand, so an uploaded asset has a hash before its card is ever rendered. An asset that arrived by import is hashed lazily by the first render that wants one, under a cap of its own that is looser than the derivative cap, because a hash costs a read and a decode and writes nothing to the object store.

The per-request derivative cap is raised to cover a page, so the page someone is actually looking at is served rather than rationed. The library grid and the picker's inline attached items poll while any card is still unresolved, so a thumbnail arrives without a reload and the polling stops on its own once every card is Ready or Failed.

An operator with an existing library backfills hashes through the regeneration command that already has the selector, the dry run and the pacing.

See `docs/adr/0018-the-blurhash-is-computed-apart-from-the-derivative.md`.

## User Stories

1. As someone opening the library for the first time after an import, I want every card to paint colour immediately, so that the grid reads as my library rather than as an empty page.
2. As someone who has just uploaded a file, I want its card to paint colour the instant it appears, so that I can tell the upload worked before the thumbnail lands.
3. As someone who has just uploaded a file, I want the real thumbnail to replace the placeholder without me reloading, so that I do not have to navigate away and back to see what I uploaded.
4. As someone browsing a library that is still filling in, I want cards to resolve in place while I look at them, so that the page improves rather than staying as it was drawn.
5. As someone browsing a fully generated library, I want no polling at all, so that an idle open modal is not a permanent source of requests.
6. As someone looking at a page containing one file that cannot be decoded, I want the polling to stop anyway, so that a single broken file does not keep the page requesting forever.
7. As someone picking media into a host form, I want the attached items beside the field to heal in place too, so that the field agrees with what I just chose.
8. As someone scrolling a page of twenty-four cards, I want that page's own generation to be requested, so that the view I am on is the view that gets served.
9. As an operator, I want a hash never to be computed twice for the same asset, so that concurrent renders do not double the reads I am billed for.
10. As an operator, I want a file that cannot be decoded to stop being asked about, so that a broken upload does not queue a job on every render forever.
11. As an operator, I want hashing to be rate capped, so that a traversal import over a large bucket cannot stampede my object store.
12. As an operator, I want hashing capped separately from derivative generation, so that a thirty byte string is not rationed at the rate of a WebP encode.
13. As an operator, I want an import run to stay as cheap as it is now, so that adopting five thousand rows does not fan out five thousand jobs at the moment I least want load.
14. As an operator with a library that predates this change, I want to backfill hashes from the command line, so that I do not have to browse the grid to heal it.
15. As an operator running that backfill, I want a dry run that tells me how long it will take, so that I can decide whether to start it now.
16. As an operator running that backfill, I want it paced by the same cap, so that a large run finishes rather than stopping a minute in.
17. As an operator, I want to raise or lower both caps in config, so that a deployment with a fast object store is not held to a default written for a slow one.
18. As someone uploading a file that turns out not to be decodable, I want the upload to succeed anyway, so that a hash I never asked for does not fail my upload.
19. As someone uploading a large image, I want the upload not to feel slower, so that the fix for a grey grid does not become a slow form.
20. As someone viewing an SVG or a small original, I want no placeholder and no hash work at all, so that an asset that already paints itself costs nothing extra.
21. As someone viewing a video or a document, I want the glyph tile it has always had, so that nothing tries to make a picture of a file that is not one.
22. As a developer reading the codebase, I want the hash's states named in the glossary, so that I do not re-derive its lifecycle from the dispatch code.
23. As a developer, I want one answer to whether an asset can be decoded, so that the render path and the command cannot disagree about what work exists.
24. As an operator, I want a ready hash never to be overwritten by a later path, so that two paths computing the same string cannot fight.
25. As an operator, I want a failed hash never to be quietly turned ready by a different route, so that a recorded failure means what it says.
26. As someone browsing under tenancy, I want hashing to respect the same boundaries the grid already does, so that the fix introduces no new way to see an asset.
27. As a developer upgrading the package, I want the migration to run without me making a data decision, so that this is not a breaking change.

## Implementation Decisions

**The hash gets a status.** A `BlurHashStatus` enum mirroring `DerivativeStatus`: Pending, Ready, Failed. It lives on the Media Asset beside the existing nullable hash column, added by a migration that requires no data decision (existing rows with a hash are Ready, existing rows without are null, meaning never asked). Null means never asked; Pending means a job is in flight and must not be dispatched again; Failed means never ask again. A nullable timestamp was rejected because it cannot distinguish in flight from finished with no result; a sentinel in the hash column was rejected because the column would then mean two things, and the painting code already has to defend against a value that is not a hash.

**Ingest computes inline.** `IngestService` computes the hash in the request, from bytes it already holds, and writes it Ready. It does not scale, encode or write an object, which is what separates this from synchronous thumbnail generation, which was considered and rejected: that would put image processing on the web tier and make a modal open as slowly as its slowest file. A decode failure is recorded Failed and never propagates: an upload succeeds regardless.

**Imports hash lazily.** Import runs dispatch nothing. The first render that wants a hash dispatches it, so an imported asset costs one extra read the first time somebody looks at it. Dispatching per adopted row was rejected: it defeats the cheap re-run the Import report exists to protect.

**The cap is split.** The hash gets its own allowance, sharing `LazyDispatch`'s counter mechanism but not its budget, defaulting substantially looser (of the order of 300 per minute against 60). It is capped rather than uncapped because a read is still a read and a traversal import over a large bucket could otherwise stampede. Both figures stay configurable.

**The per-request derivative cap is raised** to cover at least one page of cards, from its current 5. The per-minute cap remains the real protection; two caps where one would do is what made the visible page permanently under-served.

**Dispatch decisions stay in `Derivatives`.** The hash path reuses `generatable()` and skips anything `paintsItself()`, so there is one answer to whether an asset is a picture the package can decode, for the same reason `wanted()` was pulled up there: an answer that drifts between the render and the command would have one queueing work the other would not.

**The thumb job keeps writing the hash**, as an idempotent top-up, only when the asset has no Ready hash. It must never overwrite a Ready hash, and must never move a Failed status to Ready. This is what lets a library heal by either path.

**Two surfaces poll.** The library grid and the picker's inline attached items emit a poll while any card on the page is unresolved, and stop emitting it once every card is Ready or Failed. Failed counting as resolved is what stops a page polling forever over a file that will never decode. Always-on polling was rejected as turning an idle modal into a permanent query source; a per-card lazy component was rejected as multiplying round trips by the page size. The Filament resource table does not poll: a reload is natural there and polling a table is the most likely thing to fight the framework.

**The regeneration command grows a selector** for hashes only, reusing its existing dry run and its `await()` pacing. A second command was rejected: it would duplicate the selector, the dry run and the pacing.

**Glossary and ADR.** `CONTEXT.md` gains a **BlurHash** entry naming the string and its three states as a fact about the asset, with an `_Avoid_` line against Derivative, since coupling them is what caused this. **Placeholder painting** is rewritten to lean on it. ADR 18 records the decoupling. ADR 11 is untouched: it decides how the hash is painted, not when it exists.

## Testing Decisions

A good test here asserts what somebody can observe: that a card paints colour, that a job was or was not queued, that a poll attribute is present or absent. It never reaches for the enum, the job class or the cap counter directly. All seams below already exist; the feature introduces no new ones.

- **`Derivatives`**, with `Queue::fake`, prior art `tests/Feature/DerivativesTest.php` and `DerivativeStalenessTest.php`. Covers: a hash is dispatched once and not twice, a Pending hash is left alone, a Failed hash is never re-dispatched, an asset that paints itself is skipped, a non-image is skipped, the hash cap and the derivative cap are spent independently, the thumb job tops up a missing hash and leaves a Ready one alone.
- **`IngestService`**, prior art `tests/Feature/IngestServiceTest.php`. Covers: an upload returns with a Ready hash and no queued hash job, an undecodable upload returns successfully with Failed, an SVG and a small original get neither.
- **`LibraryGrid` and `MediaPicker`**, prior art `tests/Feature/LibraryGridTest.php` and `MediaPickerTest.php`. Covers: the poll attribute is present while a card is unresolved, absent once every card is Ready or Failed, and absent on a page whose only unresolved card is Failed. These assertions live here rather than in the browser because they must be deterministic.
- **`media:regenerate-derivatives`**, prior art `tests/Feature/DerivativeStalenessTest.php`. Covers: the hashes-only selector queues hash work and no derivative work, the dry run queues nothing and reports counts, the run obeys the cap.
- **Browser**, prior art `tests/Browser/PlaceholderTest.php`. Covers only the user-visible paint: a library with no derivatives at all shows coloured tiles rather than dimmed ones. Per ADR 16, a flaky browser test is deleted rather than retried, which is why the polling behaviour is asserted at the Livewire seam and not here.

`GenerateDerivative`, the new hash job and the `BlurHashStatus` enum get no seam of their own; they are exercised through `Derivatives`.

## Out of Scope

- Shipping a JavaScript BlurHash decoder. ADR 11 stands: the painting is CSS, and this spec only changes when the hash exists.
- Poster frames for video, and any rendering of documents. `generatable()` is unchanged.
- Polling the Filament resource table.
- Synchronous thumbnail generation on any path.
- Any change to Stale derivative detection, the digest, or the Import report's shape.
- Backfilling hashes automatically on upgrade. The migration adds the column; filling it is the operator's command to run.
- Progressive or true decoding of the placeholder in the browser.

## Further Notes

The measure of success is the browser test: a library with no derivatives paints colour. Everything else is the machinery that makes that true without an object store bill.

The risk worth watching in review is the two paths writing one column. The Ready-is-never-overwritten and Failed-never-silently-becomes-Ready rules are the whole of the contract between them, and they are the assertions most worth having.
