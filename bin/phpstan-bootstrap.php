<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/*
 * A floor under LARAVEL_VERSION, which Larastan's stub extension reads
 * unguarded.
 *
 * Larastan defines that constant while booting a Testbench application in its
 * own bootstrap file, and defines it nowhere else. Where that boot does not
 * reach the definition, which here shows up as a run dying with
 * `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` before any file is
 * analysed, this puts the same string in the same place, so a run reports type
 * errors rather than a bootstrap accident.
 *
 * The version is read from Composer's own installed metadata rather than from
 * the framework class, because a bootstrap file runs in PHPStan's scope and
 * the analysed project's autoloader is not registered there: asking for the
 * class answers no however installed the framework is, which is what left this
 * floor never firing. The class is still preferred where something has already
 * loaded it.
 *
 * The constant the extension reads is this one. It reads it unqualified from
 * inside `Larastan\Larastan`, which PHP resolves to the global constant once
 * no namespaced one exists, so defining it here is defining the one that is
 * read; there is no second constant to miss.
 *
 * PHPStan defers bootstrap files in the analyse flow, running them per process
 * at the points that need them: the main thread before an in-process analysis,
 * every worker, and the main thread after the workers of a parallel one. Every
 * place the stub extension is reached sits after one of those, so this floor
 * has run in whichever process asks. Nothing here may be lazy: the value must
 * be settled by the time the file returns.
 */
if (! defined('LARAVEL_VERSION')) {
    $version = null;

    if (class_exists(Application::class, false)) {
        $version = Application::VERSION;
    } elseif (is_file($installed = __DIR__.'/../vendor/composer/installed.php')) {
        /** @var array{versions: array<string, array{pretty_version?: string}>} $packages */
        $packages = require $installed;

        // Only pretty_version, never the normalized `version` beside it: that
        // one reads `dev-main` for a commit-reference install, and a
        // version_compare against `dev-main` answers no for every stub
        // directory, which is a run that quietly analyses without Larastan's
        // stubs. Defining nothing is the louder failure, and the better one.
        $version = $packages['versions']['laravel/framework']['pretty_version'] ?? null;
        $version = $version === null ? null : ltrim($version, 'v');
    }

    if ($version !== null) {
        define('LARAVEL_VERSION', $version);
    }
}
