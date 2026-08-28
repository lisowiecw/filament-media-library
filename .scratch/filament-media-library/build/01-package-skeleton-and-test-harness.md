# 01: Package Skeleton and Test Harness

**What to build:** an application can `composer require` the package, add `MediaLibraryPlugin::make()` to a panel, publish `config/media-library.php`, and the panel boots. Nothing user-facing yet, but the harness every later ticket builds on exists and is green.

**Blocked by:** None (can start immediately).

**Status:** resolved

- [x] Composer package `lisowiecw/filament-media-library`, namespace `Lisowiecw\MediaLibrary\`, PSR-4 autoload
- [x] Declared requirements: `php ^8.3`, `laravel/framework ^13.0`, `filament/filament ^4.0|^5.0`, `enshrined/svg-sanitize ^0.22`; no Flysystem or AWS SDK constraint
- [x] Service provider registering config (`media-library`), migrations, views and translations under the `media-library::` namespace
- [x] `MediaLibraryPlugin` implements `Filament\Contracts\Plugin` with `getId()`, `register(Panel)`, `boot(Panel)`, and a static `make()`
- [x] Orchestra Testbench hosts a real panel with the plugin registered, plus a fixture host model for later tickets
- [x] Pest configured, `Storage::fake()` used for the disk, one test asserting the panel boots with the plugin registered
- [x] Config file carries the keys the spec names, with the spec's defaults, and the storage defaults are read through the `Storage` facade only
