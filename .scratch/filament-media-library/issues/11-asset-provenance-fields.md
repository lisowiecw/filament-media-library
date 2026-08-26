# Define Asset Provenance Fields

Status: open
Type: grilling
Blocked by: 08

## Question

Ticket 02 enumerated the canonical Media Asset field list and ticket 08's importer needs facts that list does not carry: whether an asset was imported rather than uploaded, which legacy source it came from, and how confidently its MIME type was determined (stored header, content sniff, extension, or unknown). Does the canonical record gain provenance columns for these, and if so which — or are they left off the record and recovered from logs and the importer's report? Amending a resolved decision is the point of this ticket, so decide the amendment explicitly.
