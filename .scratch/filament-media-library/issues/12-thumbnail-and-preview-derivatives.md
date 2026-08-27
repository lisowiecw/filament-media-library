# Define Thumbnail and Preview Derivatives

Status: open
Type: grilling
Blocked by: 07, 09

## Question

Ticket 09 fixed that the library grid renders a real preview for image and video assets, a poster frame plus duration for video, and a glyph tile for everything else. Nothing yet says where those previews come from. Decide whether the plugin generates stored derivatives (and at which sizes, on upload or lazily on first request, queued or inline), or serves the original scaled by the browser; what happens for video, which needs a poster frame extracted by a tool the application may not have installed; what a derivative's `object_key` and disk are, and whether derivatives are Media Assets themselves or a separate record; how a *private* asset's thumbnail is delivered, given ticket 07 requires private content to flow through the plugin-owned Delivery route with a signed URL rather than a raw presigned one, and a grid of 48 cards would mint 48 signed URLs per scroll batch; what the grid renders while a derivative is missing, still queued, or failed; and whether legacy imported objects (ticket 08) get derivatives generated on import, lazily, or never.
