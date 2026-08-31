<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Exceptions;

use Lisowiecw\MediaLibrary\Import\Cardinality;
use Lisowiecw\MediaLibrary\Import\ImportReport;
use RuntimeException;

/**
 * The run cannot honestly continue. Everything an import declines row by row is
 * an omission in its report; this is for what makes the whole run meaningless:
 * a declaration that describes no run, or a column whose shape says the run is
 * reading every row the wrong way.
 */
class ImportRefused extends RuntimeException
{
    /**
     * What the run had already done when it was refused, where it got far
     * enough to have done anything. A refusal on row ten thousand still leaves
     * the operator the ten thousand adoptions before it, which is the thing a
     * re-run is diffed against.
     */
    public ?ImportReport $report = null;

    public function withReport(ImportReport $report): self
    {
        $this->report = $report;

        return $this;
    }

    public static function unknownDisk(string $disk): self
    {
        return new self('The disk "'.$disk.'" is not configured. Name the disk the legacy paths '
            .'resolve against: an import never guesses one, because the same path is meaningful on several.');
    }

    public static function unknownModel(string $model): self
    {
        return new self('"'.$model.'" is not an Eloquent model. Name the host model whose column holds the legacy paths.');
    }

    public static function unknownVisibility(string $named): self
    {
        return new self('Visibility is public or private, not "'.$named.'".');
    }

    public static function unknownColumn(string $model, string $column): self
    {
        return new self('"'.$model.'" has no "'.$column.'" column to read legacy paths from.');
    }

    public static function unknownCardinality(string $named): self
    {
        return new self('Cardinality is single or many, not "'.$named.'".');
    }

    public static function unknownSource(string $named): self
    {
        return new self('An import discovers paths from a column or from a disk, not from "'.$named.'".');
    }

    /**
     * The declared shape and the column disagree, which is never one bad row:
     * every other row is about to be read the same wrong way.
     */
    public static function shapeMismatch(string $rowLabel, Cardinality $declared): self
    {
        return $declared === Cardinality::Single
            ? new self('The column was declared single, but '.$rowLabel.' holds a list. Re-run with --cardinality=many.')
            : new self('The column was declared many, but '.$rowLabel.' holds one bare path. Re-run with --cardinality=single.');
    }

    public static function nestedShape(string $rowLabel): self
    {
        return new self('The column on '.$rowLabel.' holds an object rather than paths. An import reads keys, '
            .'and will not guess which part of a structure is one.');
    }

    public static function malformedList(string $rowLabel): self
    {
        return new self('The column on '.$rowLabel.' opens as a list but does not parse as one. '
            .'An import reads the column as it is written, and will not repair it.');
    }

    public static function urlValue(string $rowLabel, string $value): self
    {
        return new self('The column on '.$rowLabel.' holds a URL, "'.$value.'", rather than a key on the disk. '
            .'The host it names may not be this disk at all, so nothing is adopted for it.');
    }

    public static function prefixRequired(): self
    {
        return new self('Disk traversal needs a prefix: name it with --prefix. A whole bucket is not something '
            .'to adopt, and traversal is a fallback for a layout that has no column to read.');
    }

    /**
     * Traversal knows a key and nothing else, so an option that only a host row
     * can answer is refused rather than silently ignored.
     */
    public static function unavailableInTraversal(string $option): self
    {
        return new self('--'.$option.' has no meaning when discovering paths from a disk: a key on a bucket names '
            .'no host row. Import from a column to record one.');
    }
}
