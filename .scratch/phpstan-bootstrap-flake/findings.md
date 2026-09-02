## Where the constant goes missing

Both candidates were tested. The answer is the first one, and only in the window before the floor worked.

**It is not a different constant.** `LarastanStubFilesExtension` reads `LARAVEL_VERSION` unqualified from inside `namespace Larastan\Larastan`, with no `use const`. PHP resolves an unqualified constant to the namespaced one first and then falls back to the global one, and nothing anywhere defines `Larastan\Larastan\LARAVEL_VERSION`. Larastan's own `bootstrap.php` and `bin/phpstan-bootstrap.php` are both unnamespaced, so both define the global one, which is the one the extension ends up reading. The error message naming the namespaced constant is just how PHP reports a failed unqualified lookup.

**The bootstrap file runs, and it runs first.** PHPStan 2.2.10 defers `bootstrapFiles` in the analyse flow and executes them once per process at three points: the main thread before an in-process analysis and after the workers of a parallel one (`AnalyserRunner`), and every worker, spawned or forked (`WorkerRunner`). `ResultCacheManager::restore()` deliberately does not run the stub extensions, reading recorded hashes instead. Instrumenting `LarastanStubFilesExtension::getFiles()` with a backtrace shows every call site sitting after one of those runs:

- `StubPhpDocProvider::initializeKnownElements()` in each forked worker, during file analysis
- `AnalyseApplication.php:86` `getProjectStubFiles()` in the main thread, after `runAnalyser` returned
- `ResultCacheManager::save()`, after the analysis

In every one, the probe recorded the floor as having already run. There is no path on this version that reads the constant before the bootstrap files execute.

So the failure needs a run where nothing defines it. Larastan's `bootstrap.php` defines it only inside a `try` and only when `$app` got set; a boot that throws exits with its own framework banner, but a boot that never sets `$app` completes silently and leaves the constant undefined. That was unguarded until c79f2e3, and c79f2e3's floor never fired (it guarded on a class the bootstrap scope cannot see) until 1fd97d1. The failures on record are from that window.

## Reproducing it

Deterministic with the floor neutered: comment out the `define` in `vendor/larastan/larastan/bootstrap.php` and the `defined()` guard body in `bin/phpstan-bootstrap.php`, then run `composer analyse`. It dies with `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` at `LarastanStubFilesExtension.php:26` followed by PHPStan's usage text, exactly as reported.

Not reproducible with the floor in place: thirteen runs, cold `build/phpstan`, warm, and a cache invalidated by touching a source file, all passed and all reported type errors rather than a bootstrap accident. That is the recorded reason, and it is consistent with the mechanism above rather than with a race.

## What changed

The floor stays; it is the fix, and bootstrap files are the place that always runs before a stub extension is asked anything. Two things were added.

`tests/Unit/PhpstanBootstrapTest.php` runs the floor the way PHPStan does, in a bare PHP subprocess with no autoloader registered, because that scope is exactly what left the c79f2e3 version never firing and nothing caught it. Three cases: it defines a version with no autoloader in scope, the version is the one Composer recorded for the framework, and an already-defined constant is left alone.

The floor still reads `pretty_version` alone, and the reason is now written down beside it. Falling back to the normalized `version` next to it looks like closing a hole and is not: that field reads `dev-main` for a commit-reference install, and a `version_compare` against `dev-main` answers no for every stub directory, so the run would analyse without Larastan's stubs and report type errors that are not there. Defining nothing is the louder failure and the better one.

No retry, no version pin.
