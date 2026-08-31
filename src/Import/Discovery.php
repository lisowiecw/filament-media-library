<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * Where a run finds the objects it adopts, and everything that follows from
 * that choice.
 *
 * There are exactly two, and they share no fields: a column run knows a host
 * model, a column and how many paths that column holds, and a traversal run
 * knows a prefix. Holding them as one record with each half nullable would
 * make a run that declared both, or neither, something the type permits and
 * only a refusal rejects. See ADR 15.
 *
 * Implementations live in this namespace and nowhere else. A third kind of
 * discovery is a decision about what the importer can honestly record, not a
 * class somebody adds downstream.
 */
abstract readonly class Discovery
{
    /**
     * Where an adopted asset was discovered, on the `host.column` convention,
     * or as the prefix that was walked where there was no host to name. This
     * is the handle a migration-window rollback selects on; it says nothing
     * about where the asset's bytes are.
     */
    abstract public function importSource(): string;

    /**
     * Whether this kind of run can write attachments at all. Traversal cannot,
     * since there is no host row to attach anything to. Whether a run that
     * could actually does is a further question the request answers, since it
     * turns on the field context and the dry run.
     */
    abstract public function canAttach(): bool;

    /**
     * How many paths one unit of discovery yields, which the report names so
     * two runs can be read against each other. A traversal walks one key at a
     * time and is single in the only sense the word has there.
     */
    abstract public function cardinality(): Cardinality;
}
