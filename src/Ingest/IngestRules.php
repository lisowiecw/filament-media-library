<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

/**
 * The floor an upload has to clear: a size, a denylist and, where the field
 * names one, a list of accepted types. A field may move the size in either
 * direction and may narrow the accepted types, but its blocked types are added
 * to the package's rather than replacing them, so the denylist can only grow.
 *
 * The rules bind at ingest alone. Nothing here ever rejects, hides or deletes
 * an asset already in the library.
 */
final readonly class IngestRules
{
    /**
     * The package default, in kilobytes, for a configuration that names none.
     */
    public const int DEFAULT_MAX_UPLOAD_SIZE = 12 * 1024;

    /**
     * @param  list<string>  $blockedTypes  extensions and mime types alike
     * @param  list<string>|null  $acceptedTypes  null means every type that is not blocked
     */
    public function __construct(
        public int $maxUploadSize,
        public array $blockedTypes = [],
        public ?array $acceptedTypes = null,
    ) {}

    /**
     * @param  list<string>|null  $acceptedTypes
     * @param  list<string>|null  $blockedTypes
     */
    public static function resolve(
        ?int $maxUploadSize = null,
        ?array $acceptedTypes = null,
        ?array $blockedTypes = null,
    ): self {
        /** @var int $configuredSize */
        $configuredSize = config('media-library.max_upload_size', self::DEFAULT_MAX_UPLOAD_SIZE);

        /** @var list<string> $configuredBlocked */
        $configuredBlocked = config('media-library.blocked_types', []);

        return new self(
            maxUploadSize: $maxUploadSize ?? $configuredSize,
            blockedTypes: array_values(array_unique([
                ...self::normalizeAll($configuredBlocked),
                ...self::normalizeAll($blockedTypes ?? []),
            ])),
            acceptedTypes: $acceptedTypes === null ? null : self::normalizeAll($acceptedTypes),
        );
    }

    public function exceedsMaxUploadSize(int $sizeInBytes): bool
    {
        return (int) ceil($sizeInBytes / 1024) > $this->maxUploadSize;
    }

    /**
     * Matched on both the extension and the resolved mime, so neither alone
     * can be used to slip past the list.
     */
    public function blocks(?string $extension, ?string $mimeType): bool
    {
        return in_array($extension === null ? null : self::normalize($extension), $this->blockedTypes, strict: true)
            || in_array($mimeType === null ? null : self::normalize($mimeType), $this->blockedTypes, strict: true);
    }

    /**
     * The denylist split the way a stored row is: extensions in one column,
     * mime types in the other. An offer query reads it from here rather than
     * classifying the tokens again for itself.
     *
     * @return list<string>
     */
    public function blockedExtensions(): array
    {
        return array_values(array_filter($this->blockedTypes, fn (string $type): bool => ! str_contains($type, '/')));
    }

    /**
     * @return list<string>
     */
    public function blockedMimeTypes(): array
    {
        return array_values(array_filter($this->blockedTypes, fn (string $type): bool => str_contains($type, '/')));
    }

    /**
     * The gate runs against the sniffed truth, but a `.csv` whose bytes sniff
     * as `text/plain` still passes, because the extension's own type is read
     * alongside the sniff rather than being discarded on disagreement.
     */
    public function accepts(?string $extension, ?string $mimeType): bool
    {
        if ($this->acceptedTypes === null) {
            return true;
        }

        foreach ($this->candidates($extension, $mimeType) as $candidate) {
            foreach ($this->acceptedTypes as $accepted) {
                if ($this->matches($accepted, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Everything a list may legitimately name this file by: its extension, its
     * sniffed type, and the types its extension is known by.
     *
     * @return list<string>
     */
    private function candidates(?string $extension, ?string $mimeType): array
    {
        return array_values(array_unique(array_filter([
            $extension === null ? null : self::normalize($extension),
            $mimeType === null ? null : self::normalize($mimeType),
            ...TypeFamily::typesFor($extension),
        ], fn (?string $candidate): bool => $candidate !== null)));
    }

    /**
     * Exact match, or a family wildcard such as `image/*`.
     */
    private function matches(string $accepted, string $candidate): bool
    {
        if ($accepted === $candidate) {
            return true;
        }

        return str_ends_with($accepted, '/*')
            && str_contains($candidate, '/')
            && TypeFamily::of($candidate) === substr($accepted, 0, -2);
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private static function normalizeAll(array $types): array
    {
        return array_map(self::normalize(...), $types);
    }

    /**
     * A list may name an extension with or without its dot; both mean the same
     * thing, so both arrive here as the bare, lowercased extension.
     */
    private static function normalize(string $type): string
    {
        return ltrim(strtolower(trim($type)), '.');
    }
}
