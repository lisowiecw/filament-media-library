<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The CI matrix read as prose: which versions are actually tested, whether a
 * failing leg can reach a release, and the README table that says so.
 */
final readonly class CompatibilityMatrix
{
    public const string START = '<!-- compatibility:start -->';

    public const string END = '<!-- compatibility:end -->';

    /**
     * @param  list<string>  $php
     * @param  list<string>  $laravel
     * @param  list<string>  $filament
     */
    private function __construct(
        private array $php,
        private array $laravel,
        private array $filament,
        private bool $permitsFailure,
        private bool $isGated,
    ) {}

    public static function fromWorkflow(string $path): self
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the workflow at {$path}.");
        }

        return self::fromYaml($contents);
    }

    public static function fromYaml(string $yaml): self
    {
        /** @var array{jobs: array<string, array<string, mixed>>} $workflow */
        $workflow = Yaml::parse($yaml);
        $jobs = $workflow['jobs'];

        /** @var array{matrix: array{php: list<int|float|string>, laravel: list<string>, filament: list<string>}} $strategy */
        $strategy = $jobs['tests']['strategy'];

        return new self(
            self::series($strategy['matrix']['php']),
            self::series($strategy['matrix']['laravel']),
            self::series($strategy['matrix']['filament']),
            self::tolerance($jobs['tests']),
            self::gate($jobs),
        );
    }

    /** @return list<string> */
    public function php(): array
    {
        return $this->php;
    }

    /** @return list<string> */
    public function laravel(): array
    {
        return $this->laravel;
    }

    /** @return list<string> */
    public function filament(): array
    {
        return $this->filament;
    }

    /**
     * Whether the matrix job, or any step in it, is allowed to fail without
     * failing the run. A matrix that tolerates a red leg is a claim again.
     */
    public function permitsFailure(): bool
    {
        return $this->permitsFailure;
    }

    /**
     * Whether a job with one stable name stands behind the matrix and fails
     * unless every leg passed. Each leg is named after the versions it ran, so
     * without that job there is nothing branch protection can require.
     */
    public function isGated(): bool
    {
        return $this->isGated;
    }

    public function table(string $package): string
    {
        return implode("\n", [
            '| Package | PHP | Laravel | Filament |',
            '| ------- | --- | ------- | -------- |',
            sprintf(
                '| %s | %s | %s | %s |',
                $package,
                implode(', ', $this->php),
                implode(', ', $this->laravel),
                $this->filamentColumn(),
            ),
        ]);
    }

    /**
     * The table currently sitting between the markers in a Markdown document,
     * or null when the document carries no generated table at all.
     */
    public static function tableIn(string $markdown): ?string
    {
        $start = strpos($markdown, self::START);
        $end = strpos($markdown, self::END);

        if ($start === false || $end === false) {
            return null;
        }

        $start += strlen(self::START);

        return trim(substr($markdown, $start, $end - $start));
    }

    public static function write(string $markdown, string $table): string
    {
        return preg_replace(
            '/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s',
            self::START."\n\n".$table."\n\n".self::END,
            $markdown,
        ) ?? $markdown;
    }

    /**
     * The newest tested Filament major is what the package guarantees; anything
     * older rides the same Composer line on a best-effort basis (ADR 8).
     */
    private function filamentColumn(): string
    {
        $majors = $this->filament;

        usort($majors, static fn (string $left, string $right): int => version_compare($right, $left));

        $column = array_shift($majors).' (guaranteed)';

        foreach ($majors as $major) {
            $column .= ', '.$major.' (best effort)';
        }

        return $column;
    }

    /** @param array<string, mixed> $job */
    private static function tolerance(array $job): bool
    {
        if (($job['continue-on-error'] ?? false) !== false) {
            return true;
        }

        /** @var list<array<string, mixed>> $steps */
        $steps = $job['steps'] ?? [];

        foreach ($steps as $step) {
            if (($step['continue-on-error'] ?? false) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array<string, mixed>> $jobs */
    private static function gate(array $jobs): bool
    {
        foreach ($jobs as $name => $job) {
            if ($name === 'tests') {
                continue;
            }

            $needs = (array) ($job['needs'] ?? []);

            if (in_array('tests', $needs, true) && ($job['if'] ?? null) === 'always()') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int|float|string>  $versions
     * @return list<string>
     */
    private static function series(array $versions): array
    {
        return array_values(array_map(
            static fn (int|float|string $version): string => str_replace('.*', '.x', (string) $version),
            $versions,
        ));
    }
}
