<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Illuminate\Database\Eloquent\Model;
use Lisowiecw\MediaLibrary\Enums\Visibility;

/**
 * What one import run was asked to do: the host model and column that hold the
 * legacy paths, the disk those paths resolve against, and the field context
 * they belong to.
 *
 * Discovery is declared rather than inferred, because the row that holds the
 * path is the same row that knows who owned it and which field it filled, and
 * a bare key on a bucket knows neither.
 */
final readonly class ImportRequest
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public string $column,
        public string $disk,
        public ?string $field = null,
        public ?string $uploader = null,
        public ?Visibility $visibility = null,
        public bool $copy = false,
        public bool $sniff = false,
        public bool $dryRun = false,
        public int $chunk = 500,
    ) {}

    /**
     * Where an adopted asset was discovered, on the `host.column` convention.
     * This is the handle a migration-window rollback selects on; it says
     * nothing about where the asset's bytes are.
     */
    public function importSource(): string
    {
        return $this->model.'.'.$this->column;
    }
}
