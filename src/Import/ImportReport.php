<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

/**
 * The record an import run leaves of everything it declined to adopt and why.
 *
 * It names omissions rather than successes, because the library itself already
 * shows what was adopted, and a report short enough to read is one somebody
 * actually reads. The counts are the only thing said about the successes.
 */
final class ImportReport
{
    public int $examined = 0;

    public int $registered = 0;

    public int $copied = 0;

    public int $alreadyPresent = 0;

    public int $attached = 0;

    /**
     * Omissions and skips share one list, because both are things the run
     * declined and a reader wants them in the order they happened. The element
     * index is what tells them apart: null is a whole row the run passed over,
     * and a number is one element of a multi-value column, which says nothing
     * about the rest of that row.
     *
     * @var list<array{path: string, reason: string, detail: string|null, element: int|null}>
     */
    public array $omissions = [];

    public function __construct(public readonly ImportRequest $request) {}

    public function omit(string $path, ImportOmission $reason, ?string $detail = null, ?int $element = null): void
    {
        $this->omissions[] = ['path' => $path, 'reason' => $reason->value, 'detail' => $detail, 'element' => $element];
    }

    /**
     * How many elements of a multi-value column were passed over, as against
     * how many rows were. A row that lost one element of four is not a row the
     * run declined, and counting the two together would read as though it were.
     */
    public function skippedElements(): int
    {
        return count(array_filter($this->omissions, fn (array $omission): bool => $omission['element'] !== null));
    }

    public function omittedRows(): int
    {
        return count($this->omissions) - $this->skippedElements();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->request->importSource(),
            'disk' => $this->request->disk,
            'field' => $this->request->field,
            'cardinality' => $this->request->discovery->cardinality()->value,
            'mode' => $this->request->copy ? 'copy' : 'register',
            'dry_run' => $this->request->dryRun,
            'ran_at' => now()->toIso8601String(),
            'counts' => [
                'examined' => $this->examined,
                'registered' => $this->registered,
                'copied' => $this->copied,
                'already-present' => $this->alreadyPresent,
                'attached' => $this->attached,
                'omitted-rows' => $this->omittedRows(),
                'skipped-elements' => $this->skippedElements(),
            ],
            'omissions' => $this->omissions,
        ];
    }

    /**
     * Where a report goes when the operator names no path: one file per run, so
     * two runs can be diffed rather than one overwriting the other.
     */
    public static function defaultPath(): string
    {
        return storage_path('logs/media-import-'.now()->format('Y-m-d_His').'.json');
    }
}
