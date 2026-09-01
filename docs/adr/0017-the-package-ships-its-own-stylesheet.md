# 17. The package ships its own stylesheet

Date: 2026-09-01

## Status

Accepted

## Context

The picker and the library grid render markup of the package's own: a faceted sidebar, a card grid, a drop surface, a selection list, 44 class names under `fi-ml-`. Nothing ever styled them. On a clean Filament install the modal came out as a column of unstyled text, because Filament's compiled CSS knows its own `fi-` classes and nothing else.

Two answers were available beside shipping CSS. The class names could have been declared a hook and the styling left to the host application, but that is not a contract anyone accepts for a plugin's own UI, and it was never documented as one. The views could have been rewritten in Tailwind utilities, which only resolve in an application that builds a custom theme scanning our vendor path, so the default install would still be unstyled.

## Decision

The package ships a hand-written stylesheet, registered with Filament's asset manager and published by the application's `filament:assets`.

It is plain CSS, committed as written, with no bundler in this repository. It carries no palette: colours, radii and spacing come from Filament's own theme variables, so a custom theme and dark mode both flow through untouched.

The `fi-ml-` class names are public API from here on. Every selector is a single class, so a host theme overrides without `!important`, and a small set of custom properties covers the dimensional knobs.

Geometry is not invented here. It is taken from the prototypes the shape was decided on, `prototype/09-library-grid` (variant B) and `prototype/06-picker-workflow` (variant A), with their stand-in amber palette retranslated into theme variables.

Filament's own Blade components render the buttons, icon buttons and input wrappers. Imitating those in CSS is a thing you get most of the way right and then maintain forever, and it drifts on every Filament release.

## Consequences

Installing the package is no longer a Composer install alone: an application has to run `filament:assets`, which every Filament application already runs for every plugin it installs. This repository still gains no bundler, no npm build and no compile step. ADR 11 is amended rather than superseded, since its subject is JavaScript and its decision stands.

Renaming a `fi-ml-` class is now a breaking change.

The package depends on Filament's Blade component API in more than one line, which widens the bet ADR 8 keeps narrow. The four components used are the most stable surface Filament has, and CI is what catches it if that stops being true.
