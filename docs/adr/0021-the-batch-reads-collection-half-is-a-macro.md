# 21. The batch read's collection half is a macro

Date: 2026-09-07

## Status

Accepted

## Context

The batch read has to reach hosts that are already in memory. Two of the six uptake sites in the consuming application hold a collection they cannot re-query: it arrived through a relation, or it was assembled from more than one source, so a `withMedia` scope on a query has nothing to attach to. `$hosts->loadMedia('thumbnail')` is the shape those sites need, and it mirrors Eloquent's own `load`, which is the pair consumers already expect.

The package does not own the collection those hosts arrive in. A host model is the application's, and so is whatever `newCollection()` returns for it. Some applications already return a collection subclass of their own.

Three ways to put a method on it were considered.

A custom collection class, `MediaCollection extends Collection`, returned by the trait overriding `newCollection()`. It is the typed answer, and it is the one the package cannot have: overriding `newCollection()` from a trait either fights an application that already overrides it, or silently replaces a collection the application chose, and a trait that quietly changes what every query on the host returns is far more invasive than the read it was added for. The alternative, asking every host to extend a package class, prices the batch read at a change to the application's model hierarchy.

An interface, `ReadsMedia`, implemented by host models, with the loader typed against it. It types cleanly, but it only helps models that declare it, and the trait is the package's promised surface, not an interface beside it. Existing hosts get nothing until they are edited, which is the cost the batch read exists to avoid.

A macro on `Illuminate\Database\Eloquent\Collection`, registered when the package boots. It reaches every collection of every host with no change to any application model, at the cost of a global name and a static type the collection class does not declare.

## Decision

`loadMedia` is a macro on `Illuminate\Database\Eloquent\Collection`, registered in the service provider's boot. The scope, the collection macro and the single-host method are three ways into one helper, `Attachments\MediaEagerLoad`, which holds the constrained eager load and the field set it records.

The macro lands on collections of models that never read media, so it is defined to pass them through: a model is treated as a host when it carries the trait's own read methods, and anything else in the collection is left exactly as it was found. A mixed collection is therefore an ordinary case rather than an error.

`loadMedia` is a name the package now owns on every Eloquent collection in the host application. It is registered rather than reserved: an application that defines its own `loadMedia` macro after the package boots wins, and one that defines it first is overwritten. The name was chosen to be legible rather than to be safe, on the same reasoning as the trait's `media()` and `firstMedia()`.

## Consequences

A host application gets the collection half of the batch read for free: no model change, no collection class, no interface, and the same spelling on a collection as on a single host.

The macro is not visible to static analysis as a method on the collection class. An application running PHPStan at a high level will need `@method` on its own collection or a stub, which the package cannot supply for it. Inside the package the loader takes models rather than a typed host, and asks each one whether it reads media, which is a runtime check where a type would have been better.

The name is global and unqualified, so a collision with an application macro is possible and is not detected. It is the price of the reach; the package's other collection-facing work stays behind its own classes so this is the only such name.

Where the macro cannot be used at all, in an application that already binds `loadMedia` to something else, the same load is still reachable through `MediaEagerLoad::into($hosts, ['thumbnail'])`, which the macro is a two-line forward to.
