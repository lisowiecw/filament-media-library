# Close the Legacy Importer and Migration Gaps

Status: open
Type: grilling
Blocked by:

## Question

Three migration-window gaps left open by tickets 08 and 13, all small enough to settle together and all sharing the importer's context.

First: **multi-value columns.** Ticket 08 assumed a single-value legacy column. Decide how the importer discovers, orders and attaches paths held in a JSON array column, and what ordering means when the source array has no explicit order, given ticket 02 made attachments ordered and field-scoped.

Second: **objects on an unconfigured bucket.** Ticket 08 made unknown disk a hard failure, on the reasoning that a disk name is always suppliable. Decide what happens when it is not: legacy objects live in a bucket the application no longer configures, so no disk name exists to record. Is the row skipped and reported, is a disk configured solely for the import, or does the record gain a way to name a bucket the application cannot read?

Third: **the sniff step.** Ticket 13's earned disposition means a freshly imported library renders as downloads until `media:resolve-mimes --sniff` has run. Decide whether the importer chains that pass itself, offers it as a flag, or whether the runbook documents it as a step a human runs, and what the management page shows in the window between.
