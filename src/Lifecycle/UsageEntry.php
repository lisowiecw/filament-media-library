<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Lifecycle;

/**
 * One line of a Usage list: something that references a Media Asset, said in
 * the terms a person reviewing a delete needs.
 *
 * It is deliberately flat and already resolved. Whatever reads it (a panel, a
 * blocked delete, a command) is showing it rather than querying further, and
 * a host row whose model has since been deleted still has to read as a use,
 * which it could not if the entry carried the model itself.
 */
final readonly class UsageEntry
{
    public function __construct(
        public int $attachmentId,
        public string $label,
        public ?string $field,
        public ?string $hostType,
        public ?string $hostId,
        public bool $isExternal,
    ) {}
}
