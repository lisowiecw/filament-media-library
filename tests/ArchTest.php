<?php

declare(strict_types=1);

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
