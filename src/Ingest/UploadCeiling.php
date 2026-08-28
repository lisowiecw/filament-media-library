<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

/**
 * The limits above the package's own: PHP's two upload directives and the max
 * rule Livewire applies to the temporary upload. A configured size above any
 * of them cannot actually be reached, and fails in the browser with nothing
 * written anywhere, which is why the operator is told at boot instead.
 */
final readonly class UploadCeiling
{
    /**
     * One line per ceiling the configured size is above, in kilobytes.
     *
     * @return list<string>
     */
    public static function warnings(int $maxUploadSize): array
    {
        $warnings = [];

        foreach (self::ceilings() as $name => $ceiling) {
            if ($ceiling !== null && $maxUploadSize > $ceiling) {
                $warnings[] = sprintf(
                    'media-library.max_upload_size is %d KB, above the %d KB %s allows. Uploads over %d KB will fail in the browser.',
                    $maxUploadSize,
                    $ceiling,
                    $name,
                    $ceiling,
                );
            }
        }

        return $warnings;
    }

    /**
     * @return array<string, int|null>
     */
    private static function ceilings(): array
    {
        return [
            'PHP upload_max_filesize' => self::shorthandToKilobytes(ini_get('upload_max_filesize')),
            'PHP post_max_size' => self::shorthandToKilobytes(ini_get('post_max_size')),
            "Livewire's temporary upload max rule" => self::livewireCeiling(),
        ];
    }

    /**
     * Livewire states its own limit as a validation rule, so the number is read
     * back out of the rule rather than from a setting of its own.
     */
    private static function livewireCeiling(): ?int
    {
        /** @var array<int, string>|string|null $rules */
        $rules = config('livewire.temporary_file_upload.rules');

        if ($rules === null) {
            return null;
        }

        $rules = is_string($rules) ? explode('|', $rules) : $rules;

        foreach ($rules as $rule) {
            if (preg_match('/^max:(\d+)$/', trim($rule), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * PHP's shorthand notation (`12M`, `1G`) in kilobytes. A zero or absent
     * directive means no ceiling rather than a ceiling of nothing.
     */
    private static function shorthandToKilobytes(string|false $value): ?int
    {
        if ($value === false || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $number = (int) $value;

        if ($number <= 0) {
            return null;
        }

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            default => max(1, (int) ceil($number / 1024)),
        };
    }
}
