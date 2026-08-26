# Research: Platform and Package Contract

**Question:** What Laravel 13, PHP, Filament v5, and best-effort Filament v4 support matrix and package boundary should the specification guarantee?

**Research date:** 2026-08-26. Sources below were accessed on that date. Versioned documentation URLs and source branches are identified so that moving `5.x`/`4.x` branches are not confused with immutable release tags.

## Executive conclusion

The defensible primary support target is **Laravel 13 + PHP 8.3+ + Filament 5.x + Livewire 4.x**. The package should be a normal Laravel library with a Laravel service provider and a Filament panel plugin. Its public integration should be the documented Filament plugin contract (`getId()`, `register(Panel)`, `boot(Panel)`), panel registration (`$panel->plugin(...)`), and a small package-owned field/API surface for media selection. The package should use Laravel filesystem contracts rather than provider-specific APIs.

Filament 4 should be treated as **best effort and explicitly non-guaranteed**. The official v4 and v5 plugin contracts, panel plugin registration, and base field construction are materially aligned in the inspected official branches, so no v4-specific architecture is justified. Compatibility should be limited to the shared documented surface and tested separately; v5-only behavior, Livewire 4 assumptions, and any v5-only component/schema APIs may be documented as unsupported on v4.

## Verified facts

### Platform and release support

- Laravel 13.x requires a minimum PHP version of 8.3. Laravel's release policy says bug fixes are provided for 18 months and security fixes for two years; Laravel also says additional libraries receive only the latest major version's support. **Source:** [Laravel 13 release notes](https://laravel.com/docs/13.x/releases), section “Laravel 13 / PHP 8.3” and “Support Policy” (official Laravel documentation, versioned 13.x page; accessed 2026-08-26).
- The Laravel 13 framework package declares Composer requirement `php: ^8.3`. **Source:** [Laravel framework 13.x composer.json](https://raw.githubusercontent.com/laravel/framework/13.x/composer.json), official `laravel/framework` source branch (accessed 2026-08-26).
- Filament's official v5 installation documentation lists PHP 8.2+ and Laravel v11.28+ as requirements. **Source:** [Filament v5 installation](https://filamentphp.com/docs/5.x/introduction/installation), official versioned documentation (accessed 2026-08-26).
- Filament's official v4 installation documentation lists PHP 8.2+ and Laravel v11.28+ as requirements. **Source:** [Filament v4 installation](https://filamentphp.com/docs/4.x/introduction/installation), official versioned documentation (accessed 2026-08-26).
- The official Filament v5 panel package declares `php: ^8.2`; the official support package declares `livewire/livewire: ^4.1`. **Sources:** [Filament v5 panel composer.json](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/panels/composer.json) and [Filament v5 support composer.json](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/support/composer.json), official `filamentphp/filament` `5.x` source branch (accessed 2026-08-26).
- The official Filament v4 panel package declares `php: ^8.2`; the official support package declares `livewire/livewire: ^3.5`. **Sources:** [Filament v4 panel composer.json](https://raw.githubusercontent.com/filamentphp/filament/4.x/packages/panels/composer.json) and [Filament v4 support composer.json](https://raw.githubusercontent.com/filamentphp/filament/4.x/packages/support/composer.json), official `filamentphp/filament` `4.x` source branch (accessed 2026-08-26).
- PHP's official supported-versions page records PHP 8.3 security support through 2027-12-31. This is lifecycle context, not a substitute for Laravel's package requirement. **Source:** [PHP supported versions](https://www.php.net/supported-versions.php), official PHP documentation (accessed 2026-08-26).

### Public Filament extension points

- Filament plugins are Laravel packages installed through Composer. The official plugin documentation describes them as reusable features that can be added to individual panels and configured differently per panel. **Sources:** [Filament v5 plugin overview](https://filamentphp.com/docs/5.x/plugins/overview) and [Filament v4 plugin overview](https://filamentphp.com/docs/4.x/plugins/overview), official versioned documentation (accessed 2026-08-26).
- The official plugin-development documentation describes `register()` as the place for panel configuration and registration, and `boot()` as running when the registered panel is actually in use. **Sources:** [Filament v5 panel plugins](https://filamentphp.com/docs/5.x/plugins/panel-plugins) and [Filament v4 panel plugins](https://filamentphp.com/docs/4.x/plugins/panel-plugins), official versioned documentation (accessed 2026-08-26).
- The official `Filament\Contracts\Plugin` interface on both branches requires `getId(): string`, `register(Panel $panel): void`, and `boot(Panel $panel): void`. **Sources:** [v5 Plugin.php](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/panels/src/Contracts/Plugin.php) and [v4 Plugin.php](https://raw.githubusercontent.com/filamentphp/filament/4.x/packages/panels/src/Contracts/Plugin.php), official source branches (accessed 2026-08-26).
- The official `HasPlugins` trait on both branches exposes `Panel::plugin(Plugin $plugin): static`, `plugins(array $plugins): static`, `getPlugin(string $id): Plugin`, and `hasPlugin(string $id): bool`. `plugin()` invokes `register()` and stores the plugin by `getId()`. **Sources:** [v5 HasPlugins](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/panels/src/Panel/Concerns/HasPlugins.php) and [v4 HasPlugins](https://raw.githubusercontent.com/filamentphp/filament/4.x/packages/panels/src/Panel/Concerns/HasPlugins.php), official source branches (accessed 2026-08-26).
- Filament's forms documentation says custom fields can be created, and fields are constructed with a static `make()` method. The official `Field` base class on both branches provides `Field::make(?string $name = null): static`. **Sources:** [Filament v5 custom fields](https://filamentphp.com/docs/5.x/forms/custom-fields), [Filament v4 custom fields](https://filamentphp.com/docs/4.x/forms/custom-fields), [v5 Field.php](https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/forms/src/Components/Field.php), and [v4 Field.php](https://raw.githubusercontent.com/filamentphp/filament/4.x/packages/forms/src/Components/Field.php) (official documentation/source, accessed 2026-08-26).
- Laravel's package-development documentation describes service providers as the place to register package resources and explains package discovery through Composer metadata. **Source:** [Laravel package development](https://laravel.com/docs/13.x/packages), official Laravel 13 documentation (accessed 2026-08-26).
- Composer defines `require` as the package dependency map, requires a published library's `name`, defaults package `type` to `library`, and supports PSR-4 mappings under `autoload`. **Source:** [Composer schema](https://getcomposer.org/doc/04-schema.md), sections `name`, `type`, `require`, `autoload`, and `PSR-4` (official Composer documentation; accessed 2026-08-26).

## Recommended contract

### Support matrix

| Layer | Guaranteed target | Best-effort compatibility | Explicitly outside the contract |
| --- | --- | --- | --- |
| PHP | `^8.3` | None below 8.3, because Laravel 13 requires it | PHP 8.2 and older |
| Laravel | `^13.0` | None for Laravel 10-12 in the Laravel 13 package line | Laravel versions outside the declared Composer range |
| Filament | `^5.0` | `^4.0` only where the shared plugin/field APIs work and CI verifies it | Filament 3 and older; undocumented internal APIs |
| Livewire | Filament v5's supported `^4.1` line | Filament v4's supported `^3.5` line only for the v4 compatibility path | Other major lines |

This matrix intentionally distinguishes **the package's declared platform** from transitive framework facts: the package should declare the PHP/Laravel/Filament requirements that it actually supports, while Composer resolves Filament's Livewire dependency. If one package version must install on both Filament majors, use a Composer range such as `filament/filament: ^4.0|^5.0` only after testing both branches; otherwise publish a v5-first line and a separately tested compatibility release. Do not claim Laravel 13 plus Filament 4 merely because the Filament plugin interface is shared.

### Package boundary

The package should contain:

- domain models, migrations, relationships, policies, and application services for media assets;
- storage operations expressed through Laravel's filesystem abstraction;
- package configuration, translations, views, routes, and a Laravel service provider;
- a Filament panel plugin that registers package-owned resources, pages, assets, and configuration;
- a package-owned media picker/form field or field factory that consumers can place in a Filament schema;
- documented configuration and stable interfaces for selection, attachment, authorization, and URL/value presentation.

The package should not contain provider-specific Cloudflare R2 code, resource-specific blog-post logic, or dependencies on Filament internals. R2 should be consumed as a configured Laravel filesystem disk; the package contract should expose disk/visibility behavior through Laravel's filesystem APIs. This boundary follows Laravel package guidance and Composer's normal `library`/autoload model, while keeping Filament-specific behavior behind the panel plugin and field surface. **Sources:** [Laravel package development](https://laravel.com/docs/13.x/packages), [Composer schema](https://getcomposer.org/doc/04-schema.md), and [Laravel filesystem](https://laravel.com/docs/13.x/filesystem) (official sources, accessed 2026-08-26).

### Public plugin API

Guarantee only these public integration points:

1. A plugin class implementing `Filament\Contracts\Plugin`.
2. A stable plugin identifier returned by `getId()`.
3. `register(Panel $panel)` for panel-scoped registration/configuration.
4. `boot(Panel $panel)` for runtime setup that should occur when the panel is used.
5. Consumer registration through `->plugin(new MediaLibraryPlugin(...))` on the panel.
6. Package-owned field construction through a documented `make()` factory or custom `Field` subclass, with the field's value contract documented independently of Filament internals.

Do not make consumers depend on `HasPlugins`, concrete panel internals, Livewire component internals, generated view names, or undocumented methods. The interface and registration call are documented and source-verified on both Filament majors; the concrete field implementation should remain replaceable.

### Suggested Composer contract

For a v5/Laravel 13 primary line, the minimum defensible declarations are:

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

Add only direct runtime dependencies actually used by the package. If v4 compatibility is delivered in the same package line, broaden the Filament requirement only after a two-major test matrix, and document that the package's own PHP/Laravel floor remains PHP 8.3/Laravel 13. A separate v4 compatibility branch/release is cleaner when v4 requires incompatible component behavior or when the package must support Laravel versions below 13.

The exact package name, PSR-4 namespace, service-provider discovery metadata, and dependency ranges are recommendations to be finalized with the package namespace and release strategy. The Composer facts behind this shape are verified in the Composer schema cited above.

## Filament v4 differences that may remain unsupported

These are appropriate to document as unsupported rather than allowing them to shape the v5 design:

- **Livewire 3-specific behavior:** v4 resolves Livewire `^3.5`, while v5 resolves `^4.1`; do not promise that Livewire 3 behavior, hooks, serialization, or component internals are supported by the v5 contract. **Sources:** official v4/v5 support manifests cited above.
- **Version-specific undocumented internals:** no support promise for internal Filament classes, generated view paths, private traits, or behavior absent from the shared public plugin/field documentation. **Sources:** official v4/v5 plugin and form documentation cited above.
- **v5-only features:** any feature documented only under the v5 docs or requiring a v5-only component/schema API may be unavailable in v4. The v4 adapter may omit it or fail with a documented compatibility message.
- **Exact visual or interaction parity:** v4 best effort should mean the documented media workflow and value contract work where the shared APIs permit it, not pixel-identical markup or identical Livewire behavior.
- **Old Laravel/PHP combinations:** this Laravel 13 package line should not widen its platform floor to accommodate historical Filament 4 applications. Supporting Laravel 10-12 would be a separate release/support decision, not an implication of the shared plugin interface.

The v5 design should still avoid relying on these differences. Keep the domain/storage contract framework-level, keep panel registration in `register()`/`boot()`, and isolate the field rendering/Livewire adapter so a v4 compatibility implementation can be tested without changing the domain API.

## Unresolved release decisions

- Choose whether v4 compatibility ships in the same Composer line (`^4.0|^5.0`) or as a separately tested branch/release. The sources establish the available APIs and dependency facts, but they do not prescribe a package's release policy.
- Confirm the exact stable Filament release tags and the package namespace before publishing the final Composer constraints. Branch manifests are current source evidence, not immutable release metadata.
- Define the field's serialized value and relationship contract in the downstream picker/model tickets; Filament's `Field::make()` API does not decide the media domain model.
