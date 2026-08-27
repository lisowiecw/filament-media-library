# Define the Media Picker Field's Serialized Value

Status: open
Type: grilling
Blocked by:

## Question

Ticket 01 deferred the `MediaPicker` field's serialized value alongside the packaging questions; ticket 19 answered the packaging ones and promised the field itself as stable public API, which makes its value shape a promise too. Ticket 06 settled what the picker *does* (one field, one modal, ordered field-scoped attachments, no duplicates) and never what it puts in the host's form state.

Decide what `$data['<field>']` holds when a form using a `MediaPicker` is filled and when it is saved: a bare asset id, an ordered array of ids regardless of cardinality, a shape that differs between single and multiple, or hydrated models. Decide whether ordering is carried in the value or only in the Attachment rows, given ticket 18 made array index order attachment order verbatim for imports. Decide what a host app's own validation rules and casts see, since they run against this value and not against the Attachment table. Decide whether the value is what gets persisted at all, or whether the field writes Attachments as a side effect and the host column stays absent, which is the question a host app migrating off a legacy path column will ask first.
