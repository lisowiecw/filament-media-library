# Derivatives are child rows, not Media Assets

A Derivative is a plugin-generated downscaled rendering of a Media Asset, and it is tempting to store one as another Media Asset with a parent link, since it is also a file on a disk with a mime type and a size. It is stored instead as a row in `media_derivatives` (asset, variant, disk, object_key, width, height, bytes, status), because a Derivative is not reusable, attachable, nameable or offerable: making it a Media Asset would mean adding a "not a derivative" condition to every query that touches assets, and the first one anybody forgot would leak a thumbnail into a picker grid, a management table or a usage count.

## Considered options

- **Derivatives as Media Assets with a parent link.** Rejected as above: it makes the exclusion the caller's job, in an open-ended set of queries, forever. Half the asset record (readable name, uploader, source, import source, attachments, soft-delete semantics) is meaningless on a derivative anyway.
- **No record at all: a deterministic object key plus `Storage::exists()`.** Rejected because generation is queued, so there must be somewhere to record *pending* and *failed*. Without it a permanently failing derivative re-dispatches its job on every render, forever, and there is nothing for a health count or a regenerate action to select on.

## Consequences

A Derivative has no independent identity: it inherits its parent's disk and visibility rather than carrying its own, so a private asset's thumbnail is private by construction and flows through the Delivery route like any other private content (see ADR 0001). Its key, `<derivatives-prefix>/<asset-ulid>/<variant>.webp`, is immutable, which is what makes an aggressive `immutable` cache header safe and lets an asset's whole derivative set be removed by prefix when it is deleted. Restoring a soft-deleted asset regenerates derivatives lazily rather than resurrecting the objects. The cost is a second table and a second lifecycle to keep in step with the asset's own; the `status` column is what buys back the queue's failure story.
