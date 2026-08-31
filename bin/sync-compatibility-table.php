<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Tests\Support\CompatibilityMatrix;

require __DIR__.'/../vendor/autoload.php';
// The class lives under tests/, which a --no-dev install does not autoload.
require __DIR__.'/../tests/Support/CompatibilityMatrix.php';

$root = dirname(__DIR__);

$package = '0.x';
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

if (is_array($composer) && is_string($composer['version'] ?? null)) {
    $package = explode('.', $composer['version'])[0].'.x';
}

$table = CompatibilityMatrix::fromWorkflow($root.'/.github/workflows/tests.yml')->table($package);
$readme = (string) file_get_contents($root.'/README.md');

file_put_contents($root.'/README.md', CompatibilityMatrix::write($readme, $table));

echo "Compatibility table synced from the CI matrix.\n";
