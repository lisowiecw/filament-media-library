# External references are host-less Attachments

A Media Asset with zero Attachments is not provably unused: its URL may sit in a sent email, an export, or a third-party system the plugin cannot see. Rather than add a separate "keep" flag or a parallel table, an application records such a use as an **Attachment with a null host** (`$asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412')`), costing one nullable host column. Every existing rule then applies unchanged (the usage list names it alongside real host models, and the default delete block protects the asset), so there is no second safety mechanism to keep in step with the first.

## Considered options

- **A keep flag on the asset.** Rejected: a boolean records that someone objected, not what uses the asset, so the usage list stays a lie and nobody can ever tell when the flag is safe to clear.
- **Do nothing.** Rejected: the management page offers bulk delete over the unattached set, so the set has to mean what it says.

## Consequences

An external reference is deliberately *not* an Attachment for field purposes: null `host_type`/`host_id`/`field_name`, excluded from every field-context query, so `HasMediaAttachments` never returns one and it can never affect what a host model renders. It participates only in the usage list and the usage count (and therefore the picker's in-use/unattached facet). Creation is code-only, since it needs the application's identifier and label; revocation is exposed in the management page's usage panel, so a stale reference whose creating code is gone cannot block deletion forever.
