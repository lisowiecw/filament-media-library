# Define Upload Validation and Active Content Handling

Status: open
Type: grilling
Blocked by: 07, 11

## Question

The destination promises arbitrary file types, but nothing has yet fixed what a field validates at upload beyond ticket 06's `acceptedFileTypes` picker gate, nor how the plugin treats browser-active formats (HTML, SVG, and files whose declared type is executable or scriptable). Ticket 07 fixed that private content always flows through the plugin-owned Delivery route with inline-by-default disposition, and ticket 11 fixed that a MIME type carries a `mime_source` rung recording how much it can be trusted. Both are now inputs to this decision.

Decide: what size and type limits exist and at which level (package global, field, or both); whether a declared MIME is ever verified against content at upload and what happens on a mismatch; and whether active content is refused, stored but forced to attachment disposition on delivery, or served as-is. Ticket 11's `mime_source` should inform the disposition rule, since an extension-derived type is a weaker basis for serving something inline than a sniffed one.
