<?php

declare(strict_types=1);

/*
 * The floor under LARAVEL_VERSION runs as a PHPStan bootstrap file, in a
 * process where the analysed project's autoloader is not registered. These
 * cases run it the same way, in a bare PHP subprocess, because that scope is
 * the whole point: an earlier version of the file guarded on a framework class
 * and so never fired.
 */

/**
 * Runs the floor in a bare PHP subprocess and returns its exit status and the
 * version it settled, or `<undefined>` where it settled none. $prelude is PHP
 * that runs ahead of it, for the case where something else got there first.
 *
 * A closure rather than a function, because a test file's functions are global
 * and a name another file also chose is a fatal error rather than a failure.
 *
 * @var Closure(string=): array{int, string}
 */
$runFloorInBareProcess = function (string $prelude = ''): array {
    $floor = var_export(dirname(__DIR__, 2).'/bin/phpstan-bootstrap.php', true);

    $script = <<<PHP
        <?php
        {$prelude}
        require {$floor};
        echo defined('LARAVEL_VERSION') ? LARAVEL_VERSION : '<undefined>';
        PHP;

    $file = sys_get_temp_dir().'/phpstan-floor-'.uniqid().'.php';
    file_put_contents($file, $script);

    try {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    } finally {
        @unlink($file);
    }
};

it('defines the version with no autoloader in scope', function () use ($runFloorInBareProcess): void {
    [$status, $output] = $runFloorInBareProcess();

    expect($status)->toBe(0)
        ->and($output)->toMatch('/^\d+\.\d+/');
});

it('reads the version Composer recorded for the framework', function () use ($runFloorInBareProcess): void {
    /** @var array{versions: array<string, array{pretty_version?: string}>} $packages */
    $packages = require dirname(__DIR__, 2).'/vendor/composer/installed.php';

    $recorded = $packages['versions']['laravel/framework']['pretty_version'] ?? null;

    [, $output] = $runFloorInBareProcess();

    expect($recorded)->not->toBeNull()
        ->and($output)->toBe(ltrim($recorded, 'v'));
});

it('leaves a version another bootstrap file already settled', function () use ($runFloorInBareProcess): void {
    [, $output] = $runFloorInBareProcess("define('LARAVEL_VERSION', '99.0.0');");

    expect($output)->toBe('99.0.0');
});
