# 07: MediaPicker, Single Selection, Upload Only

**What to build:** a content editor puts a `MediaPicker` on a form, uploads a file, saves the record, and the file is attached. Reopening the record shows it. The host table gained no column.

**Blocked by:** 03, 06

**Status:** ready-for-agent

- [ ] `MediaPicker::make('field')` with `->label()`, `->acceptedFileTypes()`, `->disk()`, `->directory()`, `->visibility()`, `->maxSize()`
- [ ] The field is virtual: the host table has no media column, and Attachment rows are the only copy of the fact
- [ ] The field's value is always an ordered array of bare asset ids, in both directions, whatever the cardinality
- [ ] Array index is the attachment order, authoritative on save
- [ ] `$form->fill()` hydrates from the Attachment rows for this host and field context, ordered by `order`, ids only, unscoped
- [ ] Attachment writes are deferred until after the host record is persisted, so an abandoned create form leaves no host-less rows
- [ ] Cardinality validation rules (`required`, `minItems`, `maxItems`) run over the id array
- [ ] An incoming id the viewer cannot have rejects the whole save with a validation error naming the field and never the asset id
- [ ] An Upload tab in the library modal calls the ingest service with the field's resolved Placement
- [ ] The field label and drop banner state where uploads land and with what visibility
- [ ] Tested as a Livewire component test against the fixture host model
