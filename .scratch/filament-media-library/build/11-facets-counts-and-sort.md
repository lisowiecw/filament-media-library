# 11: Facets, Counts and Sort

**What to build:** a facet sidebar whose counts tell the editor what clicking would yield, so they never land in an empty grid, and which degrades rather than lags on a large library.

**Blocked by:** 10

**Status:** ready-for-agent

- [ ] Left facet sidebar with Type (derived from the field's own accepted types), Visibility, Usage, Uploaded by and Uploaded
- [ ] A separate sort select: Newest, Oldest, Name, Most used, defaulting to Newest
- [ ] Facet counts computed against every active filter except the facet's own dimension, one grouped query per dimension
- [ ] Counts always ride the same round trip as the results
- [ ] Above a configurable threshold on the field-scoped set (default 50,000 rows, measured before search and facets) counts are dropped entirely, leaving the facets listed and clickable without numbers
- [ ] Search input debounced at 400ms from a package-global config key
- [ ] A "not attached anywhere" option under Usage, available in the picker as a view filter
- [ ] A filter change resets the selection and announces it
- [ ] Provenance is never exposed as a picker facet
