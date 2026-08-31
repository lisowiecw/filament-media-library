<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * The real discovery: a declared host model and column hold the legacy paths.
 *
 * The row holding the path is the row that knows who owned it and which field
 * it filled, so this is the only source that can produce attachments at all.
 */
final readonly class ColumnDiscovery extends Discovery
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $model,
        public string $column,
        private Cardinality $cardinality = Cardinality::Single,
    ) {}

    public function cardinality(): Cardinality
    {
        return $this->cardinality;
    }

    public function importSource(): string
    {
        return $this->model.'.'.$this->column;
    }

    public function canAttach(): bool
    {
        return true;
    }
}
