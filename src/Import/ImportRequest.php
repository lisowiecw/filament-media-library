<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Lisowiecw\MediaLibrary\Enums\Visibility;

/**
 * What one import run was asked to do: where the legacy paths are discovered,
 * the disk they resolve against, and the field context they belong to.
 *
 * Everything that differs between a column run and a traversal run lives on
 * the Discovery rather than here, so this record holds only what both runs
 * share and no field on it is nullable for the sake of the other kind. See
 * ADR 15.
 *
 * The tenant is null when the run was told `none`, which is the operator
 * saying these bytes belong to no one rather than saying nothing. The command
 * makes them say one or the other.
 */
final readonly class ImportRequest
{
    public function __construct(
        public Discovery $discovery,
        public string $disk,
        public ?string $tenant = null,
        public ?string $field = null,
        public ?string $uploader = null,
        public ?Visibility $visibility = null,
        public bool $copy = false,
        public bool $sniff = false,
        public bool $dryRun = false,
        public int $chunk = 500,
    ) {}

    /**
     * Where an adopted asset was discovered. The handle is the discovery's to
     * name, since it is the half of the run that knows what it read.
     */
    public function importSource(): string
    {
        return $this->discovery->importSource();
    }

    /**
     * Whether this run writes attachments at all. A run that declared no field
     * does not: an attachment outside a field context is an External reference,
     * which is a different thing and not one an import invents. Neither does a
     * dry run, which writes nothing anywhere.
     */
    public function attaches(): bool
    {
        return $this->discovery->canAttach() && $this->field !== null && ! $this->dryRun;
    }
}
