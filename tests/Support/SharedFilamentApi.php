<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Every Filament symbol the source imports. Filament 4 support is limited to
 * the plugin and field APIs both majors share (ADR 8), so running this against
 * whichever major is installed is what turns that limit into something the
 * matrix can fail on: a symbol only Filament 5 declares goes missing on the
 * Filament 4 leg.
 */
final class SharedFilamentApi
{
    /** @return list<string> */
    public static function importsIn(string $directory): array
    {
        $imports = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            preg_match_all('/^use\s+(Filament\\\\[^\s;]+)\s*;/m', $contents, $matches);

            foreach ($matches[1] as $import) {
                $imports[$import] = true;
            }
        }

        $imports = array_keys($imports);
        sort($imports);

        return $imports;
    }

    /**
     * The imports the installed Filament does not declare.
     *
     * @param  list<string>  $imports
     * @return list<string>
     */
    public static function missing(array $imports): array
    {
        return array_values(array_filter(
            $imports,
            static fn (string $import): bool => ! class_exists($import)
                && ! interface_exists($import)
                && ! trait_exists($import)
                && ! enum_exists($import),
        ));
    }
}
