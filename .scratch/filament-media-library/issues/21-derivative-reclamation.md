# Define Derivative Reclamation

Status: open
Type: grilling
Blocked by:

## Question

Ticket 12 fixed that a derivative is a child row, is removable by key prefix, and dies with its asset. It did not say what happens when the *settings* change rather than the asset.

Decide: when an application changes the configured `thumb` or `preview` dimensions, what retires the objects generated under the old settings, given the existing rows still point at real, still-servable files; whether a derivative records the settings it was generated under so staleness is detectable at all, or whether a dimension change is simply a manual regeneration a human triggers; whether regeneration is a command, a queued sweep, or lazy on the next render miss (which ticket 12 already built for the missing case); and what the grid serves during the window when an asset's derivative is stale but present, since serving the old one is correct-looking and serving nothing is not.

## Comments

- Context from [Define Library Grid Performance Budget](20-grid-performance-budget.md), 2026-08-27. This
  ticket's surface has changed while it sat open, and the changes should be assumed when it is worked:
  video poster frames no longer exist, so the reclamation population is images plus sanitized SVGs only;
  `preview` is now generated on demand, so a `preview` object may simply be absent for an asset that has
  one recorded elsewhere, and "stale but present" is no longer the only window this ticket must cover;
  small originals register no derivative rows at all, so a settings change must not sweep them into
  regeneration; lazy backfill dispatch is rate-capped, which is the cap any queued sweep decided here has
  to obey rather than reinvent; and the `blurhash` column rides on the `thumb` job, so regenerating a
  `thumb` recomputes it. Under the new standing constraint on storage cost, "lazy on the next render miss"
  is now the cheap option and a queued sweep the expensive one.
