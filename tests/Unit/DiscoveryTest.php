<?php

declare(strict_types=1);

use Lisowiecw\MediaLibrary\Import\Cardinality;
use Lisowiecw\MediaLibrary\Import\ColumnDiscovery;
use Lisowiecw\MediaLibrary\Import\TraversalDiscovery;
use Lisowiecw\MediaLibrary\Tests\Fixtures\LegacyRecord;

it('names a column run by the host and column it read', function (): void {
    $discovery = new ColumnDiscovery(LegacyRecord::class, 'cover_path', Cardinality::Single);

    expect($discovery->importSource())->toBe(LegacyRecord::class.'.cover_path');
});

it('names a traversal run by the prefix it walked', function (): void {
    expect((new TraversalDiscovery('legacy/uploads'))->importSource())->toBe('disk:legacy/uploads');
});

it('lets a column run attach, and never lets a traversal one', function (): void {
    expect((new ColumnDiscovery(LegacyRecord::class, 'cover_path', Cardinality::Single))->canAttach())->toBeTrue()
        ->and((new TraversalDiscovery('legacy/uploads'))->canAttach())->toBeFalse();
});
