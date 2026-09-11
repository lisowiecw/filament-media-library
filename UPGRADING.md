# Upgrading

## What counts as a breaking change

This package's surface is a field, a policy, a route and a config file, so
breakage is defined by behaviour rather than by signatures. A release is
breaking when it does one of these four things, and each is called out in the
changelog with what to do about it:

1. **A migration demanding a data decision.** A schema change that cannot be
   applied without you saying what existing rows mean. A migration that only
   adds a nullable column, or backfills a value the package can derive on its
   own, is not one of these.
2. **A changed default about what is served or refused.** Anything that alters
   what an existing asset delivers, or what a new upload is allowed to be: a
   Disposition rule, the blocked-type list, the sanitizer's strictness, the
   disk and visibility invariant.
3. **A new fail-closed gate.** A new ability or gate that denies by default, so
   an application that has not written it loses access it had.
4. **A config key removed or redefined.** A key that disappears, or one that
   keeps its name and means something else. A new key with a default that
   preserves today's behaviour is not breaking.

A change confined to the internal surface is never breaking, however visible it
looks. What is promised and what is internal is listed once, under "The promised
surface" in the [README](README.md).

## The promised command list

The commands whose signatures are part of the promised surface, because they end
up in deploy scripts and runbooks:

- `media:import`
- `media:resolve-mimes`
- `media:regenerate-derivatives`
- `media:assign-tenant`
- `media:unattached-assets`

As of `0.1.0` all five are on that list. Earlier drafts of the promised surface
named only `media:import`; the four that joined it are `media:resolve-mimes`,
`media:regenerate-derivatives`, `media:assign-tenant` and
`media:unattached-assets`. A change to any of their signatures is announced in
the changelog like any other break in the promised surface.

## Ending Filament 4 support

Filament 4 is carried on the same Composer line as Filament 5 and is proven by
the `4.*` leg of the CI matrix in `.github/workflows/tests.yml`. A red Filament 4
job blocks a release: no job or step is allowed to continue on error, and a
single `matrix` job stands behind every leg and fails unless all of them passed,
which is the one stable name branch protection requires. A test asserts both.
Tagging is guarded independently: `release-guard.yml` refuses a tag or a
published release whose commit does not carry a passing `matrix` check, so a red
leg blocks a release even where branch protection is not configured.

What keeps the two majors honest beyond the matrix running is a test that reads
every `Filament\` symbol `src/` imports and asserts the installed major declares
it. On the Filament 4 leg, reaching for something only Filament 5 has is a
failure there rather than a bug report later.

Support ends in one commit that does both of these, never one without the other:

1. Narrow `filament/filament` in `composer.json` to `^5.0`.
2. Drop `4.*` from the `filament` matrix in `.github/workflows/tests.yml`, then
   run `composer compat:sync` so the README compatibility table follows.

No test changes are needed: the suite reads the matrix rather than restating it,
so it stays green through the narrowing and starts guarding the Filament 5 only
claim instead.

Narrowing the constraint is a breaking change, so it lands in a minor while the
package is pre-`1.0.0` and in a major after that. A v4 user is never dropped by a
patch. Leaving the constraint wide while the job is gone is the one outcome the
matrix exists to prevent: the promise would have lapsed with only the constraint
still claiming it. See [ADR 0008](docs/adr/0008-filament-4-support-rides-one-line-guarded-by-ci.md).

## The SVG sanitizer upgrade

`enshrined/svg-sanitize` moved from `^0.22` to `^1.0` to pick up four advisories
unpatched in every `0.22` release. The upgrade narrows what an SVG upload is
allowed to be, which is a changed default about what is refused, so it is
breaking under rule 2 above.

Two things can change for an upload that used to succeed:

1. **An SVG referencing a custom entity is refused.** The `<!DOCTYPE>` is now
   stripped before parsing, so a document that uses `&x;` against its own
   `<!ENTITY x ...>` no longer parses and reaches the application as an ordinary
   `IngestRefused` naming the file. Against `0.22` it was sanitized and stored
   with the entity expanded. Re-export with the entity text inlined. Merely
   declaring an entity is not refused: an unused declaration is dropped and the
   document survives.
2. **More remote references are stripped.** A bare remote `href` or `src`, an
   unquoted `url()`, a `url()` among other declarations in a `style` attribute,
   and a remote `@import` or `url()` inside a `<style>` element are now removed,
   where `0.22` left them in the stored bytes. The file still uploads. A local
   `/x` or fragment `#x` reference is untouched.

   Watch the `style` attribute case, which is the one that can change how a file
   looks: a remote `url()` costs the **whole attribute**, not just that one
   declaration, so `style="fill:red;background:url(https://...)"` comes back
   with no `style` at all and the `fill:red` is lost with it. An SVG that styled
   itself inline alongside a remote reference will render differently.

Nothing is re-sanitized at rest, so SVGs stored before this release keep the
bytes `0.22` produced for them. Re-uploading is the only way to give an existing
SVG the stricter pass, and it is worth doing for any public SVG, which no
response header covers
([ADR 5](docs/adr/0005-public-svg-is-sanitized-more-strictly.md)).

There is no config key for this: the sanitizer's strictness is not configurable
by design, because an application that could loosen it would be storing the
markup this package promises it refuses.

## 0.x

The package is `0.4.0` and pre-release. Until `1.0.0`, a minor version may carry
any of the four changes above; each one is still named in the changelog with its
migration path.
