<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * How many legacy paths one column holds.
 *
 * It is declared on the run and never inferred from the values, because a
 * multi-value column whose first row happens to hold one path is still a
 * multi-value column, and a single-value column holding text that parses as an
 * array is a column the tool has misread. Reading the shape off the data would
 * make the answer depend on which rows the run happened to see first.
 */
enum Cardinality: string
{
    /** The column holds one path, as text. */
    case Single = 'single';

    /** The column holds a list of paths, in the order they are attached. */
    case Many = 'many';
}
