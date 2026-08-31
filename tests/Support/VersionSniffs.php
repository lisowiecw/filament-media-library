<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Places where the source asks which Filament it is running against. The build
 * is Filament 5 first: an accommodation for Filament 4 lives behind the plugin
 * and field APIs both majors share, so a hit here is a version branch that
 * escaped (ADR 8).
 */
final class VersionSniffs
{
    private const array PATTERNS = [
        '/Filament::version\(/',
        '/InstalledVersions::/',
        '/version_compare\(/',
        '/class_exists\(\s*[\'"]?\\\\?Filament/',
    ];

    /** @return list<string> */
    public static function in(string $directory): array
    {
        $sniffs = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

            if ($lines === false) {
                continue;
            }

            foreach ($lines as $number => $line) {
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $sniffs[] = $file->getPathname().':'.($number + 1);

                        continue 2;
                    }
                }
            }
        }

        sort($sniffs);

        return $sniffs;
    }
}
