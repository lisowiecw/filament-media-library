<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Places where a migration puts its column next to a named one. The package's
 * migrations carry no timestamp prefixes, so a placement that names a column
 * another migration creates is an ordering dependency the filenames cannot
 * express, and sqlite discards placement entirely, so a broken order runs
 * green here and fails on MySQL alone (ADR 20).
 *
 * The match is lexical: it reads a placement in a comment as a placement, and
 * it would miss one written with a space before its parenthesis. That is the
 * warning the invariant wants rather than a proof of it.
 */
final class ColumnPlacements
{
    /** @return list<string> */
    public static function in(string $directory): array
    {
        $placements = [];

        foreach (self::migrationsIn($directory) as $migration) {
            foreach (file($migration, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                if (str_contains($line, '->after(')) {
                    $placements[] = basename($migration).':'.($number + 1);
                }
            }
        }

        return $placements;
    }

    /**
     * The files the check reads, so a caller can say it read any: a directory
     * that moved would otherwise report no placements and pass.
     *
     * @return list<string>
     */
    public static function migrationsIn(string $directory): array
    {
        /** @var iterable<SplFileInfo> $files */
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        $migrations = [];

        foreach ($files as $file) {
            $migrations[] = $file->getPathname();
        }

        sort($migrations);

        return $migrations;
    }
}
