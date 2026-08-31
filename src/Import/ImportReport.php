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

    /** @var list<array{path: string, reason: string, detail: string|null}> */
    public array $omissions = [];

    public function __construct(public readonly ImportRequest $request) {}

    public function omit(string $path, ImportOmission $reason, ?string $detail = null): void
    {
        $this->omissions[] = ['path' => $path, 'reason' => $reason->value, 'detail' => $detail];
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
            'mode' => $this->request->copy ? 'copy' : 'register',
            'dry_run' => $this->request->dryRun,
            'ran_at' => now()->toIso8601String(),
            'counts' => [
                'examined' => $this->examined,
                'registered' => $this->registered,
                'copied' => $this->copied,
                'already-present' => $this->alreadyPresent,
                'omitted' => count($this->omissions),
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
