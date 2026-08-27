# Define the Readable Name Algorithm

Status: open
Type: grilling
Blocked by:

## Question

Ticket 04 fixed the *semantics* of names: an original filename and an editable display name are metadata, the `object_key` is opaque and independent, renaming never moves an object, and a collision is an explicit choice between a new asset and cancelling. It never fixed the algorithm that produces the name in the first place.

Decide: what transformation turns an uploaded client filename into the stored original filename and the initial display name (case, whitespace, separators, length ceiling, extension handling); what Unicode normalization form is applied and whether non-ASCII scripts survive intact or are transliterated, given a library shared across locales; what counts as a "collision" for ticket 04's prompt, since it must be a comparison over *some* normalized form and the choice of form decides how often the prompt fires; and whether the readable name ever influences the `object_key`, which ticket 03 declared opaque but which a human debugging a bucket has to navigate by hand.
