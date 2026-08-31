<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Tests\Support\CompatibilityMatrix;

$root = dirname(__DIR__, 2);
$workflow = $root.'/.github/workflows/tests.yml';
$readme = $root.'/README.md';

$fixture = <<<'YAML'
jobs:
  tests:
    strategy:
      fail-fast: true
      matrix:
        php: [8.3, 8.4]
        laravel: [13.*]
        filament: [5.*, 4.*]
    steps:
      - run: composer test:unit
  matrix-result:
    needs: tests
    if: always()
    steps:
      - run: exit 0
YAML;

it('reads the tested versions out of a CI matrix', function () use ($fixture) {
    $matrix = CompatibilityMatrix::fromYaml($fixture);

    expect($matrix->php())->toBe(['8.3', '8.4'])
        ->and($matrix->laravel())->toBe(['13.x'])
        ->and($matrix->filament())->toBe(['5.x', '4.x']);
});

it('guarantees the newest tested Filament whatever order the matrix lists', function () use ($fixture) {
    $table = CompatibilityMatrix::fromYaml($fixture)->table('0.x');

    expect($table)->toStartWith('| Package | PHP | Laravel | Filament |')
        ->and($table)->toContain('| 0.x | 8.3, 8.4 | 13.x | 5.x (guaranteed), 4.x (best effort) |');
});

it('reports a matrix that tolerates a red leg', function () {
    $tolerant = CompatibilityMatrix::fromYaml(<<<'YAML'
    jobs:
      tests:
        strategy:
          matrix:
            php: [8.3]
            laravel: [13.*]
            filament: [5.*]
        steps:
          - run: composer test:unit
            continue-on-error: true
    YAML);

    expect($tolerant->permitsFailure())->toBeTrue()
        ->and($tolerant->isGated())->toBeFalse();
});

it('keeps the README table in step with the matrix', function () use ($workflow, $readme) {
    $rendered = CompatibilityMatrix::fromWorkflow($workflow)->table('0.x');

    expect(CompatibilityMatrix::tableIn(file_get_contents($readme)))->toBe($rendered);
});

it('lets no tested version fail on its own', function () use ($workflow) {
    $matrix = CompatibilityMatrix::fromWorkflow($workflow);

    expect($matrix->permitsFailure())->toBeFalse()
        ->and($matrix->isGated())->toBeTrue()
        ->and($matrix->filament())->not->toBeEmpty();
});
