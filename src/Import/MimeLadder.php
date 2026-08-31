<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Import;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\UnableToRetrieveMetadata;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * How far an adopted object's type can be trusted, resolved by asking cheaper
 * questions before expensive ones and recording which one answered.
 *
 * The rungs, in order: the type stored on the object, a sniff of its bytes
 * where the operator has paid for one, the filename extension, then nothing.
 * The type and the rung are always produced together, so a row can never claim
 * a rung it did not come from.
 */
final readonly class MimeLadder
{
    public function __construct(
        public ?string $mimeType,
        public MimeSource $source,
    ) {}

    public static function resolve(
        FilesystemAdapter $disk,
        string $key,
        ?string $extension,
        bool $sniff = false,
    ): self {
        $stored = self::stored($disk, $key);

        if ($stored !== null) {
            return new self($stored, MimeSource::Header);
        }

        // One full read of the object, which is why it is never implicit.
        if ($sniff) {
            $sniffed = self::sniffed($disk, $key);

            if ($sniffed !== null) {
                return new self($sniffed, MimeSource::Sniffed);
            }
        }

        $named = $extension === null
            ? null
            : (MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null);

        return $named === null
            ? new self(null, MimeSource::Unknown)
            : new self($named, MimeSource::Extension);
    }

    /**
     * What the object itself says it is. On a remote disk this is the stored
     * `Content-Type` header, which is a claim rather than a measurement, and on
     * a disk that never had one it is nothing at all.
     */
    private static function stored(FilesystemAdapter $disk, string $key): ?string
    {
        try {
            $mimeType = $disk->mimeType($key);
        } catch (UnableToRetrieveMetadata) {
            return null;
        }

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
    }

    /**
     * The bytes themselves, read through a temporary file because that is what
     * the sniffer takes. A read that fails is not a type: the ladder simply
     * carries on to the next rung.
     */
    private static function sniffed(FilesystemAdapter $disk, string $key): ?string
    {
        try {
            $stream = $disk->readStream($key);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        // `tmpfile()` rather than a name of our own: the handle owns the file,
        // so it is unlinked when it closes and no other process can reach it
        // between the two.
        $temporary = tmpfile();

        if ($temporary === false) {
            fclose($stream);

            return null;
        }

        try {
            stream_copy_to_stream($stream, $temporary);
            fflush($temporary);

            $path = stream_get_meta_data($temporary)['uri'] ?? null;

            return is_string($path) ? MimeTypes::getDefault()->guessMimeType($path) : null;
        } catch (Throwable) {
            return null;
        } finally {
            fclose($stream);
            fclose($temporary);
        }
    }
}
