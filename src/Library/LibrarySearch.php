<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Library;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Lisowiecw\MediaLibrary\Models\MediaAsset;

/**
 * The one search box over the library: a case-insensitive substring match
 * across everything a person might remember about a file, with whitespace
 * splitting the query into terms that all have to match.
 *
 * Terms narrow rather than widen, but each term may land in a different
 * column, so "hero rooftop" finds the hero whose alt text mentions a rooftop.
 *
 * The same object both filters the query and marks the matches in the name, so
 * the highlight can never drift from what the search actually matched.
 */
final readonly class LibrarySearch
{
    /**
     * Everything a person might remember a file by. The object key is in the
     * list because it is how an operator goes the other way, from a path in a
     * log back to the asset.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'display_name',
        'original_client_filename',
        'alt',
        'uploaded_by',
        'object_key',
    ];

    /**
     * @param  list<string>  $terms
     */
    private function __construct(public array $terms) {}

    public static function of(?string $query): self
    {
        $terms = preg_split('/\s+/', mb_strtolower(trim((string) $query)), flags: PREG_SPLIT_NO_EMPTY);

        return new self($terms === false ? [] : $terms);
    }

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * @param  Builder<MediaAsset>  $query
     */
    public function apply(Builder $query): void
    {
        foreach ($this->terms as $term) {
            $query->where(function (Builder $query) use ($term): void {
                foreach (self::COLUMNS as $column) {
                    $query->orWhereRaw("lower({$column}) like ? escape '\\'", ['%'.self::escape($term).'%']);
                }
            });
        }
    }

    /**
     * The name with its matches marked. Everything else is escaped here rather
     * than by the view, since the view has to print this unescaped.
     */
    public function highlight(?string $text): HtmlString
    {
        $text = (string) $text;

        $ranges = $this->matchRanges($text);

        if ($ranges === []) {
            return new HtmlString(e($text));
        }

        $html = '';
        $cursor = 0;

        foreach ($ranges as [$start, $end]) {
            $html .= e(mb_substr($text, $cursor, $start - $cursor))
                .'<mark>'.e(mb_substr($text, $start, $end - $start)).'</mark>';

            $cursor = $end;
        }

        return new HtmlString($html.e(mb_substr($text, $cursor)));
    }

    /**
     * Every span the terms cover, in order and with overlaps merged, so that
     * two terms matching the same run of characters produce one mark rather
     * than a nested pair.
     *
     * @return list<array{int, int}>
     */
    private function matchRanges(string $text): array
    {
        if ($this->terms === [] || $text === '') {
            return [];
        }

        $lower = mb_strtolower($text);
        $found = [];

        foreach ($this->terms as $term) {
            $offset = 0;

            while (($position = mb_strpos($lower, $term, $offset)) !== false) {
                $found[] = [$position, $position + mb_strlen($term)];
                $offset = $position + 1;
            }
        }

        usort($found, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($found as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * A wildcard someone typed is a character they are looking for, not a
     * pattern they are writing: the search box is not a query language.
     */
    private static function escape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
