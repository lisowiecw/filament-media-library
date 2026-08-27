# 10. The media picker field is virtual

Date: 2026-08-27

## Status

Accepted

## Context

A Filament field normally maps to a column on the host model. A Media Picker could have done the same: a column holding an id or a JSON array of ids, with Attachment rows derived from it.

But Attachments already had to exist and already had to be canonical. They carry the field context, the order, and the host-less rows that record an External reference, and they are what the usage list, the force-delete block, the unattached sweep and the legacy importer all read. A host column would therefore be a second copy of a fact the package already stores, and every path that touches attachments without going through a form (import, force delete, a tenant guard, an operator on the management page) would have to maintain it.

Host applications migrating onto the package arrive with exactly such a column, holding a legacy path, and will ask whether it is repurposed.

## Decision

The Media Picker holds its selection in form state only. The host table gains no media column, and a migrating host drops its legacy one rather than repurposing it.

Filling the form reads the Attachments; saving reconciles them against the Picker value by difference, and nothing about the selection is written to the host record. Because the selection is never a host attribute, the package also provides the read path a template or a job needs, as a trait on the host model, and promises it.

## Consequences

There is one copy of the selection, so nothing can drift and no path has to be taught to keep two things in step.

A host cannot read its media through an attribute, and a hand-written relationship would hardcode table and column names the package treats as internal. The trait is therefore load-bearing rather than a convenience, and dropping it would be a breaking change.

On a create form the host record does not exist when the selection is made, so attachment writes wait until it is persisted. An abandoned create form leaves the uploaded asset in the library and no attachment at all, which is the honest outcome: the upload happened, the attachment never did.

Order lives in the Picker value while a form is open, so a host that reorders attachment rows directly and then saves any field on a form containing the picker has that order overwritten. The reconciliation writes order only where it genuinely differs, which bounds this to real disagreements.
