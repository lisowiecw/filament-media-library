<?php

declare(strict_types=1);

namespace Lisowiecw\MediaLibrary\Ingest;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lisowiecw\MediaLibrary\Enums\MediaSource;
use Lisowiecw\MediaLibrary\Enums\MimeSource;
use Lisowiecw\MediaLibrary\Exceptions\IngestRefused;
use Lisowiecw\MediaLibrary\Models\MediaAsset;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

/**
 * The one entry point that turns an uploaded file into a stored object and a
 * Media Asset row. Both the picker's upload path and the management page's
 * upload action call it; the importer deliberately does not, because it adopts
 * existing objects rather than ingesting new ones.
 */
class IngestService
{
    /**
     * A public original is cached at the edge and in the browser for a year.
     * Keys are opaque and never rewritten, so the bytes behind one never change.
     */
    public const string CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function ingest(UploadedFile $file, Placement $placement, ?IngestRules $rules = null): MediaAsset
    {
        $rules ??= IngestRules::resolve();

        $name = ReadableName::from($file->getClientOriginalName());
        $path = $this->pathOf($file);
        $mimeType = $this->sniff($path);

        $this->refuseUnlessAllowed($file, $name, $mimeType, $placement, $rules);

        $asset = new MediaAsset([
            'ulid' => $ulid = (string) Str::ulid(),
            'display_name' => $name->displayName,
            'original_client_filename' => $name->originalClientFilename,
            'extension' => $name->extension,
            'mime_type' => $mimeType,
            'mime_source' => $mimeType === null ? MimeSource::Unknown : MimeSource::Sniffed,
            'disk' => $placement->disk,
            'object_key' => $placement->key($ulid.$this->keySuffix($mimeType)),
            'visibility' => $placement->visibility,
            'source' => MediaSource::Upload,
            'uploaded_by' => $this->uploader(),
        ]);

        $asset->size = $this->write($path, $asset, $placement);
        $asset->nameCollided = $this->collides($name->displayName);

        $asset->save();

        return $asset;
    }

    /**
     * The floor, in order: size, denylist, accepted types, family mismatch,
     * then the public-placement refusal of active content. Every check runs
     * before a key exists or a byte is written, so a refusal stores nothing.
     */
    private function refuseUnlessAllowed(
        UploadedFile $file,
        ReadableName $name,
        ?string $mimeType,
        Placement $placement,
        IngestRules $rules,
    ): void {
        $readable = $name->originalClientFilename;
        $size = (int) $file->getSize();

        if ($rules->exceedsMaxUploadSize($size)) {
            throw IngestRefused::tooLarge($readable, $size, $rules->maxUploadSize);
        }

        if ($rules->blocks($name->extension, $mimeType)) {
            throw IngestRefused::blockedType($readable, $name->extension, $mimeType);
        }

        if (! $rules->accepts($name->extension, $mimeType)) {
            throw IngestRefused::unacceptedType($readable, $name->extension, $mimeType);
        }

        // A different top-level family is deception rather than disagreement,
        // and is refused even where both types are individually accepted.
        if ($mimeType !== null && TypeFamily::mismatched($name->extension, $mimeType)) {
            throw IngestRefused::familyMismatch($readable, TypeFamily::typesFor($name->extension)[0], $mimeType);
        }

        // Public content never passes through the Delivery route, which is
        // where the download-only rule for active content lives. There is no
        // silent downgrade to private: the upload is refused.
        if ($placement->visibility === 'public' && ActiveContent::matches($mimeType)) {
            throw IngestRefused::activeContentOnPublicPlacement($readable, $mimeType);
        }
    }

    /**
     * The response metadata written onto the storage object at upload. Written
     * whatever the Placement, and inert on a private asset, since the Delivery
     * route sets its own. They bind here because changing one later would mean
     * rewriting the whole object.
     *
     * Active content carries a saving Disposition, so the rule that it is
     * never rendered in place holds outside the Delivery route too.
     *
     * @return array<string, string>
     */
    public function storedHeaders(?string $mimeType): array
    {
        return array_filter([
            'ContentType' => $mimeType,
            'ContentDisposition' => ActiveContent::matches($mimeType) ? 'attachment' : null,
            'CacheControl' => self::CACHE_CONTROL,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * What the write itself is handed: the placement's visibility, which is a
     * driver option rather than a stored header, plus the stored headers.
     *
     * @return array<string, string>
     */
    private function writeOptions(Placement $placement, ?string $mimeType): array
    {
        return ['visibility' => $placement->visibility] + $this->storedHeaders($mimeType);
    }

    /**
     * Write the bytes and report their size as stored, rather than as claimed.
     */
    private function write(string $path, MediaAsset $asset, Placement $placement): int
    {
        $disk = Storage::disk($placement->disk);

        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            $disk->put($asset->object_key, $stream, $this->writeOptions($placement, $asset->mime_type));
        } finally {
            fclose($stream);
        }

        return (int) $disk->size($asset->object_key);
    }

    private function pathOf(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('The uploaded file is no longer on disk.');
        }

        return $path;
    }

    /**
     * The browser's claim is never what gets stored. A sniff that fails yields
     * no type at all rather than a guess, so `mime_source` keeps telling the
     * truth about how far the type can be trusted.
     */
    private function sniff(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        try {
            $mimeType = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return $mimeType === false ? null : $mimeType;
    }

    /**
     * The key's extension follows the sniffed bytes, so the provider sees the
     * truth, while the row's own extension follows the client name. Where the
     * bytes name no extension the key simply has none, because the client name
     * must never reach a storage path.
     */
    private function keySuffix(?string $mimeType): string
    {
        if ($mimeType === null) {
            return '';
        }

        $extension = MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null;

        return $extension === null ? '' : '.'.$extension;
    }

    /**
     * A collision is informational only: it never blocks and never overwrites.
     * The comparison is library-wide and unfiltered, trashed assets included,
     * because a collision the user cannot see is worse than one they can.
     */
    private function collides(string $displayName): bool
    {
        $folded = ReadableName::fold($displayName);

        // Folding is Unicode-aware, so the comparison happens in PHP rather
        // than in a database `lower()`. That costs a scan of one column per
        // upload; a stored folded column is the escape hatch if it ever hurts.
        return MediaAsset::withTrashed()
            ->select('display_name')
            ->lazy()
            ->contains(fn (MediaAsset $asset): bool => ReadableName::fold($asset->display_name) === $folded);
    }

    private function uploader(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
