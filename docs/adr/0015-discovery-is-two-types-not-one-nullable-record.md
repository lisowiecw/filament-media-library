# 15. Discovery is two types, not one nullable record

Date: 2026-08-31

## Status

Accepted

## Context

An import run discovers the objects it adopts in one of two ways. A column run
reads a declared host model and column, which is the real one: the row holding
the path is the row that knows who owned it and which field it filled, so it is
the only kind of run that can produce attachments at all. A traversal run walks
a prefix on the disk, for a legacy layout that has no column to read, and
everything it cannot know it leaves unrecorded rather than guessing.

The two were carried as one record. `ImportRequest` held a nullable model,
column and prefix, plus a `DiscoverySource` enum saying which half was real. The
nullability was never optionality: a column run always has a model and a column,
and a traversal run always has a prefix. Each field was nullable only so the
other kind of run could leave it unset.

Every consumer then paid for that. The importer narrowed the column back to a
string with a docblock cast wherever it read one, and fell back with an empty
string on the model and the prefix. Both `importSource()` and `attaches()`
branched on the enum to decide which half of the record to trust. The console
command already built the two runs in separate branches sharing no arguments, so
the union existed in the type and in no caller at all.

Nothing was broken by this. The boundary refusals reject the combinations that
make no sense, and they are tested. The cost was that the type permitted a run
declaring both a host model and a prefix, or neither, and every reader had to
know which fields were live before it could read one.

## Decision

A run holds one `Discovery`, an abstract type with exactly two implementations:
`ColumnDiscovery`, carrying a non-nullable model, column and cardinality, and
`TraversalDiscovery`, carrying a non-nullable prefix. `ImportRequest` keeps only
what both runs share: the disk, the field context, the uploader column, the
visibility, copy, sniff, dry run and chunk.

The two behaviours that turned on the enum are now the discovery's own. It names
the import source (`host.column`, or `disk:prefix`), and it says whether its
kind of run can attach at all, which traversal answers no to for the structural
reason that there is no host row. Whether a run that could attach actually does
is still the request's question, since that turns on the field context and the
dry run rather than on where the paths came from.

`DiscoverySource` survives as the accepted values of `--source` and the domain
of the refusal for an unknown one. That is a string arriving from a command
line and it needs a named set of values. Nothing below the command reads it.

## Consequences

An impossible run is now unconstructable rather than merely unused, and no
consumer narrows a nullable back to a string. The importer dispatches on the
discovery it was handed and reads non-nullable fields off it.

The refusals stay exactly as they were, even where the type now makes one of
them unreachable from the command. They guard the option surface, which is
where an operator's mistake actually arrives, and the type guards the code. The
two are not the same boundary and removing one because the other exists would
be trading a clear error message for a type error nobody sees.

The report keeps naming a cardinality on every run, traversal included, where it
reads as single: a walk yields one key at a time. That is a property of the
discovery rather than of the request now, but the report's shape is unchanged,
which matters because runs are diffed against each other across the migration
window.

A third kind of discovery would be a new implementation, and it would have to
answer both questions honestly before it could exist. That is the point: adding
one is a decision about what the importer can truthfully record, not a new value
in an enum with a nullable field trailing behind it.
