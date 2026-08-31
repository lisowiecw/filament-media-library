<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Enums\Visibility;

/**
 * What one import run was asked to do: where the legacy paths are discovered,
 * how many of them one column holds, the disk they resolve against, and the
 * field context they belong to.
 *
 * Discovery is declared rather than inferred, because the row that holds the
 * path is the same row that knows who owned it and which field it filled, and
 * a bare key on a bucket knows neither. Traversal is the fallback for a layout
 * that has no such row, and it is the reason the model and column are nullable
 * here rather than the reason they are optional.
 */
final readonly class ImportRequest
{
    /**
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public ?string $model,
        public ?string $column,
        public string $disk,
        public ?string $field = null,
        public ?string $uploader = null,
        public ?Visibility $visibility = null,
        public bool $copy = false,
        public bool $sniff = false,
        public bool $dryRun = false,
        public int $chunk = 500,
        public DiscoverySource $source = DiscoverySource::Column,
        public ?string $prefix = null,
        public Cardinality $cardinality = Cardinality::Single,
    ) {}

    /**
     * Where an adopted asset was discovered, on the `host.column` convention,
     * or as the prefix that was walked where there was no host to name. This is
     * the handle a migration-window rollback selects on; it says nothing about
     * where the asset's bytes are.
     */
    public function importSource(): string
    {
        return $this->source === DiscoverySource::Disk
            ? 'disk:'.($this->prefix ?? '')
            : $this->model.'.'.$this->column;
    }

    /**
     * Whether this run writes attachments at all. Traversal never does, since
     * there is no host row, and neither does a run that declared no field: an
     * attachment outside a field context is an External reference, which is a
     * different thing and not one an import invents.
     */
    public function attaches(): bool
    {
        return $this->source === DiscoverySource::Column && $this->field !== null && ! $this->dryRun;
    }
}
