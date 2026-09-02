<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/*
 * A floor under LARAVEL_VERSION, which Larastan's stub extension reads
 * unguarded.
 *
 * Larastan defines that constant while booting a Testbench application in its
 * own bootstrap file, and defines it nowhere else. Where that boot does not
 * reach the definition, which here shows up intermittently as a run dying with
 * `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` before any file is
 * analysed, the version is still sitting on the framework class the analysis
 * already has loaded. Reading it there costs nothing and is the same string,
 * so a run reports type errors rather than a bootstrap accident.
 */
if (! defined('LARAVEL_VERSION') && class_exists(Application::class)) {
    define('LARAVEL_VERSION', Application::VERSION);
}
