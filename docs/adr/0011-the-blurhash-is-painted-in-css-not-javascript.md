# 11. The BlurHash is painted in CSS, not JavaScript

Date: 2026-08-29

## Status

Accepted

## Context

The thumb job records a BlurHash on every asset it decodes, and a card whose thumbnail is still in flight has nothing else to paint with. Something has to turn that string into colours.

A BlurHash is normally decoded in the browser, which would mean the package shipping its first JavaScript: a build step, a published asset, a registration with Filament's asset manager, and a versioning story for all of it, in a package that until now installs with Composer alone and nothing else.

Rendering the hash server-side as an inline `data:` URI is the other usual answer, and the spec rules it out alongside presigned derivatives and sprite endpoints.

Leaving it to the host application is the third answer, and it means the package emits an attribute and paints nothing, so the feature is only real for applications that write their own decoder.

## Decision

The hash is decoded in PHP and painted as CSS: an average background colour with a small grid of radial gradients over it, emitted in a `style` attribute on the pending tile.

The hash itself stays on the same element as `data-blurhash`, documented, so an application that wants a true decode can render one over the top.

## Consequences

There is no asset to build, and no decoder to download before a card can paint.

At the time this was written that also meant installing the package stayed a Composer install. ADR 17 has since given the package a stylesheet, so an application now runs `filament:assets` as well. That does not disturb this decision: the painting is still emitted inline in a `style` attribute, and a BlurHash decoder would still be JavaScript, which the package still does not ship.

The painting is coarser than a real decode: a 3 by 3 sample of gradients reads as the picture's composition and colour, not as the picture. It costs a few hundred bytes of inline style on each pending card, which is bounded by the page size the grid already loads.

The decode is now a thing the package does at render time rather than only at generation time, so a stored value that is not a hash has to be answered for. It paints nothing and the card falls back to the dimmed tile, since a card is not the place to discover that a column holds something unexpected.

Should the package ever ship JavaScript for another reason, this decision is worth revisiting: the attribute is already there, and the CSS painting becomes the no-JS fallback rather than the whole answer.
