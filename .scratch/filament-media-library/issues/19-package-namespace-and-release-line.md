# Define Package Namespace and Release Line

Status: resolved
Type: grilling
Blocked by:

## Question

Ticket 01 fixed the support matrix (Laravel 13 / PHP 8.3+ guaranteed, Filament 5 guaranteed, Filament 4 best effort behind shared public APIs) but deliberately deferred how that matrix is *packaged*.

Decide: the Composer package name, the PHP namespace and the published config/migration/view prefixes, all of which are effectively irreversible once anyone installs it; whether Filament 4 support ships in the same Composer line with a widened constraint or as a separately tagged and separately tested branch, given "best effort" in one line means a resolver can hand an installer a combination nobody has run; the versioning contract for a 0.x or 1.0 launch and what counts as a breaking change for a package whose public surface is a Filament field, a policy, a route and a config file; and which of those four surfaces is actually promised as stable versus documented as internal.

## Answer

**The names.** The Composer package is `lisowiecw/filament-media-library`: a personal vendor rather than an invented org, because the vendor is the irreversible half and a name that cannot be claimed on Packagist is worse than a plain one. The PHP namespace follows the vendor, not the working directory: `Lisowiecw\MediaLibrary\`, dropping "Filament" from the namespace because the package *is* the Filament plugin. Everything else derives conventionally: config published as `media-library` to `config/media-library.php`, view and translation namespace `media-library::`, tables `media_assets`, `media_attachments`, `media_derivatives`.

Table names carry no configurable prefix. A prefix knob would thread through every migration, model, index name and ticket 08's unique `(disk, object_key)` constraint, and it buys protection against a collision that is loud, immediate, and solvable by the single host app that hits it. Cheap to add in a minor if it ever bites; expensive to carry forever if it does not.

**One Composer line, not two.** `filament/filament: ^4.0|^5.0` in a single line with a single changelog, rather than a v5 line plus a tagged v4 branch. The ticket's own worry stands (a widened constraint lets the resolver hand someone a combination nobody ran), so the constraint is honest only to the degree it is tested: ticket 01's compatibility matrix must run in CI against both majors on every push, and a red v4 job is a release blocker rather than a known failure. The moment that job is allowed to fail, the promise is already gone and only the constraint is still claiming it. Two branches was rejected as the cost structure of a mature package with paying v4 users, which this has none of; v5-only was the correct fallback had the CI matrix not been affordable, since shipping an untested combination silently is worse than refusing to support it.

Support ends by narrowing to `^5.0` and dropping the v4 job **in the same commit**, never by letting coverage lapse under an unchanged constraint. The README carries a compatibility table generated from the CI matrix, so drift between what is promised and what is exercised is visible rather than prose.

**Promised versus internal.** Public and stable: the panel plugin class and its fluent configuration (`->withLibraryManagement()`, `->tenantUsing()`); the `MediaPicker` field and its configuration methods; the `MediaAsset` model as a readable, queryable Eloquent model with its `attachments` and `derivatives` relations; the ability and gate *names* (`view`/`update`/`delete`/`forceDelete`/`detach`, `uploadMedia`, `attachMedia`, `viewAllTenants`), because a host app writes those strings into its own policies; the config keys; and the Artisan command signatures `media:import` and `media:resolve-mimes`, because they end up in deploy scripts.

Internal and changeable in any release: the Delivery route's URL shape and route name, the Livewire components and view names, the derivative object key layout, the jobs and their queue payloads, and the schema beyond the columns the model exposes. The Delivery route is the sharp edge: it is the one internal surface a host app will be tempted to hardcode into a template, so `$asset->url()` on the model is the supported way to obtain a URL, and the route stays free to change (it already gained one registration per panel in ticket 17).

**Launch at `0.1.0`.** Under Composer's rules `^0.1` permits no minor bumps, so every release is visibly breaking and nobody can be surprised by one. That is an accurate statement about a surface that has never met a second consumer, on a package whose spec still has open tickets (20, 21, 23) that could plausibly add config keys or a command. 1.0 gets tagged once the package has run in one real application through a full upgrade. 0.x is not licence to be careless: the public/internal split above still governs what is deliberately not broken.

**What counts as breaking**, given the public surface is a field, a policy, a route and a config file rather than a set of signatures. Four rules, each breaking whether or not any PHP signature moved:

1. A migration requiring a data decision from the operator. An additive nullable column is not breaking; dropping, narrowing, or backfilling with a guess is.
2. A default that changes what is served or refused, even with identical code. Adding a `blocked_types` entry, tightening the strict pass, or shortening the signed TTL all change behaviour for already-stored assets under an unchanged config. This is the rule most likely to catch us, because this package's behaviour lives in its defaults far more than in its signatures, and a "harmless" denylist addition breaks a production upload silently.
3. A new fail-closed gate is breaking; a new fail-open one is not. Ticket 17's `viewAllTenants` is the shape: an ability denying by default locks out an app that never defined it.
4. A config key removed or given a new meaning is breaking. A key added with a default matching current behaviour is not.

Narrowing the Filament constraint is breaking under rule 4's spirit. Explicitly not breaking: the Delivery URL shape, view names, queue payloads, derivative key layout, and schema changes confined to columns the model does not expose. These rules live in the package's `UPGRADING.md` rather than only in the changelog.

**Left open, deliberately.** Ticket 01 deferred four things here; three are answered above. The fourth, the `MediaPicker` field's serialized value contract, is not a packaging question and would be buried in this ticket, so it graduates to its own: [Define the Media Picker Field's Serialized Value](24-media-picker-serialized-value.md). Ticket 06 settled what the picker does, never what it puts in the host's form state, and promising the field as stable API here makes that shape a promise too.

## Comments

- Resolved by grilling on 2026-08-27. The single-line Filament 4 strategy is recorded as [ADR 8](../../../docs/adr/0008-filament-4-support-rides-one-line-guarded-by-ci.md).
