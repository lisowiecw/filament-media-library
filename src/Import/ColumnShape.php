<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Lisowiecw\MediaLibrary\Exceptions\ImportRefused;

/**
 * What one host row's legacy column holds, read against the cardinality the
 * run declared.
 *
 * The split here is between what is messy and what is misread. A duplicate, an
 * empty element or a path whose object has gone is mess: the row still says
 * something the run can honour, so those elements are skipped and reported and
 * the run carries on. A shape the tool cannot honestly handle, a nested object,
 * a URL, or a column that is the other cardinality entirely, is a misreading of
 * the whole column rather than one bad row, so it ends the run: every other row
 * in that column is about to be read the same wrong way.
 */
final readonly class ColumnShape
{
    /**
     * @param  array<int, string>  $elements  the usable keys, at the index they sat at in the column
     * @param  list<array{index: int, path: string, reason: ImportOmission}>  $skips
     */
    private function __construct(
        public array $elements,
        public array $skips,
    ) {}

    /**
     * A column that holds nothing at all: no keys to adopt, and nothing to say
     * about individual elements either.
     */
    public function isEmpty(): bool
    {
        return $this->elements === [] && $this->skips === [];
    }

    /**
     * @param  string  $rowLabel  what a refusal names the offending row by
     */
    public static function read(mixed $value, Cardinality $cardinality, string $rowLabel): self
    {
        $decoded = self::decode($value, $rowLabel);

        if ($decoded === null) {
            return new self([], []);
        }

        // A mismatch in either direction is the same refusal: the column holds
        // a list exactly when the run declared it holds many.
        if (is_array($decoded) !== ($cardinality === Cardinality::Many)) {
            throw ImportRefused::shapeMismatch($rowLabel, $cardinality);
        }

        return is_array($decoded)
            ? self::list($decoded, $rowLabel)
            : new self([0 => self::key($decoded, $rowLabel)], []);
    }

    /**
     * The column's value as either one string or a list, or null where it holds
     * nothing. A JSON array is unwrapped here, since a multi-value legacy column
     * is text far more often than it is a cast attribute.
     *
     * @return string|list<mixed>|null
     */
    private static function decode(mixed $value, string $rowLabel): string|array|null
    {
        if (is_array($value)) {
            return array_is_list($value) ? $value : throw ImportRefused::nestedShape($rowLabel);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        // Only text that opens as an array is even offered to the decoder: a
        // legacy path is a string, and `json_decode` would happily turn one
        // that reads as a number into one.
        if (! str_starts_with($value, '[')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        if (is_array($decoded)) {
            throw ImportRefused::nestedShape($rowLabel);
        }

        // Text that opens as a list and does not parse as one is malformed
        // rather than a path, and saying so beats a cardinality complaint that
        // sends the operator to re-declare a column that was declared right.
        throw ImportRefused::malformedList($rowLabel);
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function list(array $values, string $rowLabel): self
    {
        $elements = [];
        $skips = [];
        $seen = [];

        foreach ($values as $index => $value) {
            if (is_array($value)) {
                throw ImportRefused::nestedShape($rowLabel);
            }

            if (! is_string($value)) {
                $skips[] = ['index' => $index, 'path' => $rowLabel, 'reason' => ImportOmission::NonTextElement];

                continue;
            }

            if (trim($value) === '') {
                $skips[] = ['index' => $index, 'path' => $rowLabel, 'reason' => ImportOmission::EmptyElement];

                continue;
            }

            $key = self::key($value, $rowLabel);

            if (in_array($key, $seen, true)) {
                $skips[] = ['index' => $index, 'path' => $key, 'reason' => ImportOmission::DuplicateElement];

                continue;
            }

            $seen[] = $key;
            $elements[$index] = $key;
        }

        return new self($elements, $skips);
    }

    /**
     * One value as an object key. A URL is refused rather than stripped down to
     * a path: the host it names may not be this disk at all, and a run that
     * guessed would adopt somebody else's bytes under a key that looks right.
     */
    private static function key(string $value, string $rowLabel): string
    {
        $value = trim($value);

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value) === 1 || str_starts_with($value, '//')) {
            throw ImportRefused::urlValue($rowLabel, $value);
        }

        return ltrim($value, '/');
    }
}
