# Define the Readable Name Algorithm

Status: resolved
Type: grilling
Blocked by:

## Question

Ticket 04 fixed the *semantics* of names: an original filename and an editable display name are metadata, the `object_key` is opaque and independent, renaming never moves an object, and a collision is an explicit choice between a new asset and cancelling. It never fixed the algorithm that produces the name in the first place.

Decide: what transformation turns an uploaded client filename into the stored original filename and the initial display name (case, whitespace, separators, length ceiling, extension handling); what Unicode normalization form is applied and whether non-ASCII scripts survive intact or are transliterated, given a library shared across locales; what counts as a "collision" for ticket 04's prompt, since it must be a comparison over *some* normalized form and the choice of form decides how often the prompt fires; and whether the readable name ever influences the `object_key`, which ticket 03 declared opaque but which a human debugging a bucket has to navigate by hand.

## Answer

### Ingest scrub on the original filename

`original_client_filename` is immutable but not verbatim. On ingest it is reduced to its basename (so a client-supplied path collapses to its last segment), stripped of C0/C1 control characters and Unicode bidi override characters, normalized to NFC, whitespace-trimmed, and capped at 255 bytes with the extension preserved. Verbatim storage is a fiction once the column has a length, and a bidi override in a provenance field is a spoofing vector rather than a fact worth preserving.

### Non-ASCII scripts survive

Names are preserved in their own script under NFC. Nothing is transliterated and no ASCII shadow column is kept. Ticket 04 already promised Unicode and punctuation in the display name, and transliterating a name a human typed destroys the thing the display name exists to show. Search reach across scripts rests on ticket 09's substring search and the database collation; if a script searches poorly, that is a ticket 09 amendment, not a reason to mangle names at ingest.

### The initial display name is derived, not prettified

The initial `display_name` is the original filename with its final extension removed, trimmed, and internal whitespace runs collapsed to a single space. No separator rewriting, no case changes. `_` and `-` are frequently meaningful (part numbers, SKUs, dates), and title-casing wrecks `iPhone` and `pH-meter`. The field is editable precisely so the algorithm does not have to guess.

A leading dot is not an extension separator, so `.gitignore` keeps its whole name and has no extension. Where stripping still leaves an empty string, the display name falls back to the full original filename rather than a generated placeholder, since the user did type something. Both paths yield a non-empty result, so `display_name` is `NOT NULL`.

### Collision is a case-folded comparison, library-wide

Ticket 04's collision prompt fires when the incoming name matches an existing one under NFC plus case folding plus whitespace collapse, and nothing further. `Annual Report` collides with `annual report`; `annual-report` does not. The comparison is library-wide and unfiltered, matching ticket 10's unscoped management page, because a collision the user cannot see (because it sits behind a field's accepted types) is worse than one they can. The prompt stays purely informational and never blocks, as ticket 04 decided.

### The extension follows the client name, the object key follows the bytes

The persisted extension is taken from the client filename and lowercased, even when ticket 13's content sniff contradicts it. Rewriting `report.txt` to `report.pdf` puts words in the user's mouth about a file that was only sniffed. Nothing safety-relevant rests on the extension: ticket 11's `mime_source` records how far the mime type can be trusted, and ticket 13's disposition rule already refuses to render a weak-rung asset in place. The one place the resolved mime type wins is the extension appended to the `object_key`, which is provider-facing rather than human-facing.

### Length ceilings differ by purpose

`display_name` is capped at 255 characters, enforced as validation on edit and as truncation only on the initial derivation. Characters rather than bytes, so a Japanese name is not silently a third as long as an English one. `original_client_filename` stays at 255 bytes, because that ceiling is about what a filesystem and a storage provider accept, which is a different constraint from what a person can read.

### The readable name never reaches the object key

Ticket 03's opacity holds unamended: keys stay a server-generated identifier plus a normalized extension, with no slugged name prefix. A name in the key would make the key lie the moment the asset is renamed, and it would carry user-controlled bytes into a path where the provider's own normalization applies.

The operator's real need (finding an asset from a bare key in a bucket) is a lookup problem and is solved as one, which places two obligations on already-resolved tickets:

- Ticket 09: `object_key` joins name, filename, alt and uploader in the substring search, so an operator can paste a key and get the asset.
- Ticket 10: the asset's view page surfaces `disk` and `object_key` as a copyable field.

## Comments

- Resolved with the requester on 2026-08-27.
