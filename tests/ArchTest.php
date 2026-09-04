<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Tests\Support\ColumnPlacements;
use Lisowiecw\MediaLibrary\Tests\Support\SharedFilamentApi;
use Lisowiecw\MediaLibrary\Tests\Support\VersionSniffs;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Lisowiecw\MediaLibrary')
    ->toUseStrictTypes();

it('stays Filament 5 first, with no version sniffing in the source', function () {
    expect(VersionSniffs::in(dirname(__DIR__).'/src'))->toBe([]);
});

it('imports only Filament symbols the installed major declares', function () {
    $imports = SharedFilamentApi::importsIn(dirname(__DIR__).'/src');

    expect($imports)->not->toBeEmpty()
        ->and(SharedFilamentApi::missing($imports))->toBe([]);
});

// The package's migrations carry no timestamp prefixes, so Laravel runs them
// in alphabetical filename order, and nothing about that order is meaningful.
// A migration that places its column relative to another migration's column
// therefore has a dependency the filenames do not express (ADR 20).
it('places no migration column relative to another migration', function () {
    $migrations = dirname(__DIR__).'/database/migrations';

    expect(ColumnPlacements::migrationsIn($migrations))->not->toBeEmpty()
        ->and(ColumnPlacements::in($migrations))->toBe(
            [],
            'A migration must not place a column against a column another migration creates: '
            .'the filenames carry no order, and sqlite discards placement so the suite cannot '
            .'see it. Drop the placement rather than reordering the files (ADR 20).',
        );
});
