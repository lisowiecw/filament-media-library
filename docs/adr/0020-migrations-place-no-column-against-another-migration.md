# 20. Migrations place no column against another migration

Date: 2026-09-05

## Status

Accepted

## Context

The package ships its migrations rather than publishing them, and they carry no timestamp prefixes: `discoversMigrations()` hands Laravel the whole directory and Laravel runs the files in alphabetical order. That order is an accident of what the columns were called. Nothing about `record_blurhash_pending_since_on_media_assets` says it has to run after `record_blurhash_status_on_media_assets`, and alphabetically it does not.

It had to, though, because it placed its column with `->after('blurhash_status')`, naming a column the later file creates. A from-scratch run on MySQL stopped there: `Unknown column 'blurhash_status' in 'media_assets'`. New installs, CI rebuilds, `migrate:fresh` and every fresh contributor setup hit it; the machines that did not were the ones that had migrated along the way and already had the column.

The suite could not have caught this and still cannot catch the next one. It runs on sqlite, whose schema grammar has no notion of column placement: `after` is parsed, discarded, and never reaches the database. Postgres does the same. MySQL is the only engine that honours it, which means the tidy column order the hint bought was never a property the package had, only one it had on one engine, at the price of an ordering dependency invisible to every test that ran.

Prefixing the filenames with timestamps would encode the order, and it was the fix the report suggested. It was not taken. It changes the name recorded in the host's `migrations` table, so every existing install re-runs the set; the guards make that survivable, but it is a real event on somebody's production database in exchange for cosmetics. It does not survive publishing either, since `vendor:publish` strips the prefix and stamps its own. And it leaves the dependency in place, merely satisfied, so the next migration to add one gets no warning at all.

## Decision

No migration in this package places a column relative to a column another migration creates. In practice: no `->after()` anywhere under `database/migrations`, enforced by an architecture test that reads the directory and names the file and line of any hit.

Column order in `media_assets` is therefore whatever the engine chose, and a column added later sits at the end of the table. That is already what a Postgres or sqlite install has always had.

The invariant is about columns, not tables. The three `create_*` files still have to run before the three `record_*` files that alter what they create, and they do, permanently, because `create` sorts before `record` and both halves of that are in the filename's meaning rather than in a prefix.

## Consequences

The migration set is order-independent among the files that alter a table, so a future migration can be named for what it records without anybody working out where it has to land. The test says so in the repository rather than in somebody's memory, and it fails on the file and the line, which is the part a reviewer would otherwise have to know to look for.

An install that already ran these migrations keeps its current physical column order, and a fresh one will differ from it on MySQL. Nothing reads a column by position, so the divergence is visible in `DESCRIBE` and nowhere else. It is not backfilled, because reordering a table's columns to make two installs look alike is a rewrite of the table in exchange for a tidier readout.

What this does not buy is coverage of the engine gap that hid the bug. sqlite still silently discards anything MySQL alone honours, and the next hint of that shape will run green here too. A MySQL leg in the compatibility matrix would close it and was considered; it was left out because it pays for a whole matrix leg to catch by accident what the test above catches by name.
