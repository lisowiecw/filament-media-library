# 14. A copied import key is derived, not generated

Date: 2026-08-31

## Status

Accepted

## Context

The importer adopts an application's existing uploads. Its default mode
registers each object where it already is, so the legacy path becomes the
object key verbatim and nothing is written to the source disk. `--copy` is the
one mode that writes: it streams the bytes to a second key under the media
directory and points the row at the copy, for an operator moving off a legacy
layout rather than blessing it.

That copy needs a key, and the package already has a key generator: the ingest
service mints a ULID per upload, which is what every asset created through the
picker or the management page carries. Reusing it here was the obvious move.

It is also wrong, for the same reason adoption is not ingest. Identity in the
library is the unique disk and object key pair, and the importer resolves rows
with `firstOrCreate` so that a re-run adopts nothing twice and a display name a
person edited afterwards survives. A freshly generated key defeats that: the
second run mints a different ULID, finds no row at that key, copies the bytes
again and inserts a duplicate asset. Every re-run would leave another copy of
every object on the disk. A re-run is the normal case during a migration
window, not the exception, so this is the mode's main path rather than a corner
of it.

## Decision

Copy mode derives its destination key from the pair it came from, as the first
32 hex characters of `sha256(disk:sourceKey)` under the configured media
directory, carrying the source extension. Ingest's ULID generation is left to
ingest.

The derivation makes the destination a pure function of the source, so the
second run resolves the same key, finds the row the first one wrote and copies
nothing. Copy mode also asserts the destination is free before writing, and
reports a `destination-occupied` omission rather than overwriting whatever is
already there.

## Consequences

Copy mode is idempotent on re-run, which is what the mode is for.

The key stays opaque and carries no readable name, matching what ingest
produces even though it is produced differently. Two distinct source keys
colliding on 128 bits of digest is not a risk worth engineering against, and a
collision would be caught by the destination-occupied assertion rather than by
an overwrite.

The cost is a second key scheme in the package. The seam holds because the two
never meet: a key is derived only when the importer copies, and generated only
when ingest stores. An asset cannot tell you which scheme produced its key, and
nothing needs to ask.
