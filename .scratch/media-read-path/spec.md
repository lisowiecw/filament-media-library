# Read path: one cache, and the rule that keeps it honest

Settled by a grilling session on 2026-09-07. Issues #91 (design and in-memory
read), #93 (`withMedia`/`loadMedia`), #94 (`firstMedia` fallback, trivial),
#95 (docs and the `CONTEXT.md` invariant). #92 is closed, folded into #91.
Consumer uptake: british-polish-school/british-polish-server#37.

## The problem

`HasMedia::media()` calls `mediaAttachments()` as a method, so it builds a fresh
query and never consults `$this->relations`. Eager loading buys nothing, a
second read of a field is a second query, and a page of N hosts costs O(N)
queries with no lever available to the consumer. Consumers have been patching
it per accessor with `->shouldCache()`, inconsistently.

## The invariant

**The loaded `mediaAttachments` relation is the only cache.** No per-instance
memo, no second structure with its own lifetime. Every read either finds the
cache or fills it.

## Reads

`media($field)` takes the in-memory path when `relationLoaded('mediaAttachments')`
and either `$field` is in the loaded-field set or that set is empty. In memory it
applies exactly the rules the query applies: filter on `field_name`, sort by
`order`, drop attachments whose asset is missing or soft-deleted.

If any attachment on the field lacks a loaded `asset`, abandon the in-memory
path for the whole field and query. Lazy-loading per attachment would be worse
than the query it replaced.

## The loaded-field set

`protected array $mediaLoadedFields`, recording which fields the loaded relation
covers, because `withMedia('thumbnail')` constrains the eager load and a relation
holding only thumbnail rows must not answer for `gallery`.

The set is advisory. A read consults it only when the relation is loaded, and
clears it whenever the relation is not, so the two structures cannot disagree
into a wrong answer. That covers `unsetRelation`, and it makes `refresh()` merely
pessimistic (relation reloaded unconstrained, set still naming one field, so
other fields re-query) rather than wrong.

**An empty set means unconstrained.** `withMedia` and `media()` both always write
the set, so a loaded relation with an empty set can only have come from a
hand-written `->with('mediaAttachments')`, which is unconstrained by definition
and is trusted for every field.

## Filling

A `media()` query appends its rows to the loaded relation and adds the field to
the set, so a second read of that field is free. This is what replaces the memo
#92 asked for. A relation that is the union of two field loads is legal, and the
set keeps it honest.

## Loading

```php
Product::query()->withMedia('thumbnail')->get();   // scope
$products->loadMedia('thumbnail');                 // Eloquent collection
$product->loadMedia('thumbnail', 'gallery');       // single model
```

Mirrors Laravel's `with`/`load` pair, because that is the shape consumers expect.
The scope constrains the eager load to the named fields and stamps the field set
on every hydrated model via `afterQuery()`, which fires once for the whole result
set. The collection and model forms share the same helper.

The collection form is not optional: two of the six uptake sites in the consuming
app hold hosts already in memory that cannot be re-queried through a scope.

Rejected: loading every field regardless (wasteful), and inferring the loaded
fields from the rows present (unsound, cannot tell an empty field from an
unloaded one).

## Staleness

A write through the trait clears the relation and the set on the instance it was
handed: `detachMedia()`, and `AttachmentReconciler::reconcile()` on the
`Model $host` it already receives. A write anywhere else leaves other instances
stale, and that is the contract, the same as any Eloquent relation.

`MediaPicker::getAttachedIds()` reads ids straight from `MediaAttachment` and
never goes through `media()`, so it is not a cache consumer and needs no change.

## firstMedia

Free off the cached path. On the query fallback it takes a small window rather
than `limit(1)`, so the trashed-asset skip survives: a naive `limit(1)` returns
null where the current code skips a trashed asset and returns the next live one.

It must not get a path that skips filling the cache. Cheaper in isolation at the
cost of a following `media()` re-querying would reintroduce the asymmetry this
work exists to remove.

## Tests

Four traps, one test each:

- `withMedia('thumbnail')` then read `gallery`
- raw `->with('mediaAttachments')` then read any field
- `unsetRelation('mediaAttachments')` then read
- `detachMedia()` then read on the same instance

Query counts only where the count is the contract: constant queries for N hosts
under `withMedia`, and one query for two reads of the same field. Behaviour tests
carry the rest, since query-count assertions elsewhere are brittle.

## Order

#91, then #93, then #94, then #95.
