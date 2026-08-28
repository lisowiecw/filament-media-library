<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

use Normalizer;

/**
 * The name algorithm: what a client filename becomes on the way into the
 * library. Names are metadata only; nothing here ever reaches an object key.
 */
final readonly class ReadableName
{
    /**
     * What a filesystem and a storage provider accept.
     */
    private const int FILENAME_BYTE_CAP = 255;

    /**
     * What a person can read. Characters rather than bytes, so a Japanese name
     * is not silently a third as long as an English one.
     */
    private const int DISPLAY_NAME_CHARACTER_CAP = 255;

    /**
     * C0 and C1 control characters, plus the bidi overrides and isolates, which
     * are a spoofing vector rather than a fact worth preserving.
     */
    private const string REMOVED_CHARACTERS = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function __construct(
        public string $originalClientFilename,
        public string $displayName,
        public ?string $extension,
    ) {}

    public static function from(string $clientFilename): self
    {
        $filename = self::scrub($clientFilename);

        return new self(
            originalClientFilename: $filename,
            displayName: self::displayNameFor($filename),
            extension: self::extensionFor($filename),
        );
    }

    /**
     * The form two names are compared in when deciding whether an upload
     * collides with something the library already holds. Case folding and
     * whitespace collapse only: `annual-report` is a different name.
     */
    public static function fold(string $name): string
    {
        return mb_convert_case(self::collapseWhitespace(self::normalize($name)), MB_CASE_FOLD);
    }

    /**
     * Basename, control and bidi stripping, NFC, trim, 255-byte cap.
     */
    private static function scrub(string $clientFilename): string
    {
        $basename = preg_split('#[/\\\\]#', $clientFilename);
        $filename = $basename === false ? $clientFilename : (string) end($basename);

        $filename = (string) preg_replace(self::REMOVED_CHARACTERS, '', $filename);

        return self::capBytes(trim(self::normalize($filename)));
    }

    /**
     * Cap at 255 bytes without splitting a character, keeping the extension so
     * a truncated name still says what kind of file it was.
     */
    private static function capBytes(string $filename): string
    {
        if (strlen($filename) <= self::FILENAME_BYTE_CAP) {
            return $filename;
        }

        $extension = self::extensionFor($filename);
        $suffix = $extension === null ? '' : '.'.$extension;
        $stem = $suffix === '' ? $filename : mb_substr($filename, 0, -mb_strlen($suffix));

        $budget = self::FILENAME_BYTE_CAP - strlen($suffix);

        return mb_strcut($stem, 0, max($budget, 0)).$suffix;
    }

    /**
     * The filename with its final extension removed. A leading dot is not an
     * extension separator, and where stripping empties the name the whole
     * filename stands in, since the user did type something.
     */
    private static function displayNameFor(string $filename): string
    {
        $extension = self::extensionFor($filename);

        $stem = $extension === null
            ? $filename
            : mb_substr($filename, 0, -(mb_strlen($extension) + 1));

        $stem = trim(self::collapseWhitespace($stem));

        if ($stem === '') {
            $stem = trim(self::collapseWhitespace($filename));
        }

        return mb_substr($stem, 0, self::DISPLAY_NAME_CHARACTER_CAP);
    }

    /**
     * The extension follows the client name, lowercased, even where a content
     * sniff contradicts it. See ADR 0006.
     */
    private static function extensionFor(string $filename): ?string
    {
        $dot = mb_strrpos($filename, '.');

        if ($dot === false || $dot === 0 || $dot === mb_strlen($filename) - 1) {
            return null;
        }

        return mb_strtolower(mb_substr($filename, $dot + 1));
    }

    private static function normalize(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalized === false ? $value : $normalized;
    }

    private static function collapseWhitespace(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', $value);
    }
}
