# Bounded field reads

A read of a media field always fetches the whole field. There is no windowed or
`limit`-ed query behind `firstMedia()`, and the cache holds no notion of
partially holding a field.

## Why this is out of scope

`HasMedia` keeps one cache for every read of a field: `mediaLoadedFields` is a
flat list of field names, and a field's presence in it means the loaded
`mediaAttachments` relation holds that field completely. `cachedMediaAttachments()`
leans on that to answer without a query, and `fillMediaAttachments()` is the only
thing that puts a field in the list, after fetching all of its rows.

```php
// src/Concerns/HasMedia.php
protected array $mediaLoadedFields = [];   // field names, not field contents
```

A bounded fetch cannot honestly record itself there:

- A window returns fewer rows than the field holds, so the relation it leaves
  behind is partial.
- The field cannot then be marked loaded, or the next `media()` reads a partial
  answer as a complete one.
- Not marking it loaded means the next `media()` re-queries, which is the exact
  asymmetry the read-path work removed.

Making the window work therefore means changing what the cache *means*: a
per-field marker recording what is held rather than whether it is held, and
every read learning to complete a partial hold. That invariant was settled
deliberately across #91, #93 and #94, and it is what keeps the cheap reads and
the whole-field read on one code path.

The bug it would buy is small. The cost is one wasted fetch, on an uncached
field, holding many assets, read only for its first. A caller who knows they are
in that shape already has `withMedia()`. The soft-delete rule makes it worse
still: trashed assets are filtered in PHP after the fetch, so a window has to
over-fetch by an unknowable margin or push that rule into SQL and hold it in two
places.

`firstMedia()` keeps the saving that was free, which is not building the
collection of assets it would have discarded, and pays the fetch.

## Prior requests

- #94: "firstMedia reads a whole field to hand back one asset"
- #97: "Bound the uncached read of a field to the assets a reader asked for"
