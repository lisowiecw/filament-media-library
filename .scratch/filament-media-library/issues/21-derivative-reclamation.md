# Define Derivative Reclamation

Status: open
Type: grilling
Blocked by:

## Question

Ticket 12 fixed that a derivative is a child row, is removable by key prefix, and dies with its asset. It did not say what happens when the *settings* change rather than the asset.

Decide: when an application changes the configured `thumb` or `preview` dimensions, what retires the objects generated under the old settings, given the existing rows still point at real, still-servable files; whether a derivative records the settings it was generated under so staleness is detectable at all, or whether a dimension change is simply a manual regeneration a human triggers; whether regeneration is a command, a queued sweep, or lazy on the next render miss (which ticket 12 already built for the missing case); and what the grid serves during the window when an asset's derivative is stale but present, since serving the old one is correct-looking and serving nothing is not.
