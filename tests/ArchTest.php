<?php

declare(strict_types=1);

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
