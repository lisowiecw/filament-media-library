# Define Platform and Package Contract

Status: resolved
Type: research
Blocked by:

## Question

What Laravel 13, PHP, Filament v5, and best-effort Filament v4 support matrix and package boundary should the specification guarantee? Identify the public plugin APIs, version constraints, and which v4 differences may be documented as unsupported rather than shaping the v5 design.

## Answer

Guarantee a primary package line for PHP `^8.3`, Laravel `^13.0`, Filament `^5.0`, and Filament's supported Livewire 4 line. Treat Filament 4 as best-effort compatibility only, limited to the shared documented plugin and field APIs and verified by a separate compatibility test matrix. Do not lower the Laravel or PHP floor for historical Filament 4 applications.

The package boundary is a normal Laravel library with a service provider, configuration, migrations, domain models, policies, media services, filesystem-backed storage operations, and a Filament panel plugin. R2 remains a configured Laravel filesystem disk; provider-specific APIs and resource-specific consumer logic stay outside the package. The public Filament surface is a plugin implementing `Filament\\Contracts\\Plugin` with `getId()`, `register(Panel)`, and `boot(Panel)`, panel registration via `->plugin(...)`, plus a package-owned media field or factory with a separately documented value contract. Consumers must not depend on Filament internals, Livewire component internals, or generated view names.

Composer should declare the platform and direct runtime dependencies it actually guarantees. The starting v5 contract is:

```json
{
	"type": "library",
	"require": {
		"php": "^8.3",
		"laravel/framework": "^13.0",
		"filament/filament": "^5.0"
	}
}
```

The three constraints above are the *only* versions this package declares. Every other version in the dependency tree is transitive: it arrives through `laravel/framework` and is governed by Laravel's own constraints, not by this plugin. In particular, storage is reached solely through Laravel's `Storage` facade and the `Illuminate\Contracts\Filesystem` contracts, so the package neither requires nor pins `league/flysystem` or any Flysystem adapter — Laravel 13 currently resolves those at `^3.25.1`. When a specification or research note cites a Flysystem, Livewire, or AWS SDK version, it is describing the behaviour of what Laravel installs, never a support target of this plugin. `^5.0` refers to Filament and to nothing else.

Filament 4 differences that may remain unsupported are Livewire 3-specific behavior, v5-only component or schema features, undocumented/internal APIs, and exact visual or interaction parity. Preserve the v5-first domain and storage design by isolating any v4 field or Livewire adapter.

The same-line versus separate-release strategy for Filament 4, exact release tags, package namespace, and the field's serialized value remain downstream decisions. Full cited evidence is in [research-01-platform-and-package-contract.md](../research-01-platform-and-package-contract.md).

## Comments

- Resolved from the cited official Laravel, Filament, PHP, and Composer sources in the linked research note on 2026-08-26.
- Amended on 2026-08-26 to distinguish declared dependencies from transitive ones, after the Flysystem `3.x` citations in ticket 08's research were read as a plugin support target.
