# Domain Context

## Media Library

- **Media Asset**: A reusable file record with human-readable file metadata and storage metadata. Its readable name is distinct from the storage object identity.
- **Original filename**: The client-supplied filename recorded on a Media Asset at upload, immutable thereafter. It is scrubbed rather than verbatim: reduced to its basename, cleared of control and bidi-override characters, normalized, trimmed and length-capped, because a column has a length and a bidi override is a spoofing vector rather than provenance. Preserved in its own script; never transliterated.
  _Avoid_: Client name, upload name
- **Display name**: The editable, human-readable name of a Media Asset, derived at upload from the Original filename with its extension removed and whitespace collapsed, and never prettified further: separators and case are left as the person typed them. It is presentation metadata alone, so editing it never touches the storage object, and it is never an identifier.
  _Avoid_: Title, label, filename (the Original filename is a different thing)
- **Name collision**: Two Media Assets whose Display names match once case and whitespace differences are set aside. Collisions are permitted and library-wide; detecting one only informs the uploader, who chooses to create a new asset or cancel. A collision is never an error and never blocks.
  _Avoid_: Duplicate (that reads as identical bytes, which a collision says nothing about)
- **Attachment**: A relationship between a Media Asset and a host model. An attachment belongs to a named field context when a host model uses more than one media field.
- **Host model**: An application-owned model that can attach reusable Media Assets without owning their metadata or storage identity.
- **Field context**: The named media field within a host model that scopes ordered selection and duplicate prevention.
- **Detach**: Removing an Attachment for a given host model and field context. Never affects the Media Asset record or its storage object.
  _Avoid_: Remove, unlink, delete (detach and delete are distinct actions)
- **Replace**: Attaching a newly uploaded Media Asset into a field context in place of the one currently attached there, then detaching the previous one. The previous asset becomes an unattached asset if nothing else references it; replacing never deletes it.
- **Delete**: The explicit, library-management action that soft-deletes a Media Asset record and queues its storage object for removal. Blocked by default when the asset has other Attachments, unless performed as a force delete.
  _Avoid_: Destroy, remove
- **Force delete**: A delete performed on a Media Asset that still has other Attachments, overriding the default block after the requester reviews the usage list.
- **Usage list**: The resolved, human-readable list of Attachments referencing a Media Asset. Shown wherever usage matters: as a count, as a panel on the asset itself, and inside a blocked or forced delete. Each entry names the host model instance and field context, using the host model's label callback when configured.
- **Media Picker**: The single field component that renders a host model's attachments for one field context and opens the library modal. The only picker surface; it never exposes delete or a visibility choice.
- **Placement**: The disk, directory and visibility a Media Picker applies to **new uploads**. Placement is fixed on the asset at upload and never re-applied by attaching, so a shared asset keeps its own placement wherever it is reused.
  _Avoid_: Destination, location (these read as the object key, which placement is not)
- **Offer**: To show an asset in a picker's library grid as selectable. A picker offers an asset when its mime matches the field's accepted file types and the asset is public, or the field's placement is private. Disk and directory never affect what is offered. Offering is unauthenticated by default and distinct from View: a grid may offer an asset without ever checking View, since offering shows metadata, not content.
- **View**: The authorization ability that governs access to a Media Asset's actual content: the bytes, not its listing. View is checked only when content is being delivered (through the Delivery route, or a forced download), never merely to appear in a picker's grid. Public assets never require View, since their content is already publicly addressable.
  _Avoid_: Read, access
- **Delivery route**: The single endpoint the plugin registers to serve a private Media Asset's content. It re-checks View on every request, then either redirects to the storage disk's own temporary URL or streams the file directly, and carries a content policy on every response that forbids the browser from fetching anything the served file references. Because that policy does not survive a redirect, an asset served for rendering in place must stream. A public asset never uses the Delivery route; it resolves straight to the disk's native URL.
  _Avoid_: Signed URL, presigned URL (these name a mechanism the Delivery route may use internally, not the contract itself)
- **Derivative**: A plugin-generated, downscaled rendering of a Media Asset, stored as its own object and recorded as a child of the asset. A derivative is never a Media Asset: it cannot be attached, named or offered, and it inherits its parent's placement and visibility rather than carrying its own. Its key is immutable, so it dies with the asset rather than being edited.
  _Avoid_: Thumbnail (that names one variant, not the concept), version, rendition
- **Variant**: The named size a Derivative was generated at. The set is fixed by the package; only the dimensions are configurable.
- **Poster frame**: The still image extracted from a video Media Asset so its card can show the video rather than a glyph. Produced by an external tool the application may not have, so its absence is a degraded card, never an error.

- **Uploader**: The authenticated user recorded on a Media Asset at the moment it is uploaded, or absent when the upload was unauthenticated. The Uploader is a fact about provenance, not a grant of authority; the plugin defines no ownership or permission implied by being the Uploader.
  _Avoid_: Owner, creator
- **Unattached asset**: A Media Asset with zero Attachments. This is evidence that nothing uses it, not proof: a URL may live in a sent email, an export or a third-party system the plugin cannot see. Surfaced for review by a report-only sweep after a configurable grace period; never deleted automatically.
  _Avoid_: Orphan (it asserts nothing references the asset, which the plugin cannot know)
- **External reference**: A record that something outside any Host model uses a Media Asset: a campaign, an export, a sent email. It is an Attachment with no host, so it appears in the usage list and blocks deletion like any other use, but belongs to no Field context and is invisible to a Host model's media fields.
- **Source**: The origin of a Media Asset, either an upload or an import. Source records origin alone; it never encodes whether the plugin wrote the asset's bytes or adopted existing ones, because the object key already distinguishes those.
  _Avoid_: Type, kind (these read as the file's mime type), imported flag
- **Mime source**: Which rung of the resolution ladder produced a Media Asset's mime type: a stored `Content-Type` header, a content sniff, the filename extension, or unknown. Recorded on every asset, uploads included, because a browser-supplied `Content-Type` is a claim rather than a fact. It states how far the mime type can be trusted, and is what a targeted re-resolution pass selects on.
  _Avoid_: Mime confidence (it reads as a score rather than a named origin)
- **Import source**: Where an imported Media Asset was discovered, named as the host model and column that held its legacy path. Absent on uploaded assets. It is the handle a migration-window rollback selects on; it does not describe the asset's storage.

- **Active content**: A Media Asset whose type the browser would execute or script when rendered: HTML, XML, JavaScript, and anything declaring itself executable. Active content is stored like any other file but is never served inline, and cannot be uploaded to a field whose placement is public, because a public asset never passes through the Delivery route where that rule is applied. SVG is the one exception, once sanitized.
  _Avoid_: Dangerous file, unsafe type (these read as a judgement on the file rather than on how it is served)
- **Sanitize**: To strip script elements and event-handler attributes from an uploaded SVG so it can be treated as an ordinary inline image. Performed once at upload and never retroactively on stored bytes; an SVG that cannot be sanitized is refused rather than stored unsanitized. Sanitizing removes the ability to execute, not the ability to reference: some external URLs in the markup survive it, which is why a Sanitized SVG is not trusted on its own but read alongside the Delivery route's content policy, or, on public placement, alongside the Strict pass.
- **Strict pass**: The narrower sanitization an SVG receives when the field's placement is public, dropping embedded images, style blocks and links. It exists because a public asset never reaches the Delivery route and so is unreachable by the content policy that covers every other SVG. An SVG that loses an element to the Strict pass is refused, naming the element, rather than stored with a hole in it.
  _Avoid_: Public sanitization, hardened mode (the pass is named for what it does, not for where it happens to apply today)
- **Blocked type**: A file type the package refuses everywhere: refused at upload, reported rather than adopted at import, and never offered in a picker grid. The list is package-global and a Field context can only narrow what remains, never widen past it.
  _Avoid_: Banned, forbidden extension
- **Disposition**: Whether the Delivery route serves an asset's content for rendering in place or for saving to disk. It is earned rather than assumed: an asset is rendered in place only when it is not Active content and its Mime source is a stored header or a content sniff, so an asset whose type came from its filename is always served for saving.
