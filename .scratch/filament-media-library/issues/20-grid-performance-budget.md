# Define Library Grid Performance Budget

Status: open
Type: prototype
Blocked by:

## Question

Two perceived-performance questions the grid tickets left open, both answerable against the same prototype.

Ticket 09 accepted one aggregate query per facet dimension per query change, with live cross-computed counts, but did not say what that budget does on a very large library or a slow connection. Decide the debounce on search input, whether facet counts are computed on the same round trip as the results or lag behind them, and what degrades first when the budget is exceeded: are counts dropped to approximations, deferred, or is the facet sidebar itself the thing that becomes optional above a library size.

Ticket 12 fixed derivative delivery and left open whether a tiny blurred placeholder is inlined in the grid payload so cards paint before their derivative arrives. Decide whether that ships, and if so what it costs the payload for a batch of 48, given ticket 12 already rules that pending and missing cards render a dimmed glyph tile with no polling.
